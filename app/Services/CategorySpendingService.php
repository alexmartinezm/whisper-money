<?php

namespace App\Services;

use App\Enums\CategoryType;
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

    public function forPeriod(string $userId, Carbon $from, Carbon $to, ?string $drillParentId = null): Collection
    {
        $transactions = Transaction::query()
            ->where('user_id', $userId)
            ->whereBetween('transaction_date', [$from, $to])
            ->with(['category', 'splits.category'])
            ->get();

        $perCategory = $this->postings->forTransactions($transactions)
            ->filter(fn ($posting): bool => $posting->category?->type === CategoryType::Expense)
            ->groupBy('categoryId')
            ->map(fn (Collection $group): array => [
                'category_id' => $group->first()->categoryId,
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
