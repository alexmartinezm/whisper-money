<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Support\DatedSeries;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use stdClass;

class BalanceLookup
{
    /**
     * Each account's recorded balances by day, keyed by account ID.
     *
     * @var array<string, DatedSeries>
     */
    private array $balancesByAccount = [];

    /**
     * Each account's non-null invested amounts by day, keyed by account ID.
     * The null ones are left out so the newest known amount carries forward
     * over them.
     *
     * @var array<string, DatedSeries>
     */
    private array $investedByAccount = [];

    /**
     * Preload all balance data for a set of accounts covering the given date range.
     *
     * Executes exactly 4 efficient queries (no correlated subqueries):
     * 1. A derived-table join to find the latest balance record before the range start per account.
     * 2. A derived-table join to find the latest non-null invested_amount before the range start per account.
     * 3. All balance records within the range.
     * 4. The accounts that only count as a share of their balance.
     *
     * Those accounts get every figure scaled down to the owner's share here,
     * so every reader (net worth, evolution charts, account metrics) stays
     * consistent. Callers that need the real bank balance — the balance editor,
     * imports, bank sync — read `account_balances` directly and are unaffected.
     *
     * @param  Collection<int, string>|array<string>  $accountIds
     */
    public static function forAccounts(Collection|array $accountIds, Carbon $rangeStart, Carbon $rangeEnd): self
    {
        $instance = new self;

        if (empty($accountIds)) {
            return $instance;
        }

        $accountIdList = $accountIds instanceof Collection ? $accountIds->all() : $accountIds;
        $startDate = $rangeStart->toDateString();
        $endDate = $rangeEnd->toDateString();

        // Query 1: Get the latest balance record before the range start for each account.
        // Uses a derived table with GROUP BY + MAX() joined back, avoiding correlated subquery.
        $carryForwardRecords = AccountBalance::query()
            ->whereIn('account_balances.account_id', $accountIdList)
            ->joinSub(
                AccountBalance::query()
                    ->selectRaw('account_id, MAX(balance_date) as max_date')
                    ->whereIn('account_id', $accountIdList)
                    ->where('balance_date', '<', $startDate)
                    ->groupBy('account_id'),
                'latest',
                function ($join) {
                    $join->on('account_balances.account_id', '=', 'latest.account_id')
                        ->on('account_balances.balance_date', '=', 'latest.max_date');
                }
            )
            ->toBase()
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.balance', 'account_balances.invested_amount']);

        // Query 2: Get the latest non-null invested_amount before the range start for each account.
        $investedCarryForwardRecords = AccountBalance::query()
            ->whereIn('account_balances.account_id', $accountIdList)
            ->joinSub(
                AccountBalance::query()
                    ->selectRaw('account_id, MAX(balance_date) as max_date')
                    ->whereIn('account_id', $accountIdList)
                    ->where('balance_date', '<', $startDate)
                    ->whereNotNull('invested_amount')
                    ->groupBy('account_id'),
                'latest_invested',
                function ($join) {
                    $join->on('account_balances.account_id', '=', 'latest_invested.account_id')
                        ->on('account_balances.balance_date', '=', 'latest_invested.max_date');
                }
            )
            ->toBase()
            ->get(['account_balances.account_id', 'account_balances.balance_date', 'account_balances.invested_amount']);

        // Query 3: All balance records within the range.
        $rangeRecords = AccountBalance::query()
            ->whereIn('account_id', $accountIdList)
            ->whereBetween('balance_date', [$startDate, $endDate])
            ->orderBy('balance_date')
            ->toBase()
            ->get(['account_id', 'balance_date', 'balance', 'invested_amount']);

        // Grouped once, so building an account never scans another's rows.
        $carryForwardRecords = self::rowsByAccount($carryForwardRecords);
        $investedCarryForwardRecords = self::rowsByAccount($investedCarryForwardRecords);
        $rangeRecords = self::rowsByAccount($rangeRecords);

        // The accounts that only count towards the user's figures as a share of
        // their balance; everything else is read at face value.
        $sharedAccounts = Account::query()
            ->whereIn('id', $accountIdList)
            ->where('ownership_applies_to_balance', true)
            ->get()
            ->keyBy('id');

        foreach ($accountIdList as $accountId) {
            $account = $sharedAccounts->get($accountId);
            $share = static fn (?int $amount): ?int => $amount === null || $account === null
                ? $amount
                : $account->shareOfAmount($amount);

            // The carry-forward seeds come first: both are dated before the
            // range, and the range records arrive sorted by date.
            $inRange = $rangeRecords[$accountId] ?? [];
            $seed = $carryForwardRecords[$accountId][0] ?? null;
            $investedSeed = $investedCarryForwardRecords[$accountId][0] ?? null;

            $balanceRows = $seed === null ? $inRange : [$seed, ...$inRange];
            $investedRows = array_values(array_filter(
                $investedSeed === null ? $inRange : [$investedSeed, ...$inRange],
                static fn (stdClass $row): bool => $row->invested_amount !== null,
            ));

            $instance->balancesByAccount[$accountId] = self::seriesOf($balanceRows, 'balance', $share);
            $instance->investedByAccount[$accountId] = self::seriesOf($investedRows, 'invested_amount', $share);
        }

        return $instance;
    }

    /**
     * Plain query rows grouped by account ID, each group in the order the
     * query returned it.
     *
     * @param  Collection<int, stdClass>  $rows
     * @return array<string, list<stdClass>>
     */
    private static function rowsByAccount(Collection $rows): array
    {
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->account_id][] = $row;
        }

        return $grouped;
    }

    /**
     * One account's balance rows as a series of one of their columns, scaled
     * to the owner's share. The columns are NOT NULL, or filtered to non-null
     * rows by the caller, so the cast never turns a missing figure into 0.
     *
     * @param  list<stdClass>  $rows
     * @param  callable(?int): ?int  $share
     */
    private static function seriesOf(array $rows, string $column, callable $share): DatedSeries
    {
        return new DatedSeries(
            array_map(static fn (stdClass $row): string => self::dayOf($row->balance_date), $rows),
            array_map(static fn (stdClass $row): int => (int) $share((int) $row->{$column}), $rows),
        );
    }

    /**
     * The `Y-m-d` day of a raw DATE column. Its first ten characters are the
     * day whatever the driver appends to them, which is what the model's
     * `date` cast used to hand back.
     */
    private static function dayOf(mixed $value): string
    {
        return substr((string) $value, 0, 10);
    }

    /**
     * Get the balance at a given date for an account (carry-forward semantics).
     * Returns the most recent balance on or before the given date, or 0 if none exists.
     */
    public function getBalanceAt(string $accountId, Carbon $date): int
    {
        $balances = $this->balancesByAccount[$accountId] ?? new DatedSeries;

        return $balances->valueOnOrBefore($date->toDateString()) ?? 0;
    }

    /**
     * Get the invested amount at a given date for an account (carry-forward semantics).
     * Returns null if no invested amount data is available on or before the given date.
     */
    public function getInvestedAmountAt(string $accountId, Carbon $date): ?int
    {
        return ($this->investedByAccount[$accountId] ?? new DatedSeries)->valueOnOrBefore($date->toDateString());
    }
}
