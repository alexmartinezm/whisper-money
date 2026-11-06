<?php

namespace App\Services;

use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * How many transactions each day holds and what they add up to, for the day
 * headers of the transactions list.
 *
 * Counted over every row the list's filters match, not over the page that was
 * served, so a day cut in two by the pagination shows its whole figure from
 * the first page on.
 *
 * `count` is the transactions the list shows that day: a split transaction is
 * one row of the list, so it counts once however many of its lines match, and
 * it counts even when the list holds it for its own category and no line
 * matches. It is not the export's row count, which is per line.
 *
 * `total` adds up postings. A split transaction stands at its own amount,
 * unless the filters name a category, a category type, a label or a cashflow
 * block: then it only adds the lines they keep, the ones the CSV export would
 * write ({@see TransactionCsvExporter::postings()}, which goes through
 * {@see CashflowBlockQuery::postingFilter()}), because the rest of it is not
 * what the list was asked for.
 */
class TransactionDayTotals
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private TransactionCsvExporter $exporter,
    ) {}

    /**
     * One grouped query over the given days. Each currency's sum is converted
     * at its own day's rate, the rate every row of that group would get, so it
     * matches converting row by row up to one rounding per currency instead of
     * one per row. A day holding an amount with no rate gets a null total: a
     * figure that skipped it would be wrong.
     *
     * A rate the table does not hold yet is fetched from the provider once and
     * stored, as every other conversion does. A failing provider costs the page
     * one bounded attempt, not one per day ({@see ExchangeRateService::convertManyOrNull()}):
     * the days it leaves without a rate show no total, the others keep theirs.
     *
     * @param  Builder<Transaction>  $filtered  The list's query with its filters, without order or eager loads
     * @param  Collection<int, string>  $dates  Days as `Y-m-d`
     * @param  array<string, mixed>  $filters  The filters applied to $filtered, as {@see Transaction::scopeApplyFilters()} takes them
     * @return array<string, array{count: int, total: ?int}>
     */
    public function forDays(Builder $filtered, Collection $dates, string $currency, array $filters = []): array
    {
        $dates = $dates->unique()->values();

        if ($dates->isEmpty()) {
            return [];
        }

        return $this->forEveryDay($filtered->whereIn('transactions.transaction_date', $dates->all()), $currency, $filters);
    }

    /**
     * The same figures for every day the query matches, as {@see self::forDays()}
     * reads them, for a summary that adds those days up rather than showing them.
     *
     * @param  Builder<Transaction>  $filtered  A query with its filters, without order or eager loads
     * @param  array<string, mixed>  $filters  The filters applied to $filtered, as {@see Transaction::scopeApplyFilters()} takes them
     * @return array<string, array{count: int, total: ?int}>
     */
    public function forEveryDay(Builder $filtered, string $currency, array $filters = []): array
    {
        $groups = (clone $filtered)
            ->toBase()
            ->select('transactions.transaction_date', 'transactions.currency_code')
            ->selectRaw('count(*) as transactions_count, sum(transactions.amount) as amount_sum')
            ->groupBy('transactions.transaction_date', 'transactions.currency_code')
            ->get();

        $this->narrowSplitTransactions($groups, $filtered, $filters);

        $converted = $this->exchangeRateService->convertManyOrNull($groups
            ->map(fn (stdClass $group): array => [
                'from' => $group->currency_code,
                'amount' => (int) $group->amount_sum,
                'date' => $this->dayOf($group),
            ])
            ->all(), $currency);

        return $groups
            ->zip($converted)
            ->groupBy(fn (Collection $group): string => $this->dayOf($group->first()))
            ->map(fn (Collection $day): array => [
                'count' => $day->sum(fn (Collection $group): int => (int) $group->first()->transactions_count),
                'total' => $this->addedUp($day->map(fn (Collection $group): ?int => $group->last())),
            ])
            ->all();
    }

    /**
     * The split transactions these filters narrow, each with what its kept
     * lines add up to, in its own currency. Empty when no filter narrows
     * lines, which keeps the whole list in the one grouped query.
     *
     * @param  Builder<Transaction>  $filtered
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array{transaction: Transaction, kept: int}>
     */
    public function narrowedSplitTransactions(Builder $filtered, array $filters): Collection
    {
        if (! $this->narrowsLines($filters)) {
            return collect();
        }

        return $this->exporter
            ->withRowRelations((clone $filtered)->whereHas('splits'), $filters)
            ->get()
            ->toBase()
            ->map(fn (Transaction $transaction): array => [
                'transaction' => $transaction,
                'kept' => collect($this->exporter->postings([$transaction], $filters))->sum('amount'),
            ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function narrowsLines(array $filters): bool
    {
        return collect(['category_ids', 'category_types', 'label_ids', 'cashflow_block'])
            ->contains(fn (string $filter): bool => ! empty($filters[$filter]));
    }

    /**
     * Swaps each split transaction's amount in its group's sum for the lines
     * the filters keep. A split transaction's lines are in its own currency,
     * so it stays in its group.
     *
     * @param  Collection<int, stdClass>  $groups
     * @param  Builder<Transaction>  $filtered
     * @param  array<string, mixed>  $filters
     */
    private function narrowSplitTransactions(Collection $groups, Builder $filtered, array $filters): void
    {
        $byDayAndCurrency = $groups->keyBy(fn (stdClass $group): string => $this->dayOf($group).strtoupper($group->currency_code));

        foreach ($this->narrowedSplitTransactions($filtered, $filters) as ['transaction' => $transaction, 'kept' => $kept]) {
            $group = $byDayAndCurrency[$transaction->transaction_date->toDateString().strtoupper($transaction->currency_code)];

            $group->amount_sum += $kept - $transaction->amount;
        }
    }

    /**
     * A day's converted sums added up, or null when any of them has no rate.
     *
     * @param  Collection<int, ?int>  $amounts
     */
    private function addedUp(Collection $amounts): ?int
    {
        return $amounts->containsStrict(null) ? null : (int) $amounts->sum();
    }

    /**
     * The group's day as `Y-m-d`, whichever way the driver hands the DATE back.
     */
    private function dayOf(stdClass $group): string
    {
        return substr((string) $group->transaction_date, 0, 10);
    }
}
