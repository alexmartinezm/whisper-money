<?php

namespace App\Http\Controllers\Api;

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\CashflowSummaryService;
use App\Services\CategoryTree;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class CashflowAnalyticsController extends Controller
{
    use ConvertsTransactionCurrency;

    private const MAX_TREND_MONTHS = 24;

    public function __construct(
        private ExchangeRateService $exchangeRateService,
        private CategoryTree $tree,
        private CashflowSummaryService $summaries,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();

        return $this->cashflowJson(
            $this->summaries->forComparedPeriods($user->id, $user->currency_code, $period, $previousPeriod)
        );
    }

    public function sankey(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'parent' => 'nullable|uuid',
        ]);

        $from = Carbon::parse($validated['from']);
        $to = Carbon::parse($validated['to']);
        $user = $request->user();
        $drillParentId = $validated['parent'] ?? null;

        // Split by sign, not by category type: a single category can appear on
        // both sides when it has both incoming and outgoing transactions.
        $incomeCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '>', $drillParentId);
        $expenseCategories = $this->getSankeyBreakdown($user->id, $user->currency_code, $from, $to, '<', $drillParentId);

        $totalIncome = $incomeCategories->sum('amount');
        $totalExpense = $expenseCategories->sum('amount');

        return $this->cashflowJson([
            'income_categories' => $incomeCategories->values(),
            'expense_categories' => $expenseCategories->values(),
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
        ]);
    }

    public function trend(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'months' => 'nullable|integer|min:1|max:'.self::MAX_TREND_MONTHS,
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        $user = $request->user();

        if (isset($validated['from'], $validated['to'])) {
            $start = Carbon::parse($validated['from'])->startOfMonth();
            $end = Carbon::parse($validated['to'])->endOfMonth();
        } else {
            $months = $validated['months'] ?? 12;
            $end = isset($validated['to'])
                ? Carbon::parse($validated['to'])->endOfMonth()
                : Carbon::now()->endOfMonth();
            $start = $end->copy()->subMonthsNoOverflow($months - 1)->startOfMonth();
        }

        // Bound the window to the most recent MAX_TREND_MONTHS months so an
        // unbounded from/to range cannot make the month loop below iterate
        // indefinitely and exhaust the request timeout.
        $earliestStart = $end->copy()->subMonthsNoOverflow(self::MAX_TREND_MONTHS - 1)->startOfMonth();

        if ($start->lt($earliestStart)) {
            $start = $earliestStart;
        }

        $monthlyTotals = $this->getMonthlyTrendTotals($user->id, $user->currency_code, $start, $end);

        $data = [];
        $current = $start->copy();

        while ($current->lte($end)) {
            $monthKey = $current->format('Y-m');
            $totals = $monthlyTotals->get($monthKey);
            $income = (int) ($totals['income'] ?? 0);
            $expense = (int) ($totals['expense'] ?? 0);

            $data[] = [
                'month' => $monthKey,
                'income' => $income,
                'expense' => $expense,
                'net' => $income - $expense,
            ];

            $current->addMonth();
        }

        return $this->cashflowJson([
            'data' => $data,
        ]);
    }

    public function breakdown(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date',
            'type' => 'required|in:income,expense',
            'parent' => 'nullable|uuid',
        ]);

        $period = PeriodComparator::fromRequest($validated);
        $previousPeriod = $period->previous();
        $user = $request->user();
        $drillParentId = $validated['parent'] ?? null;

        $categoryType = $validated['type'] === 'income' ? CategoryType::Income : CategoryType::Expense;

        $current = $this->getCategoryBreakdown($user->id, $user->currency_code, $period->from, $period->to, $categoryType, $drillParentId);
        $previous = $this->getCategoryBreakdown($user->id, $user->currency_code, $previousPeriod->from, $previousPeriod->to, $categoryType, $drillParentId);

        $currentTotal = $current->sum('amount');
        $previousTotal = $previous->sum('amount');

        // Add percentage and previous amount to current
        $currentWithPercentage = $current->map(function ($item) use ($currentTotal, $previous) {
            $previousAmount = $previous->firstWhere('category_id', $item['category_id'])['amount'] ?? 0;

            return [
                'category' => $item['category'],
                'category_id' => $item['category_id'],
                'amount' => $item['amount'],
                'percentage' => $currentTotal > 0 ? round(($item['amount'] / $currentTotal) * 100, 1) : 0,
                'previous_amount' => $previousAmount,
                'has_children' => $item['has_children'] ?? false,
                'is_direct' => $item['is_direct'] ?? false,
            ];
        })->sortByDesc('amount')->values();

        return $this->cashflowJson([
            'data' => $currentWithPercentage,
            'total' => $currentTotal,
            'previous_total' => $previousTotal,
        ]);
    }

    private function cashflowJson(array $data): JsonResponse
    {
        return response()
            ->json($data)
            ->header('Cache-Control', 'no-store, private');
    }

    private function getSankeyBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, string $operator, ?string $drillParentId = null): Collection
    {
        $isIncome = $operator === '>';
        $type = $isIncome ? CategoryType::Income : CategoryType::Expense;
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->countingTowardsTotals()
            ->with(['account', 'category', 'splits.category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);
        $transactions = $this->effectiveTransactions($transactions, $userCurrency);

        // Two populations, one pipeline. A transfer is told apart by its
        // cashflow direction rather than its category type, and its net has to
        // point the same way as the side being built.
        $regularCategories = $this->categoryTotals(
            $transactions,
            $userCurrency,
            fn (Transaction $transaction): bool => $this->belongsToSpendingSide($transaction, $type),
            fn (int $total): bool => $this->categoryNetAmountMatchesSide($total, $type),
        );

        $transferCategories = $this->categoryTotals(
            $transactions,
            $userCurrency,
            fn (Transaction $transaction): bool => $this->isTransferOnSide($transaction, $isIncome),
            fn (int $total): bool => $this->matchesSign($total, $operator),
        );

        $categorized = collect($this->tree->rollUp(
            $regularCategories->concat($transferCategories)->values()->all(),
            $userId,
            $drillParentId,
        ));

        $uncategorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->category_id === null
                && $this->matchesSign($transaction->amount, $operator))
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        // Only on the undrilled view: inside a category, spending with no
        // category of its own is not part of that subtree.
        if ($drillParentId === null && $uncategorized != 0) {
            $categorized->push($this->unknownCategoryNode($isIncome, $uncategorized));
        }

        return $categorized;
    }

    /**
     * Group the transactions a predicate keeps by category and total each one in
     * the user's currency, dropping the categories whose net points the wrong
     * way for the side being built.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @param  callable(Transaction): bool  $keep
     * @param  callable(int): bool  $netMatchesSide
     */
    private function categoryTotals(Collection $transactions, string $userCurrency, callable $keep, callable $netMatchesSide): Collection
    {
        return $transactions
            ->filter($keep)
            ->groupBy('category_id')
            ->map(function (Collection $grouped) use ($userCurrency): array {
                $totalAmount = $grouped->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $grouped->first()->category_id,
                    'category' => $grouped->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $netMatchesSide($item['total_amount']))
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);
    }

    /**
     * Savings and investments are money leaving, so they belong on the expense
     * side alongside expenses proper.
     */
    private function belongsToSpendingSide(Transaction $transaction, CategoryType $type): bool
    {
        if ($transaction->category_id === null) {
            return false;
        }

        $categoryType = $transaction->categoryType();

        if ($categoryType === $type) {
            return true;
        }

        return $type === CategoryType::Expense
            && in_array($categoryType, [CategoryType::Savings, CategoryType::Investment], true);
    }

    private function isTransferOnSide(Transaction $transaction, bool $isIncome): bool
    {
        return $transaction->category_id !== null
            && $transaction->categoryType() === CategoryType::Transfer
            && $this->categoryCashflowDirection($transaction) === ($isIncome
                ? CategoryCashflowDirection::Inflow
                : CategoryCashflowDirection::Outflow);
    }

    /**
     * @return array<string, mixed>
     */
    private function unknownCategoryNode(bool $isIncome, int $uncategorized): array
    {
        return [
            'category_id' => null,
            'category' => (new Category)->forceFill([
                'id' => null,
                'name' => $isIncome ? __('Unknown Income') : __('Unknown Expense'),
                'type' => $isIncome ? CategoryType::Income : CategoryType::Expense,
                'color' => 'gray',
                'icon' => 'HelpCircle',
            ]),
            'amount' => abs($uncategorized),
            'has_children' => false,
            'is_direct' => false,
        ];
    }

    private function getMonthlyTrendTotals(string $userId, string $userCurrency, Carbon $from, Carbon $to): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->countingTowardsTotals()
            ->with(['account', 'category', 'splits.category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);
        $transactions = $this->effectiveTransactions($transactions, $userCurrency);

        return $transactions
            ->groupBy(fn (Transaction $transaction): string => $transaction->transaction_date->format('Y-m'))
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $income = 0;
                $expense = 0;

                $categorized = $transactions
                    ->filter(fn (Transaction $transaction): bool => $transaction->category_id !== null)
                    ->groupBy('category_id');

                foreach ($categorized as $categoryTransactions) {
                    $firstTransaction = $categoryTransactions->first();
                    $type = $firstTransaction->categoryType();

                    if (! in_array($type, [CategoryType::Income, CategoryType::Expense], true)) {
                        continue;
                    }

                    $amount = $categoryTransactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                    if ($this->categoryNetAmountMatchesSide($amount, $type)) {
                        if ($type === CategoryType::Income) {
                            $income += $amount;
                        } else {
                            $expense += abs($amount);
                        }
                    }
                }

                foreach ($transactions->whereNull('category_id') as $transaction) {
                    $amount = $this->convertTransactionAmount($transaction, $userCurrency);

                    if ($transaction->amount > 0) {
                        $income += $amount;
                    }

                    if ($transaction->amount < 0) {
                        $expense += abs($amount);
                    }
                }

                return [
                    'income' => $income,
                    'expense' => $expense,
                ];
            });
    }

    private function getCategoryBreakdown(string $userId, string $userCurrency, Carbon $from, Carbon $to, CategoryType $type, ?string $drillParentId = null): Collection
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->countingTowardsTotals()
            ->with(['account', 'category', 'splits.category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);
        $transactions = $this->effectiveTransactions($transactions, $userCurrency);

        $categorized = $transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->categoryType() === $type)
            ->groupBy('category_id')
            ->map(function (Collection $transactions) use ($userCurrency): array {
                $totalAmount = $transactions->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

                return [
                    'category_id' => $transactions->first()->category_id,
                    'category' => $transactions->first()->category,
                    'amount' => abs($totalAmount),
                    'total_amount' => $totalAmount,
                ];
            })
            ->filter(fn (array $item): bool => $this->categoryNetAmountMatchesSide($item['total_amount'], $type))
            ->map(fn (array $item): array => [
                'category_id' => $item['category_id'],
                'category' => $item['category'],
                'amount' => $item['amount'],
            ]);

        $categorized = collect($this->tree->rollUp($categorized->values()->all(), $userId, $drillParentId));

        $uncategorized = $transactions
            ->filter(function (Transaction $transaction) use ($type): bool {
                return $transaction->category_id === null
                    && $this->matchesSign($transaction->amount, $type === CategoryType::Income ? '>' : '<');
            })
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));

        // Add uncategorized as a special category if there are any
        if ($drillParentId === null && $uncategorized != 0) {
            $categorized->push([
                'category_id' => null,
                'category' => (new Category)->forceFill([
                    'id' => null,
                    'name' => $type === CategoryType::Income ? __('Unknown Income') : __('Unknown Expense'),
                    'type' => $type,
                    'color' => 'gray',
                    'icon' => 'HelpCircle',
                ]),
                'amount' => abs($uncategorized),
                'has_children' => false,
                'is_direct' => false,
            ]);
        }

        return $categorized;
    }

    private function categoryCashflowDirection(Transaction $transaction): ?CategoryCashflowDirection
    {
        $direction = $transaction->category?->getAttribute('cashflow_direction');

        if ($direction instanceof CategoryCashflowDirection) {
            return $direction;
        }

        return is_string($direction) ? CategoryCashflowDirection::tryFrom($direction) : null;
    }

    private function matchesSign(int $amount, string $operator): bool
    {
        return $operator === '>' ? $amount > 0 : $amount < 0;
    }

    private function categoryNetAmountMatchesSide(int $amount, CategoryType $type): bool
    {
        return $type === CategoryType::Income ? $amount > 0 : $amount < 0;
    }
}
