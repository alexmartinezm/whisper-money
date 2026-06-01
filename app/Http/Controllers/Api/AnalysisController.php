<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AnalysisRequest;
use App\Models\Transaction;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class AnalysisController extends Controller
{
    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function index(AnalysisRequest $request): JsonResponse
    {
        $user = $request->user();
        $userCurrency = $user->currency_code;
        $groupBy = $request->validated()['group_by'];

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->with(['account:id,currency_code', 'category:id,name,icon,color,type', 'labels:id,name,color'])
            ->applyFilters($request->filters())
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $converted = $transactions->map(fn (Transaction $transaction): array => [
            'transaction' => $transaction,
            'amount' => $this->convertTransactionAmount($transaction, $userCurrency),
        ]);

        return response()
            ->json([
                'summary' => $this->summary($converted),
                'groups' => $this->groups($converted, $groupBy)->values(),
            ])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * @param  Collection<int, array{transaction: Transaction, amount: int}>  $converted
     * @return array{income: int, expense: int, net: int, count: int}
     */
    private function summary(Collection $converted): array
    {
        $income = $converted->filter(fn (array $row): bool => $row['amount'] > 0)->sum('amount');
        $expense = abs($converted->filter(fn (array $row): bool => $row['amount'] < 0)->sum('amount'));

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'count' => $converted->count(),
        ];
    }

    /**
     * @param  Collection<int, array{transaction: Transaction, amount: int}>  $converted
     * @return Collection<int, array{key: ?string, amount: int, count: int}>
     */
    private function groups(Collection $converted, string $groupBy): Collection
    {
        if ($groupBy === 'label') {
            return $this->labelGroups($converted);
        }

        return $converted
            ->groupBy(fn (array $row): string => $this->groupKey($row['transaction'], $groupBy) ?? '__null__')
            ->map(fn (Collection $rows, string $key): array => [
                'key' => $key === '__null__' ? null : $key,
                'amount' => $rows->sum('amount'),
                'count' => $rows->count(),
            ])
            ->sortByDesc(fn (array $group): int => abs($group['amount']));
    }

    /**
     * A transaction with several labels contributes to each label's bucket.
     * Transactions without labels fall into a single null bucket.
     *
     * @param  Collection<int, array{transaction: Transaction, amount: int}>  $converted
     * @return Collection<int, array{key: ?string, amount: int, count: int}>
     */
    private function labelGroups(Collection $converted): Collection
    {
        $buckets = [];

        foreach ($converted as $row) {
            $labels = $row['transaction']->labels;

            if ($labels->isEmpty()) {
                $buckets['__null__'] ??= ['key' => null, 'amount' => 0, 'count' => 0];
                $buckets['__null__']['amount'] += $row['amount'];
                $buckets['__null__']['count']++;

                continue;
            }

            foreach ($labels as $label) {
                $buckets[$label->id] ??= ['key' => $label->id, 'amount' => 0, 'count' => 0];
                $buckets[$label->id]['amount'] += $row['amount'];
                $buckets[$label->id]['count']++;
            }
        }

        return collect(array_values($buckets))
            ->sortByDesc(fn (array $group): int => abs($group['amount']));
    }

    private function groupKey(Transaction $transaction, string $groupBy): ?string
    {
        return match ($groupBy) {
            'category' => $transaction->category_id,
            'account' => $transaction->account_id,
            'month' => $transaction->transaction_date->format('m'),
            default => null,
        };
    }

    private function convertTransactionAmount(Transaction $transaction, string $userCurrency): int
    {
        return $this->exchangeRateService->convert(
            $transaction->currency_code ?: $transaction->account?->currency_code ?: $userCurrency,
            $userCurrency,
            $transaction->amount,
            $transaction->transaction_date->toDateString(),
        );
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function preloadExchangeRates(Collection $transactions, string $userCurrency): void
    {
        $dates = $transactions
            ->filter(fn (Transaction $transaction): bool => strcasecmp($transaction->currency_code ?: $transaction->account?->currency_code ?: $userCurrency, $userCurrency) !== 0)
            ->map(fn (Transaction $transaction): string => $transaction->transaction_date->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        $this->exchangeRateService->preloadRates($userCurrency, $dates);
    }
}
