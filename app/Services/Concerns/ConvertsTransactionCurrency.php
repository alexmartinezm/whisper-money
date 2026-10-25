<?php

namespace App\Services\Concerns;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\ExchangeRateService;
use Illuminate\Support\Collection;

/**
 * Shared currency conversion for whoever adds up transactions. Each consumer
 * injects an {@see ExchangeRateService} as `$exchangeRateService`, then reads
 * transaction amounts in the user's currency through these helpers.
 */
trait ConvertsTransactionCurrency
{
    /**
     * The transaction amount in the user's currency, reduced to their share of
     * the account. Requires the `account` relation to be eager loaded.
     */
    protected function convertTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $transaction->ownerShareOf($this->convertFullTransactionAmount($transaction, $currency));
    }

    /**
     * The whole transaction amount in $currency, ignoring the owner's share of
     * the account. Requires the `account` relation to be eager loaded.
     */
    protected function convertFullTransactionAmount(Transaction $transaction, string $currency): int
    {
        return $this->exchangeRateService->convert(
            $this->sourceCurrency($transaction->currency_code, $transaction->account, $currency),
            $currency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    /**
     * The currency an amount is booked in: the transaction's own, else its
     * account's, else the user's.
     */
    protected function sourceCurrency(?string $transactionCurrency, ?Account $account, string $currency): string
    {
        return $transactionCurrency ?: $account?->currency_code ?: $currency;
    }

    /**
     * The signed total of a set of transactions in the user's currency.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function sumConvertedAmounts(Collection $transactions, string $currency): int
    {
        return $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $currency));
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    protected function preloadExchangeRates(Collection $transactions, string $currency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($this->sourceCurrency($transaction->currency_code, $transaction->account, $currency), $currency) !== 0)
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
     * Amounts here are converted but NOT reduced to the owner's share: every
     * caller sums the postings through {@see self::convertTransactionAmount()},
     * which applies the share itself. Weighing the parent here as well charged
     * a shared account's split rows the percentage twice — a 50%-owned account
     * counted a split €100 as €25.
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

            $convertedParent = $this->exchangeRateService->convert(
                $this->sourceCurrency($transaction->currency_code, $transaction->account, $currency),
                $currency,
                $transaction->amount,
                $transaction->transaction_date->toDateString(),
            );
            $allocated = 0;
            $lastPosition = $transaction->splits->count() - 1;

            return $transaction->splits->values()->map(function (TransactionSplit $split, int $position) use ($transaction, $currency, $convertedParent, &$allocated, $lastPosition): Transaction {
                $amount = $position === $lastPosition
                    ? $convertedParent - $allocated
                    : $this->exchangeRateService->convert(
                        $this->sourceCurrency($transaction->currency_code, $transaction->account, $currency),
                        $currency,
                        $split->amount,
                        $transaction->transaction_date->toDateString(),
                    );
                $allocated += $amount;

                return $transaction->splitPosting($split, $amount, $currency);
            })->all();
        })->values();
    }
}
