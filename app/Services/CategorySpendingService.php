<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Transactions\EffectiveTransactionPostings;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CategorySpendingService
{
    public function __construct(
        private CategoryTree $tree,
        private EffectiveTransactionPostings $postings,
    ) {}

    /**
     * Expense spending rolled up the category tree.
     *
     * Without a drill target, child category amounts fold into their root
     * ancestor so only parents are listed. With one, the parent's children
     * become the rows (plus a direct node for transactions sitting on the
     * parent itself). Soft-deleted categories are excluded.
     */
    public function forPeriod(string $userId, Carbon $from, Carbon $to, ?string $drillParentId = null): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            // Joined rather than eager loaded: the percentage is the only column
            // needed, and the join keeps this at the query count it had before
            // ownership existed. It ignores the account's soft-delete scope, so
            // a deleted account keeps its transactions in the totals.
            ->joinOwningAccount()
            ->select('transactions.*', 'accounts.ownership_percentage')
            ->with(['category', 'splits.category'])
            ->get();

        $perCategory = $transactions
            ->flatMap(fn (Transaction $transaction): Collection => $this->postings
                ->forTransaction($transaction)
                ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense)
                ->map(fn ($posting): array => [
                    'category_id' => $posting->categoryId,
                    // A shared account is only the user's at their percentage, and
                    // a split posting is weighed like the transaction it comes from.
                    'amount' => Account::shareOf($posting->amount, $transaction->ownership_percentage),
                ]))
            ->groupBy('category_id')
            ->map(fn (Collection $group, string $categoryId): array => [
                'category_id' => $categoryId,
                'category' => null,
                'amount' => -$group->sum('amount'),
            ])
            ->values()
            ->all();

        return collect($this->tree->rollUp($perCategory, $userId, $drillParentId))
            ->filter(fn (array $item): bool => $item['amount'] > 0)
            ->values();
    }
}
