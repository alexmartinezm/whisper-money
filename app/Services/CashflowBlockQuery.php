<?php

namespace App\Services;

use App\Enums\BudgetExpenseType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Which budget expense type a cashflow posting belongs to, read the way the
 * fork's budgets read a transaction ({@see BudgetTransactionService}): a label
 * a budget tracks on the transaction claims all of it, otherwise each posting
 * goes by its own category. A posting is an element of
 * `effectiveTransactions()`: an unsplit transaction, or one split line carrying
 * its parent's labels.
 *
 * The per-posting classifier and its SQL twin sit side by side and share the
 * tracked ids. The SQL side works at transaction level and matches a
 * transaction when it, or any of its split lines, is tracked, so every posting
 * the classifier counts belongs to a transaction the query returns; a split
 * transaction can match while only some of its lines count.
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
     * The expense type of the budgets tracking $posting, or null when none
     * does. Fixed wins an overlap, so a posting is only ever counted once.
     *
     * @param  array<string, TrackedIds>  $trackedByType  as {@see self::trackedIds()} returns it
     */
    public function expenseTypeOf(Transaction $posting, array $trackedByType): ?BudgetExpenseType
    {
        foreach ([BudgetExpenseType::Fixed, BudgetExpenseType::Variable] as $type) {
            if ($this->isTrackedBy($posting, $trackedByType[$type->value])) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Narrow $rows to the transactions $tracked reaches: booked to a tracked
     * category, carrying a tracked label, or split with a line in a tracked
     * category. The SQL twin of {@see self::isTrackedBy()}.
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
