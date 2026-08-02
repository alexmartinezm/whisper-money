<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Transaction;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Support\Facades\DB;

class BudgetTransactionService
{
    public function __construct(
        private readonly CategoryTree $tree = new CategoryTree,
        private readonly EffectiveTransactionPostings $postings = new EffectiveTransactionPostings,
        private readonly BudgetNotificationService $notifications = new BudgetNotificationService,
    ) {}

    public function assignTransaction(Transaction $transaction): void
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
                ->with(['labels', 'category', 'splits.category'])
                ->firstOrFail();
            $periods = BudgetPeriod::query()
                ->whereHas('budget', fn ($query) => $query
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

        $this->notifications->handleAssignment($transaction, $matchingPeriodIds, $createdPeriodIds);
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
            ->with(['labels', 'category', 'splits.category'])
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
            return -$transaction->amount;
        }

        $budgetCategoryIds = $this->tree->expand($budget->user_id, $budget->categories->pluck('id')->all());
        $postings = $this->postings->forTransaction($transaction);

        if (! $budget->is_catch_all) {
            return -$postings->whereIn('categoryId', $budgetCategoryIds)->sum('amount');
        }

        $claimedByLabel = Budget::query()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->where('is_catch_all', false)
            ->whereHas('labels', fn ($query) => $query->whereIn('labels.id', $transaction->labels->pluck('id')))
            ->exists();
        if ($claimedByLabel) {
            return 0;
        }

        $claimed = $this->tree->expand($budget->user_id, Budget::query()
            ->where('user_id', $budget->user_id)
            ->where('space_id', $budget->space_id)
            ->where('is_catch_all', false)
            ->with('categories:id')
            ->get()
            ->flatMap(fn (Budget $other): mixed => $other->categories->pluck('id'))
            ->unique()
            ->all());

        return -$postings
            ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense && ! in_array($posting->categoryId, $claimed, true))
            ->sum('amount');
    }
}
