<?php

namespace App\Services;

use App\Enums\BudgetExpenseType;
use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Which block of a cashflow period each posting belongs to, and which
 * transactions the list shows for a block. A posting is an element of
 * `effectiveTransactions()`: an unsplit transaction, or one split line
 * carrying its parent's labels.
 *
 * - `saved` / `invested`: a posting in a Savings / Investment category that is
 *   not a set-aside account leg, exactly what the summary adds up as savings
 *   and investments.
 * - `fixed`, `variable`, `general`: an expense-side posting, by the expense
 *   type of the budgets tracking it, read the way the fork's budgets read a
 *   transaction ({@see BudgetTransactionService}): a label a budget tracks on
 *   the transaction claims all of it, otherwise each posting goes by its own
 *   category. Fixed wins an overlap with variable; what no budget tracks is
 *   general.
 *
 * The per-posting classifier ({@see self::blockOf()}) adds up the figures, and
 * its SQL twin ({@see self::apply()}) filters the list; both share the tracked
 * ids. The SQL side works at transaction level: a transaction is listed under
 * a block when it, or any of its split lines, falls in it. So every posting
 * the figure counts belongs to a listed transaction, and a split transaction
 * is listed under each block one of its lines falls in, while only those lines
 * count towards each figure.
 *
 * @phpstan-type TrackedIds array{categories: array<int, string>, labels: array<int, string>}
 */
class CashflowBlockQuery
{
    /**
     * Tracked ids already resolved, keyed by user and period.
     *
     * @var array<string, array<string, TrackedIds>>
     */
    private array $trackedCache = [];

    public function __construct(private CategoryTree $tree) {}

    /**
     * The block $posting falls in, or null when it is in none (income, a
     * transfer, a set-aside account leg). Needs the posting's `category`,
     * `account` and `labels` loaded; a split line shares its parent's labels.
     *
     * @param  array<string, TrackedIds>  $trackedByType  as {@see self::trackedIds()} returns it
     */
    public function blockOf(Transaction $posting, array $trackedByType): ?CashflowBlock
    {
        if ($posting->isExpenseSide()) {
            return match ($this->expenseTypeOf($posting, $trackedByType)) {
                BudgetExpenseType::Fixed => CashflowBlock::Fixed,
                BudgetExpenseType::Variable => CashflowBlock::Variable,
                null => CashflowBlock::General,
            };
        }

        if ($posting->isSetAsideAccountLeg()) {
            return null;
        }

        return match ($posting->categoryType()) {
            CategoryType::Savings => CashflowBlock::Saved,
            CategoryType::Investment => CashflowBlock::Invested,
            default => null,
        };
    }

