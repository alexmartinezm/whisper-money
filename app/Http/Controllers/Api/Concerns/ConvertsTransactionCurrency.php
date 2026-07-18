<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Support\Collection;

/**
 * Shared currency conversion for the analytics controllers. Each consumer
 * injects an {@see ExchangeRateService} as `$exchangeRateService`, then reads
 * transaction amounts in the user's currency through these helpers.
 */
trait ConvertsTransactionCurrency
{
    protected function convertTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $currency,
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function preloadExchangeRates(Collection $transactions, string $currency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($transaction->currency_code ?: $transaction->account?->currency_code ?: $currency, $currency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($currency, $dates);
    }

    /**
     * Expand split parents into category-bearing clones. Converted line amounts
     * reconcile to the converted parent; final line receives rounding residue.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, Transaction>
     */
    protected function effectiveTransactions(Collection $transactions, string $currency): Collection
    {
        return $transactions->flatMap(function (Transaction $transaction) use ($currency): array {
            $transaction->loadMissing(['category', 'splits.category']);
            if ($transaction->splits->isEmpty()) {
                return [$transaction];
            }

            $convertedParent = $this->convertTransactionAmount($transaction, $currency);
            $allocated = 0;
            $lastPosition = $transaction->splits->count() - 1;

            return $transaction->splits->values()->map(function ($split, int $position) use ($transaction, $currency, $convertedParent, &$allocated, $lastPosition): Transaction {
                $amount = $position === $lastPosition
                    ? $convertedParent - $allocated
                    : $this->exchangeRateService->convert(
                        $transaction->currency_code ?: $transaction->account?->currency_code ?: $currency,
                        $currency,
                        $split->amount,
                        $transaction->transaction_date->toDateString(),
                    );
                $allocated += $amount;

                $posting = clone $transaction;
                $posting->forceFill(['amount' => $amount, 'currency_code' => $currency, 'category_id' => $split->category_id]);
                $posting->setRelation('category', $split->category);
                $posting->setRelation('splits', collect());

                return $posting;
            })->all();
        })->values();
    }
}
