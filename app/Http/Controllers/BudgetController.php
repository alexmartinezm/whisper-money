<?php

namespace App\Http\Controllers;

use App\Enums\BudgetPeriodType;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetPeriodRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Services\BudgetManagementService;
use App\Services\BudgetPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected BudgetPeriodService $budgetPeriodService,
        protected BudgetManagementService $budgetManagementService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $applicationDate = CarbonImmutable::today();
        $activeSpaceId = $user->activeSpace()?->id;
        $budgets = $user
            ->budgets()
            ->with(['categories', 'labels', 'periods' => function ($query) use ($applicationDate) {
                $query->whereDate('start_date', '<=', $applicationDate->toDateString())
                    ->whereDate('end_date', '>=', $applicationDate->toDateString())
                    ->withSum('budgetTransactions as spent_amount', 'amount');
            }])
            ->get();

        $budgets->each(function (Budget $budget) use ($applicationDate, $activeSpaceId): void {
            if ($budget->space_id !== $activeSpaceId) {
                $budget->setAttribute('next_planning_period', null);

                return;
            }

            $nextPlanningPeriod = $budget->getNextPlanningPeriod($applicationDate, $budget->periods->first());
            $budget->setAttribute('next_planning_period', $nextPlanningPeriod === null ? null : [
                'id' => $nextPlanningPeriod->id,
                'start_date' => $nextPlanningPeriod->start_date,
                'end_date' => $nextPlanningPeriod->end_date,
                'allocated_amount' => $nextPlanningPeriod->allocated_amount,
            ]);
        });

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
        $applicationDate = CarbonImmutable::today();
        $activePeriod = $budget->getCurrentPeriod($applicationDate);
        if ($activePeriod === null) {
            $activePeriod = $this->budgetPeriodService->generatePeriod($budget, null, $applicationDate);
        }
        $directSuccessor = $budget->getNextPlanningPeriod($applicationDate, $activePeriod);

        $periodId = $request->query('period');
        $isPlanningPeriod = false;
        $canPlanThisBudget = $budget->space_id === $user->activeSpace()?->id;
        if ($periodId) {
            $viewedPeriod = $budget->periods()->whereKey($periodId)->firstOrFail();
            $isPlanningPeriod = $canPlanThisBudget
                && $directSuccessor !== null
                && $viewedPeriod->id === $directSuccessor->id;

            if ($viewedPeriod->start_date->greaterThan($applicationDate) && ! $isPlanningPeriod) {
                abort(404);
            }
        } else {
            $viewedPeriod = $activePeriod;
        }

        if (! $isPlanningPeriod) {
            $viewedPeriod->load([
                'budgetTransactions.transaction.account.bank',
                'budgetTransactions.transaction.category',
                'budgetTransactions.transaction.labels',
                'budgetTransactions.transaction.splits.category',
            ]);
        }

        $previousPeriod = $budget->periods()
            ->where('end_date', '<', $viewedPeriod->start_date)
            ->orderBy('end_date', 'desc')
            ->with(['budgetTransactions.transaction'])
            ->first();

        if ($isPlanningPeriod) {
            $nextPeriod = null;
        } elseif ($viewedPeriod->id === $activePeriod->id) {
            $nextPeriod = $directSuccessor;
        } else {
            $nextPeriod = $budget->periods()
                ->where('start_date', '>', $viewedPeriod->end_date)
                ->whereDate('start_date', '<=', $applicationDate->toDateString())
                ->orderBy('start_date', 'asc')
                ->first();
        }

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
            'nextPlanningPeriod' => $isPlanningPeriod ? null : $directSuccessor,
            'is_planning_period' => $isPlanningPeriod,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    public function store(StoreBudgetRequest $request): RedirectResponse
    {
        $result = $this->budgetManagementService->create(
            $request->user(),
            $request->user()->activeSpace(),
            [
                'name' => $request->name,
                'period_type' => $request->period_type,
                'period_start_day' => $request->period_start_day,
                'rollover_type' => $request->rollover_type,
                'is_catch_all' => $request->boolean('is_catch_all'),
                'allocated_amount' => $request->integer('allocated_amount'),
            ],
            $request->category_ids ?? [],
            $request->label_ids ?? [],
        );

        return redirect()->route('budgets.show', $result['budget']);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): RedirectResponse
    {
        $this->authorize('update', $budget);

        $this->budgetManagementService->update(
            $request->user(),
            $request->user()->activeSpace(),
            $budget->id,
            $request->only(['name', 'allocated_amount']),
            CarbonImmutable::today(),
        );

        return redirect()->route('budgets.show', $budget);
    }

    public function updatePeriod(UpdateBudgetPeriodRequest $request, Budget $budget, BudgetPeriod $period): RedirectResponse
    {
        $this->authorize('update', $budget);

        $this->budgetManagementService->updateNextPeriodAllocation(
            $request->user(),
            $request->user()->activeSpace(),
            $budget->id,
            $period->id,
            $request->integer('allocated_amount'),
            CarbonImmutable::today(),
        );

        return redirect()->route('budgets.show', ['budget' => $budget, 'period' => $period]);
    }

    public function destroy(Request $request, Budget $budget): RedirectResponse
    {
        $this->authorize('delete', $budget);

        $this->budgetManagementService->delete(
            $request->user(),
            $request->user()->activeSpace(),
            $budget->id,
        );

        return redirect()->route('budgets.index');
    }
}