    /**
     * Narrow $query to the transactions with a posting in $block over $period,
     * among the rows counting towards totals: the SQL twin of
     * {@see self::blockOf()}.
     *
     * The condition runs as an id subquery, so it composes with any other
     * filter, pagination or bulk update without joining into $query.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function apply(Builder $query, CashflowBlock $block, string $userId, PeriodComparator $period): Builder
    {
        $rows = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$period->from, $period->to])
            ->countingTowardsTotals()
            ->select('transactions.id');

        $tracked = $this->trackedIds($userId, $period);
        $fixed = $tracked[BudgetExpenseType::Fixed->value];
        $variable = $tracked[BudgetExpenseType::Variable->value];

        match ($block) {
            CashflowBlock::Saved => $rows->setAsideInto(CategoryType::Savings),
            CashflowBlock::Invested => $rows->setAsideInto(CategoryType::Investment),
            CashflowBlock::Fixed => $this->whereHasExpensePosting($rows, $fixed, []),
            CashflowBlock::Variable => $this->whereHasExpensePosting($rows, $variable, [$fixed]),
            CashflowBlock::General => $this->whereHasExpensePosting($rows, null, [$fixed, $variable]),
        };

        // Wrapped in a derived table, which MySQL materializes, so a bulk
        // update can filter on the same table it writes to.
        return $query->whereIn('transactions.id', DB::query()->fromSub($rows, 'cashflow_block_rows')->select('id'));
    }

    /**
     * Narrow $rows to the transactions $tracked reaches: booked to a tracked
     * category, carrying a tracked label, or split with a line in a tracked
     * category. Every transaction with a posting {@see self::isTrackedBy()}
     * accepts is among them, so it is a cheap superset to load and classify.
     *
     * @param  Builder<Transaction>  $rows
     * @param  TrackedIds  $tracked
     * @return Builder<Transaction>
     */
    public function whereTrackedBy(Builder $rows, array $tracked): Builder
    {
        return $rows->where(fn (Builder $query) => $query
            ->whereIn('transactions.category_id', $tracked['categories'])
            ->orWhereHas('splits', fn (Builder $splitQuery) => $splitQuery->whereIn('transaction_splits.category_id', $tracked['categories']))
            ->orWhereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $tracked['labels'])));
    }

    /**
     * Categories (children included) and labels tracked by the user's
     * non-catch-all budgets with a period in the range, by expense type.
     * Catch-all budgets are left out: they track whatever nothing else does,
     * which would leave no general expenses at all.
     *
     * @return array<string, TrackedIds>
     */
    public function trackedIds(string $userId, PeriodComparator $period): array
    {
        $key = $userId.'|'.$period->from->toDateString().'|'.$period->to->toDateString();

        // Archived budgets included: they still tracked the periods they had,
        // and archiving one must not turn its past spending into general costs.
        return $this->trackedCache[$key] ??= Budget::query()
            ->where('user_id', $userId)
            ->where('is_catch_all', false)
            ->whereHas('periods', fn (Builder $query) => $query->whereIn(
                'budget_periods.id',
                BudgetPeriod::query()->overlapping($period->from, $period->to)->select('id'),
            ))
            ->with('categories:id', 'labels:id')
            ->get()
            ->groupBy(fn (Budget $budget): string => $budget->expense_type->value)
            ->pipe(fn (Collection $budgets): array => collect(BudgetExpenseType::cases())
                ->mapWithKeys(fn (BudgetExpenseType $type): array => [$type->value => $this->trackedBy($userId, $budgets->get($type->value, collect()))])
                ->all());
    }

    /**
     * The expense type of the budgets tracking $posting, or null when none
     * does. Fixed wins an overlap, so a posting is only ever counted once.
     *
     * @param  array<string, TrackedIds>  $trackedByType
     */
    private function expenseTypeOf(Transaction $posting, array $trackedByType): ?BudgetExpenseType
    {
        foreach ([BudgetExpenseType::Fixed, BudgetExpenseType::Variable] as $type) {
            if ($this->isTrackedBy($posting, $trackedByType[$type->value])) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Rows with an expense-side posting that $trackedBy tracks (any
     * expense-side posting when null) and none of $outrankedBy does: the SQL
     * twin of {@see self::blockOf()} for the expense blocks, posting by
     * posting. A label sits on the row and reaches every posting, so it is
     * checked on the row; a category is checked on each posting, the row's own
     * or a split line's. A split row with lines in several blocks matches
     * each of them.
     *
     * @param  Builder<Transaction>  $rows
     * @param  TrackedIds|null  $trackedBy
     * @param  list<TrackedIds>  $outrankedBy  what the types that win an overlap track
     * @return Builder<Transaction>
     */
    private function whereHasExpensePosting(Builder $rows, ?array $trackedBy, array $outrankedBy): Builder
    {
        foreach ($outrankedBy as $tracked) {
            $rows->whereDoesntHave('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $tracked['labels']));
        }

        // Spelled out rather than as a negated whereIn(): an uncategorized
        // row's `category_id IN (...)` is NULL, and negating NULL would drop it
        // instead of keeping it.
        $outranked = function (Builder $posting, string $categoryColumn) use ($outrankedBy): void {
            foreach ($outrankedBy as $tracked) {
                $posting->where(fn (Builder $category) => $category
                    ->whereNull($categoryColumn)
                    ->orWhereNotIn($categoryColumn, $tracked['categories']));
            }
        };

        if ($trackedBy === null) {
            return $rows->expenseSide($outranked);
        }

        return $rows->where(fn (Builder $reached) => $reached
            ->where(fn (Builder $byLabel) => $byLabel
                ->whereHas('labels', fn (Builder $labelQuery) => $labelQuery->whereIn('labels.id', $trackedBy['labels']))
                ->expenseSide($outranked))
            ->orWhere(fn (Builder $byCategory) => $byCategory
                ->expenseSide(function (Builder $posting, string $categoryColumn) use ($outranked, $trackedBy): void {
                    $outranked($posting, $categoryColumn);
                    $posting->whereIn($categoryColumn, $trackedBy['categories']);
                })));
    }

    /**
     * Whether $tracked reaches $posting: its category (a split line's own) is
     * tracked, or the transaction carries a tracked label. Needs the posting's
     * `labels` loaded; a split line shares its parent's.
     *
     * @param  TrackedIds  $tracked
     */
    private function isTrackedBy(Transaction $posting, array $tracked): bool
    {
        return in_array($posting->category_id, $tracked['categories'], true)
            || $posting->labels->pluck('id')->intersect($tracked['labels'])->isNotEmpty();
    }

    /**
     * @param  Collection<int, Budget>  $budgets
     * @return TrackedIds
     */
    private function trackedBy(string $userId, Collection $budgets): array
    {
        return [
            'categories' => $this->tree->expand($userId, $budgets->flatMap(fn (Budget $budget) => $budget->categories->pluck('id'))->all()),
            'labels' => $budgets->flatMap(fn (Budget $budget) => $budget->labels->pluck('id'))->unique()->values()->all(),
        ];
    }
}
