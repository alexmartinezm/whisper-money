<?php

namespace App\Http\Controllers;

use App\Enums\BudgetPeriodType;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Services\BudgetPeriodService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected BudgetPeriodService $budgetPeriodService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $budgets = $user
            ->budgets()
            ->with(['categories', 'labels', 'periods' => function ($query) {
                $query->where('start_date', '<=', today())
                    ->where('end_date', '>=', today())
                    ->withSum('budgetTransactions as spent_amount', 'amount');
            }])
            ->get();

        return Inertia::render('budgets/index', [
            'budgets' => $budgets,
            'budgetSummary' => $this->buildBudgetSummary($budgets),
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    /**
     * Build the overview from the same active periods shown by the index.
     * Catch-all budgets stay separate so they are not double-counted as an
     * additional allocation on top of category/label budgets.
     */
    private function buildBudgetSummary(Collection $budgets): array
    {
        $activeBudgets = $budgets
            ->map(function (Budget $budget): ?array {
                $period = $budget->periods->first();

                if (! $period) {
                    return null;
                }

                $allocated = (int) $period->allocated_amount;
                $carriedOver = (int) ($period->carried_over_amount ?? 0);
                $available = $allocated + $carriedOver;
                $spent = (int) ($period->spent_amount ?? 0);
                $status = $period->limitStatus($spent);
                $percentageUsed = $available > 0
                    ? round(($spent / $available) * 100, 1)
                    : 0;

                $period->setAttribute('status', $status);

                return [
                    'period_type' => $budget->period_type->value,
                    'is_catch_all' => (bool) $budget->is_catch_all,
                    'allocated_amount' => $allocated,
                    'carried_over_amount' => $carriedOver,
                    'available_amount' => $available,
                    'spent_amount' => $spent,
                    'remaining_amount' => $available - $spent,
                    'percentage_used' => $percentageUsed,
                    'status' => $status,
                ];
            })
            ->filter()
            ->values();

        $specificBudgets = $activeBudgets->where('is_catch_all', false)->values();
        $catchAllBudgets = $activeBudgets->where('is_catch_all', true)->values();
        $periodTypeOrder = collect(BudgetPeriodType::cases())
            ->mapWithKeys(fn (BudgetPeriodType $periodType, int $index): array => [
                $periodType->value => $index,
            ]);
        $groups = $specificBudgets
            ->groupBy('period_type')
            ->map(function (Collection $group, string $periodType): array {
                return ['period_type' => $periodType] + $this->aggregateBudgetStats($group);
            })
            ->sortBy(fn (array $group): int => $periodTypeOrder[$group['period_type']] ?? PHP_INT_MAX)
            ->values()
            ->all();

        return $this->aggregateBudgetStats($specificBudgets) + [
            'groups' => $groups,
            'catch_all' => $catchAllBudgets->isEmpty()
                ? null
                : $this->aggregateBudgetStats($catchAllBudgets),
        ];
    }

    private function aggregateBudgetStats(Collection $budgets): array
    {
        $totalAllocated = (int) $budgets->sum('allocated_amount');
        $totalCarriedOver = (int) $budgets->sum('carried_over_amount');
        $totalAvailable = (int) $budgets->sum('available_amount');
        $totalSpent = (int) $budgets->sum('spent_amount');

        return [
            'budgets_count' => $budgets->count(),
            'total_allocated' => $totalAllocated,
            'total_carried_over' => $totalCarriedOver,
            'total_available' => $totalAvailable,
            'total_spent' => $totalSpent,
            'total_remaining' => $totalAvailable - $totalSpent,
            'percentage_used' => $totalAvailable > 0
                ? round(($totalSpent / $totalAvailable) * 100, 1)
                : 0,
            'status' => BudgetPeriod::limitStatusFor($totalSpent, $totalAvailable),
            'over_limit_count' => $budgets
                ->where('status', 'over_limit')
                ->count(),
            'close_to_limit_count' => $budgets
                ->where('status', 'close_to_limit')
                ->count(),
        ];
    }

    public function show(Request $request, Budget $budget): Response
    {
        $this->authorize('view', $budget);

        $user = $request->user();

        // If a specific period UUID is requested, load it (scoped to this budget, past/current only)
        $periodId = $request->query('period');
        if ($periodId) {
            $viewedPeriod = $budget->periods()
                ->where('id', $periodId)
                ->where('start_date', '<=', today())
                ->firstOrFail();
        } else {
            $viewedPeriod = $budget->getCurrentPeriod();

            if (! $viewedPeriod) {
                $viewedPeriod = $this->budgetPeriodService->generatePeriod($budget);
            }
        }

        $viewedPeriod->load([
            'budgetTransactions.transaction.account.bank',
            'budgetTransactions.transaction.category',
            'budgetTransactions.transaction.labels',
            'budgetTransactions.transaction.splits.category',
        ]);

        $previousPeriod = $budget->periods()
            ->where('end_date', '<', $viewedPeriod->start_date)
            ->orderBy('end_date', 'desc')
            ->with(['budgetTransactions.transaction'])
            ->first();

        $nextPeriod = $budget->periods()
            ->where('start_date', '>', $viewedPeriod->end_date)
            ->where('start_date', '<=', today())
            ->orderBy('start_date', 'asc')
            ->first();

        $budget->load(['categories', 'labels']);

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        return Inertia::render('budgets/show', [
            'budget' => $budget,
            'currentPeriod' => $viewedPeriod,
            'previousPeriod' => $previousPeriod,
            'nextPeriod' => $nextPeriod,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $result = DB::transaction(function () use ($request) {
            $setting = $request->user()->setting;

            $budget = $request->user()->budgets()->create([
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_start_day' => $request->period_start_day,
                'rollover_type' => $request->rollover_type,
                'is_catch_all' => $request->boolean('is_catch_all'),
                'notify_on_new_transaction' => $setting->budget_notify_on_new_transaction ?? false,
                'notify_on_close_to_limit' => $setting->budget_notify_on_close_to_limit ?? true,
                'notify_on_over_limit' => $setting->budget_notify_on_over_limit ?? true,
            ]);

            $budget->categories()->sync($request->category_ids ?? []);
            $budget->labels()->sync($request->label_ids ?? []);

            $period = $this->budgetPeriodService->generatePeriod($budget, $request->allocated_amount, null, true);
            $previousPeriod = $this->budgetPeriodService->generatePreviousPeriod($budget, $period, $request->allocated_amount, true);

            return ['budget' => $budget, 'period' => $period, 'previousPeriod' => $previousPeriod];
        });

        // Dispatch jobs to assign historical transactions for the current and previous periods
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['period']);
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['previousPeriod']);

        return redirect()->route('budgets.show', $result['budget']);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        DB::transaction(function () use ($request, $budget) {
            $budget->update($request->only([
                'name',
                'period_type',
                'period_start_day',
                'rollover_type',
            ]));

            // If allocated_amount is provided, update current and future periods
            if ($request->has('allocated_amount')) {
                $budget->periods()
                    ->where('start_date', '>=', now()->startOfDay())
                    ->update(['allocated_amount' => $request->allocated_amount]);
            }
        });

        return redirect()->route('budgets.show', $budget);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $budget->delete();

        return redirect()->route('budgets.index');
    }
}
