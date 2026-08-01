<?php

namespace App\Services\Recurring;

use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Account;
use App\Models\RecurringSeries;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Answers "will I make it to payday" by walking today's spendable cash forward
 * through the charges detection already knows about, plus an estimate of the
 * everyday spending it does not.
 *
 * Deliberately a different question from the dashboard's net worth. Property, a
 * pension and an investment portfolio all count towards being better off and
 * none of them pay a direct debit, so a runway that started from net worth
 * could not dip below zero for anyone with a flat — and would sit permanently
 * below zero for anyone early in a mortgage. Which accounts feed the figure is
 * reported alongside it, because "it does not match my accounts" is answered by
 * saying which ones count, not by quietly counting more.
 */
class ProjectCashflow
{
    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private EstimateDiscretionarySpending $spending,
    ) {}

    /**
     * @return array{
     *     currency_code: string,
     *     days: int,
     *     starting_balance: int,
     *     ending_balance: int,
     *     expected_in: int,
     *     expected_out: int,
     *     lowest: array{date: string, balance: int},
     *     accounts: list<array{id: string, name: string}>,
     *     spending: null|array{monthly: int, months_observed: int, ending_balance: int, lowest: array{date: string, balance: int}},
     *     other_currencies: list<string>,
     *     occurrences: list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, balance_after: int, category: ?string}>,
     *     later: list<array{month: string, total: int, charges: list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, category: ?string}>}>
     * }
     */
    public function forUser(User $user, ?int $days = null): array
    {
        $days ??= (int) config('recurring.forecast_days');
        $currency = $user->currency_code ?? 'USD';
        $today = CarbonImmutable::today();
        $horizon = $today->addDays($days);
        $spaceId = $user->activeSpace()->id;

        $series = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('space_id', $spaceId)
            ->where('status', RecurringSeriesStatus::Active)
            ->where('user_state', '!=', RecurringSeriesUserState::Ignored)
            ->with('category')
            ->get();

        $occurrences = $this->expand($series->where('currency_code', $currency), $today, $horizon);
        $accounts = $this->spendableAccounts($user, $spaceId);
        $balance = $this->spendableBalance($accounts, $currency, $today);

        $running = $balance;
        $expectedIn = 0;
        $expectedOut = 0;
        $lowest = ['date' => $today->toDateString(), 'balance' => $balance];
        $rows = [];

        foreach ($occurrences as $occurrence) {
            $running += $occurrence['amount'];
            $occurrence['amount'] > 0
                ? $expectedIn += $occurrence['amount']
                : $expectedOut += $occurrence['amount'];

            if ($running < $lowest['balance']) {
                $lowest = ['date' => $occurrence['date'], 'balance' => $running];
            }

            $rows[] = $occurrence + ['balance_after' => $running];
        }

        return [
            'currency_code' => $currency,
            'days' => $days,
            'starting_balance' => $balance,
            'ending_balance' => $running,
            'expected_in' => $expectedIn,
            'expected_out' => $expectedOut,
            'lowest' => $lowest,
            'accounts' => $accounts
                ->map(fn (Account $account): array => ['id' => $account->id, 'name' => $account->name])
                ->values()
                ->all(),
            'spending' => $this->everydaySpending($accounts, $currency, $today, $days, $balance, $occurrences),
            'other_currencies' => $series
                ->pluck('currency_code')
                ->reject(fn (string $code): bool => $code === $currency)
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'occurrences' => $rows,
            'later' => $this->outlook(
                $series->where('currency_code', $currency),
                $horizon->addDay(),
                (int) config('recurring.outlook_months'),
                $days,
            ),
        ];
    }

    /**
     * The irregular charges waiting past the projection window, by month.
     *
     * A quarterly insurance premium or an annual renewal never lands inside 30
     * days, so until now they were detected but shown against no date at all —
     * exactly the charges that catch people out. Anything billing monthly or
     * faster is deliberately left out: it already appears in the projection and
     * in the monthly total, and repeating it once a month for a year would bury
     * the single premium this list exists to surface.
     *
     * No running balance out here either: projecting months ahead from recurring
     * charges alone would ignore groceries and everything discretionary, and a
     * reassuring wrong number is worse than none.
     *
     * @param  Collection<int, RecurringSeries>  $series
     * @return list<array{month: string, total: int, charges: list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, category: ?string}>}>
     */
    private function outlook(Collection $series, CarbonImmutable $from, int $months, int $windowDays): array
    {
        if ($months < 1) {
            return [];
        }

        // Cadence is authoritative: calendar-monthly charges can have 32-day gaps
        // when their dates cross short and long months.
        $minInterval = max($windowDays, 31);
        $irregular = $series->filter(fn (RecurringSeries $row): bool => $row->cadence !== RecurringCadence::Monthly
            && $row->interval_days > $minInterval);

        if ($irregular->isEmpty()) {
            return [];
        }

        $horizon = $from->addMonthsNoOverflow($months)->endOfMonth();
        $byMonth = [];

        // Months with nothing due are simply absent — an empty card would be a
        // row of chrome saying nothing.
        foreach ($this->expand($irregular, $from, $horizon) as $occurrence) {
            $byMonth[substr($occurrence['date'], 0, 7)][] = $occurrence;
        }

        ksort($byMonth);

        return collect($byMonth)
            ->map(fn (array $charges, string $month): array => [
                'month' => $month,
                'total' => (int) collect($charges)->sum('amount'),
                'charges' => $charges,
            ])
            ->values()
            ->all();
    }

    /**
     * Every charge falling inside the window, not just the next one per series:
     * a weekly gym fee hits four times before the month is out, and a runway
     * that counted it once would be wrong in the direction that hurts.
     *
     * @param  Collection<int, RecurringSeries>  $series
     * @return list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, category: ?string}>
     */
    private function expand(Collection $series, CarbonImmutable $today, CarbonImmutable $horizon): array
    {
        $occurrences = [];

        foreach ($series as $row) {
            $interval = max(1, $row->interval_days);
            $date = CarbonImmutable::parse($row->next_expected_on);

            // A series whose next date already passed has not been re-scanned
            // yet; roll it forward rather than reporting a charge in the past.
            while ($date->lessThan($today)) {
                $date = $date->addDays($interval);
            }

            while ($date->lessThanOrEqualTo($horizon)) {
                $occurrences[] = [
                    'date' => $date->toDateString(),
                    'series_id' => $row->id,
                    'display_name' => $row->display_name,
                    'amount' => $row->expected_amount,
                    'amount_is_variable' => $row->amount_is_variable,
                    'category' => $row->category?->name,
                ];

                $date = $date->addDays($interval);
            }
        }

        usort($occurrences, fn (array $a, array $b): int => [$a['date'], $a['display_name']] <=> [$b['date'], $b['display_name']]);

        return $occurrences;
    }

    /**
     * The accounts a direct debit can actually come out of.
     *
     * @return EloquentCollection<int, Account>
     */
    private function spendableAccounts(User $user, string $spaceId): EloquentCollection
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->where('space_id', $spaceId)
            ->where('include_in_net_worth', true)
            ->orderBy('name')
            ->get()
            ->filter(fn (Account $account): bool => $account->type->holdsSpendableCash())
            ->values();
    }

    /**
     * Today's spendable cash, converted into the user's own currency.
     *
     * Currencies are converted rather than dropped: money in a second currency
     * is still money, and silently leaving an account out was what made this
     * figure impossible to reconcile against the accounts screen.
     *
     * @param  EloquentCollection<int, Account>  $accounts
     */
    private function spendableBalance(EloquentCollection $accounts, string $currency, CarbonImmutable $today): int
    {
        if ($accounts->isEmpty()) {
            return 0;
        }

        // BalanceLookup works in mutable Carbon.
        $asOf = $today->toMutable();
        $lookup = BalanceLookup::forAccounts($accounts->pluck('id'), $today->subYear()->toMutable(), $asOf);

        return (int) $accounts->sum(fn (Account $account): int => $this->exchangeRateService->convert(
            $account->currency_code,
            $currency,
            $lookup->getBalanceAt($account->id, $asOf),
            $today->toDateString(),
        ));
    }

    /**
     * The same window walked again, this time with everyday spending in it.
     *
     * Reported apart from the exact figures rather than folded into them: the
     * four headline numbers each reconcile against the list of charges below,
     * and an estimate mixed into an auditable total would cost that without
     * announcing it. The estimate spreads a median month evenly across the
     * window, which is wrong about any given Tuesday and about right by the end
     * of the month — the horizon the number is read at.
     *
     * @param  EloquentCollection<int, Account>  $accounts
     * @param  list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, category: ?string}>  $occurrences
     * @return null|array{monthly: int, months_observed: int, ending_balance: int, lowest: array{date: string, balance: int}}
     */
    private function everydaySpending(
        EloquentCollection $accounts,
        string $currency,
        CarbonImmutable $today,
        int $days,
        int $balance,
        array $occurrences,
    ): ?array {
        $estimate = $this->spending->forAccounts($accounts->pluck('id'), $currency, $today);

        if ($estimate === null || $estimate['monthly'] >= 0) {
            return null;
        }

        $charges = [];
        foreach ($occurrences as $occurrence) {
            $charges[$occurrence['date']] = ($charges[$occurrence['date']] ?? 0) + $occurrence['amount'];
        }

        // A month of spending per thirty days of window. Rounding the running
        // total rather than the daily slice keeps a rounded cent from
        // compounding into euros over a long horizon.
        $perDay = $estimate['monthly'] / 30;
        $running = $balance;
        $lowest = ['date' => $today->toDateString(), 'balance' => $balance];

        for ($day = 1; $day <= $days; $day++) {
            $date = $today->addDays($day)->toDateString();
            $slice = (int) round($perDay * $day) - (int) round($perDay * ($day - 1));
            $running += $slice + ($charges[$date] ?? 0);

            if ($running < $lowest['balance']) {
                $lowest = ['date' => $date, 'balance' => $running];
            }
        }

        return [
            'monthly' => $estimate['monthly'],
            'months_observed' => $estimate['months_observed'],
            'ending_balance' => $running,
            'lowest' => $lowest,
        ];
    }
}
