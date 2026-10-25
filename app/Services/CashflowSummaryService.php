<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Transaction;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Collection;

class CashflowSummaryService
{
    public function __construct(private CashflowAggregateQuery $aggregates) {}

    /**
     * Derive the summary from signed income and expense totals, in minor units.
     * Either side can be negative when a period's reversals outweigh what they
     * reverse, and the net says so rather than hiding it.
     *
     * @return array{income: int, expense: int, net: int, savings_rate: float|int}
     */
    private static function summarize(int $income, int $expense): array
    {
        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savings_rate' => $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0,
        ];
    }

    /**
     * A period's cashflow summary alongside the one before it, in the user's
     * currency. The dashboard widget and the cashflow screen both read this, so
     * the same month cannot come out two different ways.
     *
     * Whatever else a caller reads off the same figures goes through $extend,
     * so it neither loads nor converts them again. It gets each period's
     * groups as the summary adds them up (see {@see CashflowAggregateQuery}:
     * unsplit rows grouped in SQL, and one group per split line), each already
     * worth its share in the user's currency, and returns the fields to append
     * to each period's summary. Groups carry the labels in $trackedLabelIds
     * for whoever classifies them by label.
     *
     * @param  (Closure(array{current: Collection<int, CashflowAggregate>, previous: Collection<int, CashflowAggregate>}): array{current: array<string, mixed>, previous: array<string, mixed>})|null  $extend
     * @param  list<string>  $trackedLabelIds
     * @return array{current: array<string, mixed>, previous: array<string, mixed>}
     */
    public function forComparedPeriods(string $userId, string $userCurrency, PeriodComparator $period, PeriodComparator $previousPeriod, ?Closure $extend = null, array $trackedLabelIds = []): array
    {
        $aggregates = $this->aggregates->aggregateByPeriod(
            Transaction::query()
                ->where('transactions.user_id', $userId)
                ->whereBetween('transactions.transaction_date', [$previousPeriod->from, $period->to]),
            $userCurrency,
            ['current' => $period, 'previous' => $previousPeriod],
            $trackedLabelIds,
        );

        $byPeriod = ['current' => $aggregates['current'], 'previous' => $aggregates['previous']];
        $extra = $extend === null ? ['current' => [], 'previous' => []] : $extend($byPeriod);

        return [
            'current' => [...$this->forAggregates($byPeriod['current']), ...$extra['current']],
            'previous' => [...$this->forAggregates($byPeriod['previous']), ...$extra['previous']],
        ];
    }

    /**
     * The same summary, one row per calendar month between two dates, keyed by
     * YYYY-MM. One query for the whole range instead of one per month: the
     * monthly analysis needs a year of savings rates to draw a streak and a
     * sparkline, and twelve round trips for that would be twelve too many.
     *
     * A split transaction counts as its parts here exactly as it does on the
     * cashflow screen, each in the month of its transaction.
     *
     * @param  string|null  $spaceId  that space's transactions, whoever recorded
     *                                them, instead of the user's
     * @return array<string, array<string, mixed>>
     */
    public function forMonths(string $userId, string $userCurrency, Carbon $from, Carbon $to, ?string $spaceId = null): array
    {
        $aggregates = $this->aggregates->aggregateByMonth(
            Transaction::query()
                ->when(
                    $spaceId === null,
                    fn ($query) => $query->where('transactions.user_id', $userId),
                    fn ($query) => $query->forSpace($spaceId),
                )
                ->whereBetween('transactions.transaction_date', [
                    $from->copy()->startOfMonth(),
                    $to->copy()->endOfMonth(),
                ]),
            $userCurrency,
        );

        $months = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $months[$cursor->format('Y-m')] = $this->forAggregates($aggregates->get($cursor->format('Y-m'), collect()));

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  Collection<int, CashflowAggregate>  $aggregates
     * @return array<string, mixed>
     */
    private function forAggregates(Collection $aggregates): array
    {
        // Deliberately unclamped: a month whose refunds outweigh its spending
        // has negative expenses, and clamping that to zero is the one place
        // money goes missing from the net.
        $income = $this->sumWhere($aggregates, fn (Transaction $transaction): bool => $transaction->isIncomeSide());
        $expense = -$this->sumWhere($aggregates, fn (Transaction $transaction): bool => $transaction->isExpenseSide());

        return [
            ...self::summarize($income, $expense),
            'savings' => $this->sumSetAside($aggregates, CategoryType::Savings),
            'investments' => $this->sumSetAside($aggregates, CategoryType::Investment),
        ];
    }

    /**
     * What was set aside into $type, net: an outflow adds, a withdrawal back
     * subtracts, so a period of net withdrawals comes out negative. Legs on a
     * savings, investment or retirement account are skipped, since the one on
     * the other account already counts that money.
     *
     * @param  Collection<int, CashflowAggregate>  $aggregates
     */
    private function sumSetAside(Collection $aggregates, CategoryType $type): int
    {
        return -$this->sumWhere(
            $aggregates,
            fn (Transaction $transaction): bool => $transaction->categoryType() === $type
                && ! $transaction->isSetAsideAccountLeg(),
        );
    }

    /**
     * @param  Collection<int, CashflowAggregate>  $aggregates
     * @param  callable(Transaction): bool  $counts
     */
    private function sumWhere(Collection $aggregates, callable $counts): int
    {
        return CashflowAggregate::total($aggregates->filter(fn (CashflowAggregate $aggregate): bool => $counts($aggregate->transaction)));
    }
}
