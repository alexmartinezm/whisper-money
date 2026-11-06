<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\Budgets\BudgetAssignmentLookups;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * @phpstan-type CatchAllChanges array{released: int, adjusted: int, absorbed: int, spent_change: int}
 * @phpstan-type SnapshotChanges array{rows: array<int, array{transaction_id: string, budget_period_id: string, amount: int}>, released_ids: array<int, string>, absorbed: int, adjusted: int, spent_change: int}
 */
class BudgetTransactionService
{
    public function __construct(
        private readonly CategoryTree $tree = new CategoryTree,
        private readonly EffectiveTransactionPostings $postings = new EffectiveTransactionPostings,
        private readonly BudgetNotificationService $notifications = new BudgetNotificationService,
    ) {}

    /**
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransaction(Transaction $transaction, bool $notify = true): void
    {
        $this->assignWithLookups($transaction, $notify, new BudgetAssignmentLookups($this->tree));
    }

    /**
     * {@see self::assignTransaction()} for a batch, reading each user's
     * category tree once for the whole batch instead of once per transaction.
     * Each transaction is still re-read under its own row lock, and the periods
     * covering its date and the budgets that can displace a catch-all are read
     * inside that lock (see {@see BudgetAssignmentLookups}).
     *
     * The lookups only live for this call, so the next batch sees any category
     * re-parented in the meantime.
     *
     * @param  iterable<int, Transaction>  $transactions
     * @param  bool  $notify  set to false for bulk backfills, where the emails
     *                        would describe budget states the user never crossed
     */
    public function assignTransactions(iterable $transactions, bool $notify = true): void
    {
        $lookups = new BudgetAssignmentLookups($this->tree);

        foreach ($transactions as $transaction) {
            $this->assignWithLookups($transaction, $notify, $lookups);
        }
    }

    private function assignWithLookups(Transaction $transaction, bool $notify, BudgetAssignmentLookups $lookups): void
    {
        if (! $transaction->user_id) {
            return;
        }

        $matchingPeriodIds = [];
        $createdPeriodIds = [];

        DB::transaction(function () use ($transaction, $lookups, &$matchingPeriodIds, &$createdPeriodIds): void {
            // Reset per attempt: a deadlock retry re-runs the closure.
            $matchingPeriodIds = [];
            $createdPeriodIds = [];

            $locked = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['labels', 'category', 'splits.category'])
                ->firstOrFail();
            $this->reuseLoadedAccount($locked, $transaction);

            foreach ($lookups->periodsCovering($locked) as $period) {
                $amount = $this->amountForBudget($locked, $period->budget, $lookups);
                if ($amount === 0) {
                    continue;
                }

                $matchingPeriodIds[] = $period->id;
                $assignment = BudgetTransaction::updateOrCreate(
                    ['transaction_id' => $transaction->id, 'budget_period_id' => $period->id],
                    ['amount' => $amount],
                );

                if ($assignment->wasRecentlyCreated) {
                    $createdPeriodIds[] = $period->id;
                }
            }

            BudgetTransaction::query()
                ->where('transaction_id', $transaction->id)
                ->whereHas('budgetPeriod.budget', fn ($query) => $query->whereNull('archived_at'))
                ->when($matchingPeriodIds !== [], fn ($query) => $query->whereNotIn('budget_period_id', $matchingPeriodIds))
                ->delete();
        }, attempts: 5);

