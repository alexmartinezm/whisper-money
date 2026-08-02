<?php

namespace App\Services\NetWorth;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\RecurringSeries;
use App\Models\Space;
use App\Models\User;
use App\Services\BalanceLookup;
use App\Services\ExchangeRateService;
use App\Services\LoanAmortizationService;
use App\Services\Recurring\ProjectCashflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Where net worth is heading, split into what is already committed and what is
 * only a habit.
 *
 * The runway answers "will I make it to payday" out of spendable cash. This
 * answers the slower question, and the two are deliberately kept apart: a
 * mortgage that amortises makes you better off every month while doing nothing
 * for your current account.
 *
 * The split into two lines is the whole point. A mortgage repays on a contract,
 * a subscription renews at a known price, a flat revalues at a rate the user
 * typed in themselves — none of that depends on behaviour, and a year out it is
 * still worth something. Groceries are a median of three months, and a median
 * projected twelve times is a guess wearing a suit. Drawing both as one line
 * would make month twelve look exactly as solid as month one.
 */
class ProjectNetWorth
{
    /** Mirrors the twelve months of history the dashboard chart already draws. */
    private const MONTHS = 12;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private LoanAmortizationService $amortization,
        private ProjectCashflow $cashflow,
    ) {}

    /**
     * @return array{
     *     currency_code: string,
     *     months: int,
     *     today: int,
     *     committed: int,
     *     estimated: ?int,
     *     points: list<array{date: string, actual: ?int, committed: ?int, estimated: ?int}>,
     *     drivers: array{debt_repaid: int, property_growth: int, recurring_in: int, recurring_out: int, contributions: int, everyday_spending: ?int},
     *     assumptions: array{investments_flat: bool, revalued_accounts: list<string>, months_observed: ?int, recurring_income: bool}
     * }
     */
    public function forUser(User $user, ?Space $space = null): array
    {
        $currency = $user->currency_code ?? 'USD';
        $today = CarbonImmutable::today();
        $spaceId = ($space ?? $user->activeSpace())->id;

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->where('space_id', $spaceId)
            ->where('include_in_net_worth', true)
            ->with(['loanDetail', 'realEstateDetail'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Account $account): bool => $account->type->countsInNetWorth())
            ->values();

        // The cash side comes from the runway rather than a second walk of its
        // own, so the two screens can never disagree about what is due.
        $forecast = $this->cashflow->forUser($user, self::MONTHS * 31, $space);
        $moved = $this->contributionSeriesIds($user, $spaceId);

        $balances = $this->balancesToday($accounts, $currency, $today);
        $cashToday = $forecast['starting_balance'];

        // Everything that is not spendable cash: investments, property, debt.
        // Cash is tracked separately because the runway already owns it.
        $wealthToday = (int) $accounts
            ->reject(fn (Account $account): bool => $account->type->holdsSpendableCash())
            ->sum(fn (Account $account): int => $this->contribution($account, $balances[$account->id] ?? 0));

        $netToday = $cashToday + $wealthToday;
        $points = $this->history($accounts, $currency, $today, $netToday);

        $spending = $forecast['spending'];
        $perDay = $spending ? $spending['monthly'] / 30 : null;

        $committed = $netToday;
        $estimated = $spending ? $netToday : null;
        $drivers = ['debt_repaid' => 0, 'property_growth' => 0, 'recurring_in' => 0, 'recurring_out' => 0, 'contributions' => 0];

        foreach ($this->forwardDates($today) as $date) {
            $cash = $cashToday;
            $recurringIn = 0;
            $recurringOut = 0;
            $contributions = 0;

            foreach ($forecast['occurrences'] as $occurrence) {
                if ($occurrence['date'] > $date->toDateString()) {
                    break;
                }

                $cash += $occurrence['amount'];

                // Reported apart rather than netted, matching the runway's own
                // expected-in and expected-out. A single figure buries a year
                // of salary inside it, and a year of salary is the least
                // certain thing holding the committed line up.
                $occurrence['amount'] > 0
                    ? $recurringIn += $occurrence['amount']
                    : $recurringOut += $occurrence['amount'];

                // A standing order into a pension is not money gone: it leaves
                // the current account and arrives somewhere that still counts.
                // Treating it as spending would show net worth falling every
                // month the user saved harder.
                if (in_array($occurrence['series_id'], $moved, true)) {
                    $contributions += abs($occurrence['amount']);
                }
            }

            $wealth = (int) $accounts
                ->reject(fn (Account $account): bool => $account->type->holdsSpendableCash())
                ->sum(fn (Account $account): int => $this->contribution(
                    $account,
                    $this->projectedBalance($account, $balances[$account->id] ?? 0, $today, $date),
                ));

            $committed = $cash + $wealth + $contributions;
            $estimated = $perDay === null
                ? null
                : $committed + (int) round($perDay * $today->diffInDays($date));

            // Overwritten every pass on purpose: the breakdown describes the
            // horizon, so only the last month's figures survive the loop.
            $drivers = [
                'debt_repaid' => $this->debtRepaid($accounts, $balances, $today, $date),
                'property_growth' => $this->propertyGrowth($accounts, $balances, $today, $date),
                'recurring_in' => $recurringIn,
                'recurring_out' => $recurringOut,
                'contributions' => $contributions,
            ];

            $points[] = [
                'date' => $date->toDateString(),
                'actual' => null,
                'committed' => $committed,
                'estimated' => $estimated,
            ];
        }

        return [
            'currency_code' => $currency,
            'months' => self::MONTHS,
            'today' => $netToday,
            'committed' => $committed,
            'estimated' => $estimated,
            'points' => $points,
            'drivers' => $drivers + [
                'everyday_spending' => $spending === null
                    ? null
                    : $estimated - $committed,
            ],
            'assumptions' => [
                'investments_flat' => $accounts->contains(
                    fn (Account $account): bool => in_array($account->type, [AccountType::Investment, AccountType::Retirement], true),
                ),
                'revalued_accounts' => $accounts
                    ->filter(fn (Account $account): bool => (float) ($account->realEstateDetail?->revaluation_percentage ?? 0) !== 0.0)
                    ->map(fn (Account $account): string => $account->name)
                    ->values()
                    ->all(),
                'months_observed' => $spending['months_observed'] ?? null,
                // Worth saying out loud. The committed line leans on a year of
                // salary, and the mortgage only amortises because that salary
                // pays it -- the two stand or fall together.
                'recurring_income' => $drivers['recurring_in'] > 0,
            ],
        ];
    }

    /**
     * Series whose money only changes address. Category type is the only signal
     * available: a recurring transfer records no destination account, so a
     * pension contribution and a gym fee are the same row until the category
     * says otherwise.
     *
     * @return list<string>
     */
    private function contributionSeriesIds(User $user, string $spaceId): array
    {
        return RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('space_id', $spaceId)
            ->whereHas('category', fn ($query) => $query->whereIn('type', [
                CategoryType::Savings,
                CategoryType::Investment,
            ]))
            ->pluck('id')
            ->all();
    }

    /**
     * Twelve month-end points behind today, then today itself carrying all
     * three values so the forward lines start where the real one stops.
     *
     * @param  Collection<int, Account>  $accounts
     * @return list<array{date: string, actual: ?int, committed: ?int, estimated: ?int}>
     */
    private function history(Collection $accounts, string $currency, CarbonImmutable $today, int $netToday): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $start = $today->startOfMonth()->subMonthsNoOverflow(self::MONTHS);
        $lookup = BalanceLookup::forAccounts($accounts->pluck('id'), $start->toMutable(), $today->toMutable());
        $points = [];

        for ($back = self::MONTHS; $back >= 1; $back--) {
            $date = $today->startOfMonth()->subMonthsNoOverflow($back)->endOfMonth();

            $total = (int) $accounts->sum(fn (Account $account): int => $this->contribution(
                $account,
                $this->exchangeRateService->convert(
                    $account->currency_code,
                    $currency,
                    $lookup->getBalanceAt($account->id, $date->toMutable()),
                    $date->toDateString(),
                ),
            ));

            $points[] = ['date' => $date->toDateString(), 'actual' => $total, 'committed' => null, 'estimated' => null];
        }

        $points[] = [
            'date' => $today->toDateString(),
            'actual' => $netToday,
            'committed' => $netToday,
            'estimated' => $netToday,
        ];

        return $points;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @return array<string, int>
     */
    private function balancesToday(Collection $accounts, string $currency, CarbonImmutable $today): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $asOf = $today->toMutable();
        $lookup = BalanceLookup::forAccounts($accounts->pluck('id'), $today->subYear()->toMutable(), $asOf);

        return $accounts
            ->mapWithKeys(fn (Account $account): array => [
                $account->id => $this->exchangeRateService->convert(
                    $account->currency_code,
                    $currency,
                    $lookup->getBalanceAt($account->id, $asOf),
                    $today->toDateString(),
                ),
            ])
            ->all();
    }

    /**
     * What an account is worth on a future date, by its own mechanics.
     *
     * Investments and pensions hold still on purpose. Projecting a return would
     * be inventing the number that matters most, and the honest alternative —
     * showing them flat and saying so — at least fails in the direction that
     * cannot talk someone into a purchase.
     */
    private function projectedBalance(Account $account, int $todayBalance, CarbonImmutable $today, CarbonImmutable $date): int
    {
        if ($account->type === AccountType::Loan && $account->loanDetail) {
            return $this->amortization->getBalanceAtDate($account->loanDetail, $date->toMutable());
        }

        $rate = (float) ($account->realEstateDetail?->revaluation_percentage ?? 0);

        if ($account->type === AccountType::RealEstate && $rate !== 0.0) {
            $months = $today->diffInDays($date) / 30.4375;

            return (int) round($todayBalance * (1 + $rate / 100) ** ($months / 12));
        }

        return $todayBalance;
    }

    /** Debt counts against the total; everything else counts towards it. */
    private function contribution(Account $account, int $balance): int
    {
        return $account->type->reducesNetWorth() ? -abs($balance) : $balance;
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, int>  $balances
     */
    private function debtRepaid(Collection $accounts, array $balances, CarbonImmutable $today, CarbonImmutable $date): int
    {
        return (int) $accounts
            ->filter(fn (Account $account): bool => $account->type->reducesNetWorth())
            ->sum(fn (Account $account): int => abs($balances[$account->id] ?? 0)
                - abs($this->projectedBalance($account, $balances[$account->id] ?? 0, $today, $date)));
    }

    /**
     * @param  Collection<int, Account>  $accounts
     * @param  array<string, int>  $balances
     */
    private function propertyGrowth(Collection $accounts, array $balances, CarbonImmutable $today, CarbonImmutable $date): int
    {
        return (int) $accounts
            ->filter(fn (Account $account): bool => $account->type === AccountType::RealEstate)
            ->sum(fn (Account $account): int => $this->projectedBalance($account, $balances[$account->id] ?? 0, $today, $date)
                - ($balances[$account->id] ?? 0));
    }

    /** @return list<CarbonImmutable> */
    private function forwardDates(CarbonImmutable $today): array
    {
        return collect(range(0, self::MONTHS - 1))
            ->map(fn (int $month): CarbonImmutable => $today->startOfMonth()->addMonthsNoOverflow($month)->endOfMonth())
            ->all();
    }
}
