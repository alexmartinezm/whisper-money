<?php

namespace App\Services\Recurring;

use App\Enums\AccountType;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Account;
use App\Models\RecurringSeries;
use App\Models\User;
use App\Services\BalanceLookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Answers "where will my balance be at the end of the month" by walking today's
 * cash forward through the charges detection already knows about.
 *
 * The screen used to only look backwards: it could say what repeats, but not
 * what that means for the money actually in the account.
 */
class ProjectCashflow
{
    /**
     * Accounts that hold spendable cash. Investments, loans, property and
     * retirement move on their own logic and would make the runway meaningless.
     */
    private const LIQUID_TYPES = [AccountType::Checking, AccountType::Savings];

    /**
     * @return array{
     *     currency_code: string,
     *     days: int,
     *     starting_balance: int,
     *     ending_balance: int,
     *     expected_in: int,
     *     expected_out: int,
     *     lowest: array{date: string, balance: int},
     *     other_currencies: list<string>,
     *     occurrences: list<array{date: string, series_id: string, display_name: string, amount: int, amount_is_variable: bool, balance_after: int, category: ?string}>
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
        $balance = $this->liquidBalance($user, $spaceId, $currency, $today);

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
            'other_currencies' => $series
                ->pluck('currency_code')
                ->reject(fn (string $code): bool => $code === $currency)
                ->unique()
                ->sort()
                ->values()
                ->all(),
            'occurrences' => $rows,
        ];
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
     * Today's spendable cash, in the user's own currency only. Converting other
     * currencies here would put an estimate at the head of a figure the rest of
     * the projection reports exactly.
     */
    private function liquidBalance(User $user, string $spaceId, string $currency, CarbonImmutable $today): int
    {
        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->where('space_id', $spaceId)
            ->where('currency_code', $currency)
            ->whereIn('type', self::LIQUID_TYPES)
            ->pluck('id');

        if ($accounts->isEmpty()) {
            return 0;
        }

        // BalanceLookup works in mutable Carbon.
        $asOf = $today->toMutable();
        $lookup = BalanceLookup::forAccounts($accounts, $today->subYear()->toMutable(), $asOf);

        return (int) $accounts->sum(fn (string $id): int => $lookup->getBalanceAt($id, $asOf));
    }
}
