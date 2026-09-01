<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

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
        if (! $transaction->user_id) {
            return;
        }

        $matchingPeriodIds = [];
        $createdPeriodIds = [];

        DB::transaction(function () use ($transaction, &$matchingPeriodIds, &$createdPeriodIds): void {
            // Reset per attempt: a deadlock retry re-runs the closure.
            $matchingPeriodIds = [];
            $createdPeriodIds = [];

            $locked = Transaction::query()
                ->whereKey($transaction->id)
                ->lockForUpdate()
                ->with(['account' => fn ($query) => $query->withTrashed(), 'labels', 'category', 'splits.category'])
                ->firstOrFail();
            $periods = BudgetPeriod::query()
                // An archived budget stops taking in new data from the day it
                // was archived; what it already counted stays as it was.
                // Spelled out rather than via Budget::scopeNotArchived: inside
                // a whereHas closure the builder is not typed to the model.
                ->whereHas('budget', fn ($query) => $query
                    ->whereNull('archived_at')
                    ->where('user_id', $locked->user_id)
                    ->where('space_id', $locked->space_id))
                ->where('start_date', '<=', $locked->transaction_date)
                ->where('end_date', '>=', $locked->transaction_date)
                ->with(['budget.categories:id', 'budget.labels:id'])
                ->get();

            foreach ($periods as $period) {
                $amount = $this->amountForBudget($locked, $period->budget);
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
        $budget = $period->budget()->with(['categories:id', 'labels:id'])->first();
        if (! $budget) {
            return 0;
        }

        $count = 0;
        Transaction::query()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->whereBetween('transaction_date', [$period->start_date, $period->end_date])
            ->with(['account' => fn ($query) => $query->withTrashed(), 'labels', 'category', 'splits.category'])
            ->chunk(500, function ($transactions) use ($period, $budget, &$count): void {
                foreach ($transactions as $transaction) {
                    $amount = $this->amountForBudget($transaction, $budget);
                    if ($amount === 0) {
                        BudgetTransaction::query()->where([
                            'transaction_id' => $transaction->id,
                            'budget_period_id' => $period->id,
                        ])->delete();

                        continue;
                    }

                    $assignment = BudgetTransaction::updateOrCreate(
                        ['transaction_id' => $transaction->id, 'budget_period_id' => $period->id],
                        ['amount' => $amount],
                    );
                    if ($assignment->wasRecentlyCreated) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function amountForBudget(Transaction $transaction, Budget $budget): int
    {
        $labelMatch = $budget->labels->pluck('id')->intersect($transaction->labels->pluck('id'))->isNotEmpty();
        if ($labelMatch) {
            return -$this->ownedAmount($transaction, $transaction->amount);
        }

        $budgetCategoryIds = $this->tree->expand(
            $budget->user_id,
            $budget->categories->pluck('id')->all(),
            $budget->space_id,
        );
        $postings = $this->postings->forTransaction($transaction);

        if (! $budget->is_catch_all) {
            return -$postings
                ->whereIn('categoryId', $budgetCategoryIds)
                ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
        }

        // Only a budget that can actually take this transaction displaces the
        // catch-all: one whose period covers the transaction's date. A budget
        // created later, or one whose periods stop short, would otherwise drop
        // the expense out of every budget there is.
        $claimants = Budget::query()
            ->notArchived()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->where('is_catch_all', false)
            ->whereHas('periods', fn ($query) => $query
                ->where('start_date', '<=', $transaction->transaction_date)
                ->where('end_date', '>=', $transaction->transaction_date));

        $claimedByLabel = (clone $claimants)
            ->whereHas('labels', fn ($query) => $query->whereIn('labels.id', $transaction->labels->pluck('id')))
            ->exists();
        if ($claimedByLabel) {
            return 0;
        }

        $claimed = $this->tree->expand(
            $budget->user_id,
            $claimants
                ->with('categories:id')
                ->get()
                ->flatMap(fn (Budget $other): mixed => $other->categories->pluck('id'))
                ->unique()
                ->all(),
            $budget->space_id,
        );

        return -$postings
            ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense && ! in_array($posting->categoryId, $claimed, true))
            ->sum(fn ($posting): int => $this->ownedAmount($transaction, $posting->amount));
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
