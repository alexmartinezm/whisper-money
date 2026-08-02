<?php

namespace App\Services\Recurring;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\ExchangeRateService;
use App\Support\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * How much leaves the account every month that detection knows nothing about.
 *
 * The recurring projection walks subscriptions and bills, which for most people
 * is well under half of what they spend. Groceries, restaurants, fuel and
 * presents never settle into a cadence, so a runway built from detected charges
 * alone is optimistic every month — and optimistic in the direction that hurts,
 * because warning is the whole point of the number.
 *
 * The gap is measured from the user's own history rather than from their
 * budgets: budgets are optional, cover a handful of categories, and state an
 * intention rather than a prediction. The recurring pivot makes the split exact
 * — a transaction either belongs to a series or it does not — so nothing has to
 * be inferred about which spending the projection already covers.
 */
class EstimateDiscretionarySpending
{
    /**
     * Category types that move money without spending it. A sweep into savings
     * is not consumption, and counting it would make anyone who saves on payday
     * look like they are haemorrhaging cash.
     */
    private const INTERNAL_TYPES = [
        CategoryType::Transfer,
        CategoryType::Savings,
        CategoryType::Investment,
    ];

    public function __construct(private readonly ExchangeRateService $exchangeRates) {}

    /**
     * The median month of non-recurring outflow across the recent complete
     * months, as a negative amount in the given currency.
     *
     * Null when there is not enough history to say anything. A pace
     * extrapolated from a fortnight is a guess wearing a number's clothes, and
     * this figure exists to make the runway more honest, not less.
     *
     * @param  Collection<int, string>  $accountIds
     * @return array{monthly: int, months_observed: int}|null
     */
    public function forAccounts(Collection $accountIds, string $currency, CarbonImmutable $today): ?array
    {
        $months = (int) config('recurring.spending_lookback_months');
        $minimum = (int) config('recurring.spending_min_months');

        if ($accountIds->isEmpty() || $months < 1) {
            return null;
        }

        // Complete months only. The month in progress is always partly unspent,
        // so letting it into the median would drag the pace down every time the
        // user opened the screen early in the month.
        $lastMonthEnd = $today->startOfMonth()->subDay();
        $firstMonthStart = $lastMonthEnd->startOfMonth()->subMonthsNoOverflow($months - 1);

        $totals = $this->monthlyTotals($accountIds->all(), $currency, $firstMonthStart, $lastMonthEnd);

        // A month with no qualifying outflow at all is treated as absent rather
        // than as a zero: far more often it means the ledger does not reach
        // back that far than that the user spent nothing for thirty days.
        if ($totals->count() < $minimum) {
            return null;
        }

        return [
            'monthly' => (int) round(Statistics::median($totals->values()->all())),
            'months_observed' => $totals->count(),
        ];
    }

    /**
     * Outflow per calendar month, keyed YYYY-MM.
     *
     * Two passes rather than one: a plain transaction carries its own category,
     * while a split carries none and its lines do. Summing the parent of a
     * split would count a shop that was half a transfer in full.
     *
     * @param  list<string>  $accountIds
     * @return Collection<string, int>
     */
    private function monthlyTotals(
        array $accountIds,
        string $currency,
        CarbonImmutable $from,
        CarbonImmutable $to,
    ): Collection {
        $window = [$from->toDateString(), $to->toDateString()];

        $unsplit = Transaction::query()
            ->whereIn('transactions.account_id', $accountIds)
            ->whereBetween('transactions.transaction_date', $window)
            ->where('transactions.amount', '<', 0)
            ->whereDoesntHave('splits')
            ->where(function (Builder $query): void {
                $query->whereNull('transactions.category_id')
                    ->orWhereHas('category', fn (Builder $category) => $category->whereNotIn('type', self::INTERNAL_TYPES));
            })
            ->where($this->notAlreadyRecurring())
            ->selectRaw("DATE_FORMAT(transactions.transaction_date, '%Y-%m') as month")
            ->selectRaw('transactions.currency_code as currency_code')
            ->selectRaw('SUM(transactions.amount) as total')
            ->groupBy('month', 'currency_code')
            ->get();

        $split = TransactionSplit::query()
            ->join('transactions', 'transactions.id', '=', 'transaction_splits.transaction_id')
            ->whereNull('transactions.deleted_at')
            ->whereIn('transactions.account_id', $accountIds)
            ->whereBetween('transactions.transaction_date', $window)
            ->where('transaction_splits.amount', '<', 0)
            ->where(function (Builder $query): void {
                $query->whereNull('transaction_splits.category_id')
                    ->orWhereHas('category', fn (Builder $category) => $category->whereNotIn('type', self::INTERNAL_TYPES));
            })
            ->where($this->notAlreadyRecurring())
            ->selectRaw("DATE_FORMAT(transactions.transaction_date, '%Y-%m') as month")
            ->selectRaw('transactions.currency_code as currency_code')
            ->selectRaw('SUM(transaction_splits.amount) as total')
            ->groupBy('month', 'currency_code')
            ->get();

        $byMonth = [];

        foreach ($unsplit->concat($split) as $row) {
            $month = (string) $row->getAttribute('month');

            // Converted once per month and currency rather than per row: a
            // figure reported as an approximation does not need a rate lookup
            // for every coffee.
            $byMonth[$month] = ($byMonth[$month] ?? 0) + $this->exchangeRates->convert(
                (string) $row->getAttribute('currency_code'),
                $currency,
                (int) $row->getAttribute('total'),
                $month.'-01',
            );
        }

        return collect($byMonth)->sortKeys();
    }

    /**
     * Excludes anything the projection already walks. The pivot holds at most
     * one row per transaction, so this can neither double count nor miss.
     */
    private function notAlreadyRecurring(): callable
    {
        return fn (Builder $query) => $query->whereNotExists(
            fn ($sub) => $sub->from('recurring_series_transaction')
                ->whereColumn('recurring_series_transaction.transaction_id', 'transactions.id')
        );
    }
}
