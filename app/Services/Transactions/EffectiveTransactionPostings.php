<?php

namespace App\Services\Transactions;

use App\Data\EffectiveTransactionPosting;
use App\Models\Transaction;
use Illuminate\Support\Collection;

class EffectiveTransactionPostings
{
    /** @return Collection<int, EffectiveTransactionPosting> */
    public function forTransaction(Transaction $transaction): Collection
    {
        $transaction->loadMissing(['category', 'splits.category']);

        if ($transaction->splits->isNotEmpty()) {
            return $transaction->splits->map(fn ($split): EffectiveTransactionPosting => new EffectiveTransactionPosting(
                $transaction,
                $split->category,
                $split->category_id,
                $split->amount,
                true,
            ));
        }

        return collect([new EffectiveTransactionPosting(
            $transaction,
            $transaction->category,
            $transaction->category_id,
            $transaction->amount,
            false,
        )]);
    }

    /** @param Collection<int, Transaction> $transactions
     * @return Collection<int, EffectiveTransactionPosting>
     */
    public function forTransactions(Collection $transactions): Collection
    {
        if ($transactions instanceof \Illuminate\Database\Eloquent\Collection) {
            $transactions->loadMissing(['category', 'splits.category']);
        }

        return $transactions->flatMap(fn (Transaction $transaction): Collection => $this->forTransaction($transaction))->values();
    }
}
