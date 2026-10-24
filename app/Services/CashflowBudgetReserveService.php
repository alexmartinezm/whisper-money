<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Enums\CashflowBlock;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * What the user's budgets still hold back from a cashflow period, and how much
 * of its spending they cover.
 *
 * @phpstan-type BudgetMarginRow array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, expense_type: string, allocated: int, spent: int, margin: int}
 * @phpstan-type ExpenseSplit array{fixed_expense: int, variable_expense: int, general_expense: int}
 *
 * @phpstan-import-type TrackedIds from CashflowBlockQuery
 */
class CashflowBudgetReserveService
{
    /**
     * The longest a quarter gets; any longer range is read as a year.
     */
    private const MAX_QUARTER_DAYS = 92;

    public function __construct(private CashflowBlockQuery $blocks) {}

    /**
     * What to append to a period's cashflow summary and to the one before it,
     * read off the summary's own postings: the same transactions, expanded the
     * same way, and added up with $sum, the summary's own conversion.
     *
     * The current period gets `reserved`, which adds up the positive margins
     * of the budget periods that overlap the range and are in progress today:
     * money still earmarked for spending. An over-budget period adds nothing
     * rather than eating into the rest, a closed one adds nothing because
     * whatever it left over is already back in the net, and a future one adds
     * nothing because none of this range's income is promised to it yet.
     * `budgets` lists the same periods, with margins that may be negative. A
     * margin, like the budget screens, ignores any carried-over amount. The
     * previous period gets neither: what budgets still reserve is about today,
     * which a past period has nothing to say about.
     *
     * Both only count the budgets whose period fits the range: weekly,
     * bi-weekly and monthly budgets for a range of up to a quarter (the month
     * and quarter views), yearly ones for anything longer (the year view). A
     * yearly budget's margin is not money held back from one month's income,
     * and a monthly one's says little about a whole year.
     *
     * Both periods get the expense split, see {@see self::expenseSplit()}.
     *
     * Budget amounts are read as stored, in the user's currency, the same way
     * the budget screens read them.
     *
     * @param  array{current: Collection<int, Transaction>, previous: Collection<int, Transaction>}  $postings
     * @param  Closure(Collection<int, Transaction>): int  $sum
     * @return array{current: array{reserved: int, budgets: list<BudgetMarginRow>, fixed_expense: int, variable_expense: int, general_expense: int}, previous: ExpenseSplit}
     */
    public function forComparedPeriods(string $userId, PeriodComparator $period, PeriodComparator $previousPeriod, array $postings, Closure $sum): array
    {
        [$tracked, $previousTracked] = $this->blocks->trackedIdsByPeriod($userId, [$period, $previousPeriod]);
        $this->loadLabelsIfTracked($postings, [$tracked, $previousTracked]);
        $budgets = $this->openBudgetMargins($userId, $period);

        return [
            'current' => [
                'reserved' => array_sum(array_map(fn (array $row): int => max(0, $row['margin']), $budgets)),
                'budgets' => $budgets,
                ...$this->expenseSplit($postings['current'], $tracked, $sum),
            ],
            'previous' => $this->expenseSplit($postings['previous'], $previousTracked, $sum),
        ];
    }

    /**
     * The period's expense split by the expense type of the budgets tracking
     * it, with a period in the range: `fixed_expense` for what a budget marked
     * fixed tracks, `variable_expense` for what one marked variable tracks,
     * `general_expense` for what no budget tracks. A posting tracked by both a
     * fixed and a variable budget counts once, as fixed.
     *
     * It is read posting by posting, the way budgets read a transaction: a
     * tracked label on a split transaction claims every line, otherwise each
     * line goes by its own category. Every expense-side posting falls in
     * exactly one of the three, so they always add up to the summary's
     * `expense` (any can be negative in a month of refunds, as `expense` can).
     * Unlike `reserved`, it reads as well for a past period as for the current
     * one. The classifier is {@see CashflowBlockQuery::blockOf()}, whose SQL
     * twin filters the transactions list by block, so a block's list holds
     * every posting its figure counts.
     *
     * @param  Collection<int, Transaction>  $postings
     * @param  array<string, TrackedIds>  $tracked
     * @param  Closure(Collection<int, Transaction>): int  $sum
     * @return ExpenseSplit
     */
    private function expenseSplit(Collection $postings, array $tracked, Closure $sum): array
    {
        $byBlock = $postings->groupBy(fn (Transaction $posting): string => $this->blocks->blockOf($posting, $tracked)->value ?? '');
        $expenseIn = fn (CashflowBlock $block): int => -$sum($byBlock->get($block->value, collect()));

        return [
            'fixed_expense' => $expenseIn(CashflowBlock::Fixed),
            'variable_expense' => $expenseIn(CashflowBlock::Variable),
            'general_expense' => $expenseIn(CashflowBlock::General),
        ];
    }

    /**
     * Load the labels of every posting at once, and only when some budget
     * tracks a label: a split line shares its parent's, and without tracked
     * labels the classifier never reads them.
     *
     * @param  array{current: Collection<int, Transaction>, previous: Collection<int, Transaction>}  $postings
     * @param  list<array<string, TrackedIds>>  $trackedByPeriod
     */
    private function loadLabelsIfTracked(array $postings, array $trackedByPeriod): void
    {
        $tracksLabels = collect($trackedByPeriod)
            ->flatten(1)
            ->contains(fn (array $tracked): bool => $tracked['labels'] !== []);

        if ($tracksLabels) {
            EloquentCollection::make($postings['current']->concat($postings['previous']))->load('labels:id');
        }
    }

    /**
     * @return list<BudgetMarginRow>
     */
    private function openBudgetMargins(string $userId, PeriodComparator $period): array
    {
        return BudgetPeriod::query()
            ->whereHas('budget', fn (Builder $query) => $query
                ->where('user_id', $userId)
                ->whereNull('archived_at')
                ->whereIn('period_type', $this->periodTypesFor($period)))
            ->overlapping($period->from, $period->to)
            // In progress today: a period that is over already gave back what it
            // left, and one yet to start holds nothing back from this income.
            ->where('start_date', '<=', today()->toDateString())
            ->where('end_date', '>=', today()->toDateString())
            ->with('budget:id,name,expense_type')
            ->withSum('budgetTransactions as spent', 'amount')
            ->orderBy('start_date')
            ->get()
            ->map(function (BudgetPeriod $budgetPeriod): array {
                $spent = (int) $budgetPeriod->getAttribute('spent');

                return [
                    'budget_id' => $budgetPeriod->budget_id,
                    'budget_period_id' => $budgetPeriod->id,
                    'name' => $budgetPeriod->budget->name,
                    'start_date' => $budgetPeriod->start_date->toDateString(),
                    'end_date' => $budgetPeriod->end_date->toDateString(),
                    'expense_type' => $budgetPeriod->budget->expense_type->value,
                    'allocated' => $budgetPeriod->allocated_amount,
                    'spent' => $spent,
                    // Same as BudgetPeriod::remainingAmount(), without a query per row.
                    'margin' => $budgetPeriod->allocated_amount - $spent,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The budget period types whose margins a range of this length reserves.
     *
     * @return list<BudgetPeriodType>
     */
    private function periodTypesFor(PeriodComparator $period): array
    {
        if ($period->days() > self::MAX_QUARTER_DAYS) {
            return [BudgetPeriodType::Yearly];
        }

        return [BudgetPeriodType::Weekly, BudgetPeriodType::Biweekly, BudgetPeriodType::Monthly];
    }
}
