<?php

namespace App\Services;

use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Net worth at a point in time, in the user's currency.
 *
 * This used to live inside DashboardAnalyticsController. It moved out because a
 * second caller appeared — the on-demand monthly analysis — and a figure the
 * analysis quotes has to be the same one the dashboard shows, or the reader
 * stops believing the rest of it.
 *
 * What counts is decided per account, not per type preference: an account the
 * user has switched off through `include_in_net_worth` is out, and so is any
 * type that does not count towards wealth at all (credit cards are spending
 * accounts). The loan and real-estate switches beside the dashboard chart are
 * deliberately not read here — they filter the drawn series in the browser, so
 * folding them in would silently move the headline total and what the
 * `get_net_worth` MCP tool reports.
 */
class NetWorthCalculator
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * @param  Collection<int, Account>  $accounts
     */
    public function at(
        Collection $accounts,
        BalanceLookup $lookup,
        Carbon $date,
        string $userCurrency,
    ): int {
        $total = 0;

        foreach ($accounts as $account) {
            if (! $this->counts($account)) {
                continue;
            }

            $total += $this->contributionOf($account, $lookup, $date, $userCurrency);
        }

        return $total;
    }

    private function counts(Account $account): bool
    {
        return $account->include_in_net_worth !== false
            && $account->type->countsInNetWorth();
    }

    /**
     * Liabilities are stored as positive magnitudes, so they always subtract.
     * Assets keep their real sign, so an overdrawn checking account correctly
     * reduces net worth instead of being flipped positive.
     */
    private function contributionOf(
        Account $account,
        BalanceLookup $lookup,
        Carbon $date,
        string $userCurrency,
    ): int {
        $converted = $this->exchangeRateService->convert(
            $account->currency_code,
            $userCurrency,
            $lookup->getBalanceAt($account->id, $date),
            $date->toDateString(),
        );

        return $account->type->reducesNetWorth()
            ? -abs($converted)
            : $converted;
    }
}
