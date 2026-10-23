<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Transaction;
use App\Services\CategoryTree;
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
     * The live budgets, other than catch-alls, that share the catch-all's
     * owner and space and have a period covering the transaction's date: the
     * ones that can take a transaction away from it. Each comes with its
     * categories and labels loaded.
     *
     * Only a budget that can actually take this transaction displaces the
     * catch-all: a budget created later, or one whose periods stop short,
     * would otherwise drop the expense out of every budget there is.
     *
     * @return Collection<int, Budget>
     */
    public function catchAllClaimants(Budget $catchAll, Transaction $transaction): Collection
    {
        $date = $transaction->transaction_date->toDateString();

        return Budget::query()
            ->notArchived()
            ->where('user_id', $catchAll->user_id)
            ->where('space_id', $catchAll->space_id)
            ->where('is_catch_all', false)
            // Compared as plain dates: a Carbon binding serializes with a time
            // ("Y-m-d H:i:s"), which sorts after a stored date-only end_date on
            // any driver that compares them as strings, so the period's own last
            // day would never match.
            ->whereHas('periods', fn ($query) => $query
                ->where('start_date', '<=', $date)
                ->where('end_date', '>=', $date))
            ->with(['categories:id', 'labels:id'])
            ->get();
    }
}