        if ($notify) {
            $this->notifications->handleAssignment($transaction, $matchingPeriodIds, $createdPeriodIds);
        }
    }

    /**
     * Rewrite the budget snapshots of an account whose ownership share changed.
     *
     * The amounts were written when each transaction was assigned, so a new
     * share only reaches the budgets already counting this account if something
     * recomputes them. Recomputed rather than rescaled in SQL: a split
     * transaction contributes only the postings a budget tracks, so its
     * snapshot is not a fixed fraction of the transaction's amount.
     *
     * @return int the number of transactions queued for a fresh assignment
     */
    public function reweighAccountSnapshots(Account $account): int
    {
        $transactionIds = BudgetTransaction::query()
            ->join('transactions', 'transactions.id', '=', 'budget_transactions.transaction_id')
            ->where('transactions.account_id', $account->id)
            ->distinct()
            ->pluck('transactions.id')
            ->all();

        if ($transactionIds === []) {
            return 0;
        }

        // Every affected period now holds a different total, so the limit alerts
        // it already sent describe a state that no longer exists. Clearing the
        // flags lets the next crossing notify again, the same way a refund that
        // drops a budget back under its limit does.
        BudgetPeriod::query()
            ->whereHas(
                'budgetTransactions.transaction',
                fn (Builder $query) => $query->where('account_id', $account->id),
            )
            ->update(['close_to_limit_notified' => false, 'over_limit_notified' => false]);

        ReassignTransactionsToBudgets::dispatch($transactionIds, notify: false);

        return count($transactionIds);
    }

    public function unassignTransaction(Transaction $transaction): void
    {
        BudgetTransaction::query()->where('transaction_id', $transaction->id)->delete();
    }

    public function assignHistoricalTransactionsToPeriod(BudgetPeriod $period): int
    {
        $budget = $this->budgetOfPeriod($period);
        if (! $budget) {
            return 0;
        }

        $lookups = new BudgetAssignmentLookups($this->tree);
        $count = 0;
        $this->transactionsOfPeriod($budget, $period)
            ->chunk(500, function (Collection $transactions) use ($period, $budget, $lookups, &$count): void {
                $count += $this->recordSnapshots($transactions, $period, $budget, $lookups);
            });

        return $count;
    }

    /**
     * Bring a catch-all period back in line with what the other budgets count,
     * silently, like every historical assignment. Runs under the period's row
     * lock, the one every other reconciliation of the period takes.
     *
     * A period still running is re-derived whole. A closed one keeps the figure
     * it ran on, so all it can lose is what another budget counts as well: it
     * absorbs nothing, and what it holds is judged by the rows of the other
     * budgets rather than by what they track today, which may not be what they
     * tracked back then.
     *
     * @return CatchAllChanges
     */
    public function rebuildCatchAllPeriod(BudgetPeriod $period): array
    {
        return DB::transaction(function () use ($period): array {
            BudgetPeriod::query()->whereKey($period->id)->lockForUpdate()->first();

            return $this->reconcileCatchAllPeriod($period, write: true);
        }, attempts: 5);
    }

    /**
     * What {@see self::rebuildCatchAllPeriod()} would change, without changing it.
     *
     * @return CatchAllChanges
     */
    public function catchAllDrift(BudgetPeriod $period): array
    {
        return $this->reconcileCatchAllPeriod($period, write: false);
    }

    /**
     * @return CatchAllChanges
     */
    private function reconcileCatchAllPeriod(BudgetPeriod $period, bool $write): array
    {
        $totals = ['released' => 0, 'adjusted' => 0, 'absorbed' => 0, 'spent_change' => 0];
        $catchAll = $this->budgetOfPeriod($period);

        if (! $catchAll?->is_catch_all || $catchAll->isArchived()) {
            return $totals;
        }

        $lookups = new BudgetAssignmentLookups($this->tree);
        $frozenPeriod = $period->hasEnded() ? $period : null;

        $this->transactionsOfPeriod($catchAll, $period)
            ->when($frozenPeriod !== null, fn (Builder $query) => $query
                ->whereHas('budgetTransactions', fn (Builder $rows) => $rows->where('budget_period_id', $period->id))
                ->whereHas('budgetTransactions.budgetPeriod.budget', fn (Builder $budgets) => $budgets->where('is_catch_all', false)))
            ->chunkById(500, function (Collection $transactions) use ($period, $catchAll, $lookups, $frozenPeriod, $write, &$totals): void {
                $changes = $this->snapshotChanges($period, $this->wantedAmounts($transactions, $catchAll, $lookups, $frozenPeriod));

                if ($write) {
                    $this->writeSnapshots($period, $changes);
                }

                $totals['released'] += count($changes['released_ids']);
                $totals['adjusted'] += $changes['adjusted'];
                $totals['absorbed'] += $changes['absorbed'];
                $totals['spent_change'] += $changes['spent_change'];
            });

        return $totals;
    }

    private function budgetOfPeriod(BudgetPeriod $period): ?Budget
    {
        return $period->budget()->with(['categories:id', 'labels:id'])->first();
    }

    /**
     * The transactions dated inside a period, with all a snapshot reads.
     *
     * @return Builder<Transaction>
     */
    private function transactionsOfPeriod(Budget $budget, BudgetPeriod $period): Builder
    {
        return Transaction::query()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->with(['account' => fn ($query) => $query->withTrashed(), 'labels', 'category', 'splits.category']);
    }

    /**
     * Write one period's snapshots for a chunk of transactions in one lookup,
     * one upsert and one delete, rather than all three per transaction.
     *
     * Behaves as updateOrCreate() would row by row: a missing snapshot is
     * inserted, one whose amount changed is refreshed, and one already holding
     * the right amount is left untouched, updated_at included. A transaction
     * the budget no longer counts loses its snapshot. Eloquent's upsert() fills
     * in the ids and timestamps the model would have set.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return int the number of snapshots newly created
     */
    private function recordSnapshots(Collection $transactions, BudgetPeriod $period, Budget $budget, BudgetAssignmentLookups $lookups): int
    {
        $changes = $this->snapshotChanges($period, $this->wantedAmounts($transactions, $budget, $lookups));
        $this->writeSnapshots($period, $changes);

        return $changes['absorbed'];
    }

    /**
     * What each transaction should contribute to a budget. On a catch-all that
     * takes off what another budget already counts, whatever it tracks today,
     * and with $frozenPeriod it starts from what that closed period holds
     * instead of working out what it would hold now.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return SupportCollection<string, int> keyed by transaction id
     */
    private function wantedAmounts(Collection $transactions, Budget $budget, BudgetAssignmentLookups $lookups, ?BudgetPeriod $frozenPeriod = null): SupportCollection
    {
        $holders = $budget->is_catch_all
            ? $lookups->catchAllHolders($budget, $transactions->modelKeys(), onlyEndedPeriods: $frozenPeriod === null)
            : collect();
        $frozenAmounts = $frozenPeriod?->budgetTransactions()
            ->whereIn('transaction_id', $transactions->modelKeys())
            ->pluck('amount', 'transaction_id');

        return $transactions->mapWithKeys(function (Transaction $transaction) use ($budget, $lookups, $holders, $frozenAmounts): array {
            $amount = $frozenAmounts?->get($transaction->id) ?? $this->amountForBudget($transaction, $budget, $lookups);
            $transactionHolders = $holders->get($transaction->id);

            if ($transactionHolders !== null) {
                $unheld = $this->unheldAmount($transaction, $budget, $transactionHolders, $lookups);
                $amount = abs($unheld) < abs($amount) ? $unheld : $amount;
            }

            return [$transaction->id => $amount];
        });
    }

    /**
     * How the snapshots of a period differ from the amounts it should hold.
     *
     * @param  SupportCollection<string, int>  $wantedAmounts  keyed by transaction id
     * @return SnapshotChanges
     */
    private function snapshotChanges(BudgetPeriod $period, SupportCollection $wantedAmounts): array
    {
        $existingAmounts = BudgetTransaction::query()
            ->where('budget_period_id', $period->id)
            ->whereIn('transaction_id', $wantedAmounts->keys()->all())
            ->pluck('amount', 'transaction_id');

        $changes = ['rows' => [], 'released_ids' => [], 'absorbed' => 0, 'adjusted' => 0, 'spent_change' => 0];

        foreach ($wantedAmounts as $transactionId => $amount) {
            $existing = $existingAmounts->get($transactionId);

            if ($existing === $amount || ($existing === null && $amount === 0)) {
                continue;
            }

            $changes['spent_change'] += $amount - (int) $existing;

            if ($amount === 0) {
                $changes['released_ids'][] = $transactionId;

                continue;
            }

            $changes[$existing === null ? 'absorbed' : 'adjusted']++;
            $changes['rows'][] = [
                'transaction_id' => $transactionId,
                'budget_period_id' => $period->id,
                'amount' => $amount,
            ];
        }

        return $changes;
    }

    /**
     * @param  SnapshotChanges  $changes
     */
    private function writeSnapshots(BudgetPeriod $period, array $changes): void
    {
        if ($changes['released_ids'] !== []) {
            BudgetTransaction::query()
                ->where('budget_period_id', $period->id)
                ->whereIn('transaction_id', $changes['released_ids'])
                ->delete();
        }

        BudgetTransaction::upsert($changes['rows'], ['transaction_id', 'budget_period_id'], ['amount']);
    }

    private function amountForBudget(Transaction $transaction, Budget $budget, BudgetAssignmentLookups $lookups): int
    {
        $labelMatch = $budget->labels->pluck('id')->intersect($transaction->labels->pluck('id'))->isNotEmpty();
        if ($labelMatch) {
            return -$this->ownedAmount($transaction, $transaction->amount);
        }

        if ($budget->is_catch_all) {
            $claimants = $lookups->catchAllClaimants($budget, $transaction);

            // An archived claimant holds this transaction, see catchAllClaimants().
            return $claimants->contains(fn (Budget $other): bool => $other->isArchived())
                ? $this->unheldAmount($transaction, $budget, $claimants, $lookups)
                : $this->unclaimedAmount($transaction, $budget, $claimants, $lookups);
        }

        $budgetCategoryIds = $lookups->expand(
            $budget->user_id,
            $budget->categories->pluck('id')->all(),
            $budget->space_id,
        );

        return -$this->postings->forTransaction($transaction)
            ->whereIn('categoryId', $budgetCategoryIds)
            ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
    }

    /**
     * What is left for a catch-all once budgets already counting a transaction
     * have had theirs. An unsplit transaction is one posting, so a budget
     * holding it holds all of it, even if it has moved on to other categories
     * since; a split one is read line by line, as {@see self::unclaimedAmount()}
     * does.
     *
     * @param  SupportCollection<int, Budget>  $holders
     */
    private function unheldAmount(Transaction $transaction, Budget $catchAll, SupportCollection $holders, BudgetAssignmentLookups $lookups): int
    {
        return $transaction->splits->isEmpty()
            ? 0
            : $this->unclaimedAmount($transaction, $catchAll, $holders, $lookups);
    }

    /**
     * What a catch-all takes of a transaction: nothing when a label claims all
     * of it, otherwise the expense postings none of $claimants tracks.
     *
     * @param  SupportCollection<int, Budget>  $claimants
     */
    private function unclaimedAmount(Transaction $transaction, Budget $catchAll, SupportCollection $claimants, BudgetAssignmentLookups $lookups): int
    {
        $transactionLabelIds = $transaction->labels->pluck('id');

        $claimedByLabel = $claimants->contains(
            fn (Budget $other): bool => $other->labels->pluck('id')->intersect($transactionLabelIds)->isNotEmpty(),
        );
        if ($claimedByLabel) {
            return 0;
        }

        $claimed = $lookups->expand(
            $catchAll->user_id,
            $claimants
                ->flatMap(fn (Budget $other): mixed => $other->categories->pluck('id'))
                ->unique()
                ->all(),
            $catchAll->space_id,
        );

        return -$this->postings->forTransaction($transaction)
            ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense && ! in_array($posting->categoryId, $claimed, true))
            ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
    }

    /**
     * Hand the locked copy the account the caller already loaded, so a job that
     * eager loads a chunk's accounts does not pay one lookup per row again. The
     * row lock does not guard the account, so reading it before the lock is no
     * staler than reading it after. A relation loaded as null (a trashed account
     * read without withTrashed()) is not trusted: the copy loads its own.
     */
    private function reuseLoadedAccount(Transaction $locked, Transaction $loaded): void
    {
        $account = $loaded->relationLoaded('account') ? $loaded->account : null;

        if ($account !== null && $account->id === $locked->account_id) {
            $locked->setRelation('account', $account);

            return;
        }

        $locked->load(['account' => fn ($query) => $query->withTrashed()]);
    }

    /**
     * The owner's slice of an amount on the transaction's account. A budget is
     * one person's plan, so a jointly held account only contributes the share
     * that person owns. Loaded with trashed accounts so a deleted one still
     * weighs its transactions instead of dropping them to face value.
     */
    private function ownedAmount(Transaction $transaction, int $amount): int
    {
        $transaction->loadMissing(['account' => fn ($query) => $query->withTrashed()]);

        return Account::shareOf($amount, $transaction->account?->ownership_percentage);
    }
}
