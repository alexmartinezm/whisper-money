<?php

namespace App\Http\Controllers;

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\CashflowSummaryService;
use App\Services\CategorySpendingService;
use App\Services\PeriodComparator;
use App\Services\Transactions\EffectiveTransactionPostings;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private AccountMetricsService $accountMetricsService,
        private CategorySpendingService $categorySpendingService,
        private EffectiveTransactionPostings $effectivePostings,
    ) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('dashboard', [
            'showEncryptionPrompt' => session('show_encryption_prompt', false),
            'netWorthEvolution' => Inertia::defer(fn () => $this->getNetWorthEvolution($request), 'dashboard'),
            'topCategories' => Inertia::defer(fn () => $this->getTopCategories($request), 'dashboard'),
            'cashflowSummary' => Inertia::defer(fn () => $this->getCashflowSummary($request), 'dashboard'),
        ]);
    }

    private function getNetWorthEvolution(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $start = $now->copy()->subMonths(12);
        $end = $now->copy();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo', 'realEstateDetail:account_id,linked_loan_account_id'])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return $this->accountMetricsService->getNetWorthEvolution($user->currency_code, $accounts, $start, $end);
    }

    private function getTopCategories(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $from = $now->copy()->subDays(30);
        $to = $now->copy();

        $period = new PeriodComparator($from, $to);
        $previousPeriod = $period->previous();

        $currentSpending = $this->categorySpendingService->forPeriod($user->id, $period->from, $period->to);
        $previousSpending = $this->categorySpendingService->forPeriod($user->id, $previousPeriod->from, $previousPeriod->to);

        $totalAmount = $currentSpending->sum('amount');

        return $currentSpending
            ->sortByDesc('amount')
            ->take(10)
            ->map(function ($item) use ($previousSpending, $totalAmount) {
                $previousAmount = $previousSpending->firstWhere('category_id', $item['category_id'])['amount'] ?? 0;

                return [
                    'category' => $item['category'],
                    'category_id' => $item['category_id'],
                    'amount' => $item['amount'],
                    'previous_amount' => $previousAmount,
                    'total_amount' => $totalAmount,
                    'has_children' => $item['has_children'],
                    'is_direct' => $item['is_direct'],
                ];
            })
            ->values()
            ->all();
    }

    private function getCashflowSummary(Request $request): array
    {
        $user = $request->user();
        $now = Carbon::now();
        $from = $now->copy()->startOfMonth();
        $to = $now->copy()->endOfMonth();

        $period = new PeriodComparator($from, $to);
        $previousPeriod = $period->previous();

        return [
            'current' => $this->calculateCashflowSummary($user->id, $period->from, $period->to),
            'previous' => $this->calculateCashflowSummary($user->id, $previousPeriod->from, $previousPeriod->to),
        ];
    }

    private function calculateCashflowSummary(string $userId, Carbon $from, Carbon $to): array
    {
        $income = max(0, $this->getTransactionSum($userId, $from, $to, CategoryType::Income));
        $expense = max(0, -$this->getTransactionSum($userId, $from, $to, CategoryType::Expense));

        return CashflowSummaryService::summarize($income, $expense);
    }

    private function getTransactionSum(string $userId, Carbon $from, Carbon $to, CategoryType $type): int
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from, $to])
            ->with(['category', 'splits.category'])
            ->get();

        return $this->effectivePostings->forTransactions($transactions)
            ->filter(function ($posting) use ($type): bool {
                if ($posting->category !== null) {
                    return $posting->category->type === $type;
                }

                return $type === CategoryType::Income
                    ? $posting->amount > 0
                    : $posting->amount < 0;
            })
            ->sum('amount');
    }
}
