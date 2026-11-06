<?php

namespace App\Services;

use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What every transaction a filter set matches adds up to: how many there are,
 * their total and average in the reader's currency, whether they are all
 * expenses, all income or both, and the same filters month by month over the
 * six months the filter ends in.
 *
 * Every figure stands on {@see TransactionDayTotals}, so it agrees with the
 * day headers: `count` is the rows the list shows, a split transaction once,
 * and `total` adds a split transaction's kept lines under a category, category
 * type, label or cashflow block filter, as the analysis drawer does. `average`
 * divides by the rows, so a split transaction listed for its own category with
 * no matching line weighs on it with nothing added. `kind` reads what each row adds, its
 * kept lines for a narrowed split transaction, so a refund line picked out of
 * a split expense reads as income.
 *
 * Built from SQL aggregates, with only the narrowed split transactions read
 * as rows, so it is cheap enough for every load of the transactions page.
 *
 * Amounts in another currency go through
 * {@see ExchangeRateService::convertManyOrNull()}, as the day headers do: a
 * failing provider costs one bounded attempt for the total and one for the
 * trend, and the figures it leaves without a rate read null.
 */
class TransactionFilterSummary
{
    /**
     * How many months the trend covers, the filter's last month included.
     */
    private const TREND_MONTHS = 6;

    public function __construct(private TransactionDayTotals $dayTotals) {}

    /**
     * The total adds up each day's figure, each currency converted once at
     * that day's rate as the day headers do, so it agrees with them to the
     * cent. A day with no rate leaves the total (and the average) null rather
     * than a figure that skipped it.
     *
     * @param  array<string, mixed>  $filters  As `IndexTransactionRequest::filters()` hands them, `user_id` included
     * @return array{count: int, total: ?int, average: ?int, kind: 'expense'|'income'|'mixed', currency: string, trend: list<array{month: string, total: ?int}>}
     */
    public function summarize(array $filters, string $currency): array
    {
        $signs = $this->signs($filters);

        $count = $signs['count'];
        $total = $count === 0 ? 0 : $this->sumOfDays($this->dayTotals->forEveryDay($this->matching($filters), $currency, $filters));

        return [
            'count' => $count,
            'total' => $total,
            'average' => $total === null || $count === 0 ? $total : (int) round($total / $count),
            'kind' => $this->kind($signs['expenses'], $signs['incomes']),
            'currency' => $currency,
            'trend' => $this->trend($filters, $currency),
        ];
    }

    /**
     * How many rows the filters list, without reading a total or a rate: what
     * a filter set will show before it is applied.
     *
     * @param  array<string, mixed>  $filters  As `IndexTransactionRequest::filters()` hands them, `user_id` included
     */
    public function count(array $filters): int
    {
        return $this->matching($filters)->count();
    }

    /**
     * How many rows there are and how many of them add an expense or an
     * income: a narrowed split transaction counts by its kept lines, not by
     * its own amount.
     *
     * @param  array<string, mixed>  $filters
     * @return array{count: int, expenses: int, incomes: int}
     */
    private function signs(array $filters): array
    {
        $signs = $this->matching($filters)
            ->toBase()
            ->selectRaw('count(*) as transactions_count')
            ->selectRaw('sum(case when transactions.amount < 0 then 1 else 0 end) as expenses_count')
            ->selectRaw('sum(case when transactions.amount > 0 then 1 else 0 end) as incomes_count')
            ->first();

        $expenses = (int) $signs->expenses_count;
        $incomes = (int) $signs->incomes_count;

        foreach ($this->dayTotals->narrowedSplitTransactions($this->matching($filters), $filters) as ['transaction' => $transaction, 'kept' => $kept]) {
            $expenses += (int) ($kept < 0) - (int) ($transaction->amount < 0);
            $incomes += (int) ($kept > 0) - (int) ($transaction->amount > 0);
        }

        return ['count' => (int) $signs->transactions_count, 'expenses' => $expenses, 'incomes' => $incomes];
    }

    /**
     * Month by month over the window that ends in the filter's last month (or
     * this month without one), its own date range set aside for that window's.
     * A cashflow block is then read over the whole window, the period that
     * filter needs, rather than over the filter's own dates.
     *
     * @param  array<string, mixed>  $filters
     * @return list<array{month: string, total: ?int}>
     */
    private function trend(array $filters, string $currency): array
    {
        $end = isset($filters['date_to'])
            ? CarbonImmutable::parse($filters['date_to'])->endOfMonth()
            : CarbonImmutable::now()->endOfMonth();
        $start = $end->subMonthsNoOverflow(self::TREND_MONTHS - 1)->startOfMonth();

        $window = [
            ...$filters,
            'date_from' => $start->toDateString(),
            'date_to' => $end->toDateString(),
        ];

        $days = collect($this->dayTotals->forEveryDay($this->matching($window), $currency, $window));

        $trend = [];

        for ($month = $start; $month->lessThan($end); $month = $month->addMonthNoOverflow()) {
            $key = $month->format('Y-m');

            $trend[] = [
                'month' => $key,
                'total' => $this->sumOfDays($days->filter(fn (array $day, string $date): bool => str_starts_with($date, $key))->all()),
            ];
        }

        return $trend;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    private function matching(array $filters): Builder
    {
        return Transaction::query()
            ->where('user_id', $filters['user_id'])
            ->applyFilters($filters);
    }

    /**
     * @param  array<string, array{count: int, total: ?int}>  $days
     */
    private function sumOfDays(array $days): ?int
    {
        $total = 0;

        foreach ($days as $day) {
            if ($day['total'] === null) {
                return null;
            }

            $total += $day['total'];
        }

        return $total;
    }

    /**
     * @return 'expense'|'income'|'mixed'
     */
    private function kind(int $expenses, int $incomes): string
    {
        if ($expenses > 0 && $incomes === 0) {
            return 'expense';
        }

        if ($incomes > 0 && $expenses === 0) {
            return 'income';
        }

        return 'mixed';
    }
}
