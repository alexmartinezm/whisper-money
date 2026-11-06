<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * What assigning transactions to budgets reads over and over. Each user's
 * category tree is read once per batch instead of once per transaction; the
 * budget periods and catch-all claimants are read per transaction, inside its
 * row lock, because the assignment deletes the snapshots of every period they
 * leave out: a period or tracking change made by another job while the batch
 * runs must not be read stale and have its fresh snapshots deleted.
 *
 * One instance lives for one batch only, so the next batch sees any category
 * re-parented in the meantime.
 */
class BudgetAssignmentLookups
{
    /** @var array<string, array<string, ?string>> */
    private array $parentMaps = [];

    public function __construct(private readonly CategoryTree $tree) {}

    /**
     * {@see CategoryTree::expand()} over the user's tree as this batch read it.
     *
     * @param  array<int, string>  $categoryIds
     * @return array<int, string>
     */
    public function expand(string $userId, array $categoryIds, ?string $spaceId): array
    {
        if (array_filter($categoryIds) === []) {
            return [];
        }

        $parentMap = $this->parentMaps[$userId.'|'.$spaceId] ??= $this->tree->parentMap($userId, $spaceId);

        return $this->tree->expand($userId, $categoryIds, $spaceId, $parentMap);
    }

    /**
     * The periods of the owner's live budgets in the transaction's space that
     * cover its date, with each budget's categories and labels loaded.
     *
     * @return Collection<int, BudgetPeriod>
     */
    public function periodsCovering(Transaction $transaction): Collection
    {
        return BudgetPeriod::query()
            // An archived budget stops taking in new data from the day it
            // was archived; what it already counted stays as it was.
            // Spelled out rather than via Budget::scopeNotArchived: inside
            // a whereHas closure the builder is not typed to the model.
            ->whereHas('budget', fn ($query) => $query
                ->whereNull('archived_at')
                ->where('user_id', $transaction->user_id)
                ->where('space_id', $transaction->space_id))
            ->where('start_date', '<=', $transaction->transaction_date)
            ->where('end_date', '>=', $transaction->transaction_date)
            ->with(['budget.categories:id', 'budget.labels:id'])
            ->get();
    }

    /**
     * The budgets that take a transaction away from a catch-all as it is being
     * assigned on its own: the live ones sharing its owner and space with a
     * period covering the transaction's date, and the archived ones still
     * holding the transaction. Each comes with its categories and labels loaded.
     *
     * A closed period counts here because the same assignment writes to it, see
     * {@see self::periodsCovering()}: the budget is about to hold what it claims.
     * A backfill writes one period only, so it reads
     * {@see self::runningClaimants()} and the rows other budgets hold instead.
     *
     * Judged by the transaction's date and not by whether a budget overlaps the
     * catch-all period, as upstream does: only a budget that can actually take
     * this transaction displaces the catch-all. A budget created later, or one
     * whose periods stop short, would otherwise drop the expense out of every
     * budget there is.
     *
     * An archived budget stopped taking anything in but keeps what it counted,
     * so it claims exactly that: anything else it used to track is the
     * catch-all's, and what it holds must not be counted a second time.
     *
     * @return Collection<int, Budget>
     */
    public function catchAllClaimants(Budget $catchAll, Transaction $transaction): Collection
    {
        $date = $transaction->transaction_date->toDateString();

        return $this->otherBudgets($catchAll)
            ->where(fn (Builder $claimants) => $claimants
                ->where(fn (Builder $live) => $live
                    ->whereNull('archived_at')
                    ->whereHas('periods', fn ($query) => $query
                        ->where('start_date', '<=', $date)
                        ->where('end_date', '>=', $date)))
                ->orWhere(fn (Builder $archived) => $archived
                    ->whereNotNull('archived_at')
                    ->whereHas('periods.budgetTransactions', fn ($query) => $query
                        ->where('transaction_id', $transaction->id))))
            ->get();
    }

    /**
     * The live budgets with a period still running that covers the
     * transaction's date. Only those can be expected to hold what they track
     * today: a closed period keeps the rows of the tracking it ran on, so it
     * claims what it holds and nothing else.
     *
     * @return Collection<int, Budget>
     */
    public function runningClaimants(Budget $catchAll, Transaction $transaction): Collection
    {
        $date = $transaction->transaction_date->toDateString();

        return $this->otherBudgets($catchAll)
            ->whereNull('archived_at')
            ->whereHas('periods', fn ($query) => $query
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date)
                ->where('end_date', '>=', today()->toDateString()))
            ->get();
    }

    /**
     * The rows other budgets hold for each transaction, whatever those budgets
     * track today, with each row's period and budget loaded. Deleted budgets
     * count nothing any more, so they are left out.
     *
     * @param  array<int, string>  $transactionIds
     * @return array<array-key, Collection<int, BudgetTransaction>> keyed by transaction id
     */
    public function rowsHeldElsewhere(Budget $catchAll, array $transactionIds): array
    {
        return BudgetTransaction::query()
            ->whereIn('transaction_id', $transactionIds)
            ->whereHas('budgetPeriod.budget', fn ($query) => $query
                ->where('user_id', $catchAll->user_id)
                ->where('space_id', $catchAll->space_id)
                ->where('is_catch_all', false))
            ->with(['budgetPeriod.budget.categories:id', 'budgetPeriod.budget.labels:id'])
            ->get()
            ->groupBy('transaction_id')
            ->all();
    }

    /**
     * The budgets, other than catch-alls, sharing a catch-all's owner and space.
     *
     * Dates in the queries built on this are compared as plain strings: a
     * Carbon binding serializes with a time ("Y-m-d H:i:s"), which sorts after a
     * stored date-only end_date on any driver that compares them as strings, so
     * the period's own last day would never match.
     *
     * @return Builder<Budget>
     */
    private function otherBudgets(Budget $catchAll): Builder
    {
        return Budget::query()
            ->where('user_id', $catchAll->user_id)
            ->where('space_id', $catchAll->space_id)
            ->where('is_catch_all', false)
            ->with(['categories:id', 'labels:id']);
    }
}
