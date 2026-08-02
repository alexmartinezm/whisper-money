<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Which budgets the current pace will overshoot before the period is out.
 *
 * This is where a budget earns its place next to a forecast. Folding an
 * allocation into a projected balance would produce a number that is wrong in a
 * new way — allocations are an intention, not a prediction, and most spending
 * is not budgeted at all. Held against the pace instead, the same figure says
 * something a balance never can: which category to slow down, and by how much.
 */
class ProjectBudgetPace
{
    /**
     * Days of a period that must have elapsed before extrapolating from it.
     * On day one a single weekly shop reads as a month of overspending.
     */
    private const MIN_ELAPSED_DAYS = 5;

    /** How many warnings the screen carries before it becomes a wall. */
    private const MAX_RESULTS = 3;

    /**
     * @return list<array{id: string, name: string, spent: int, available: int, projected: int, over_by: int, period_end: string}>
     */
    public function forUser(User $user, ?CarbonImmutable $today = null, ?Space $space = null): array
    {
        $today ??= CarbonImmutable::today();

        $budgets = $user->budgets()
            ->where('space_id', ($space ?? $user->activeSpace())->id)
            ->with(['periods' => function ($query) use ($today): void {
                $query->whereDate('start_date', '<=', $today->toDateString())
                    ->whereDate('end_date', '>=', $today->toDateString())
                    ->withSum('budgetTransactions as spent_amount', 'amount');
            }])
            ->get();

        return $budgets
            ->map(fn (Budget $budget): ?array => $this->pace($budget, $today))
            ->filter()
            ->sortByDesc('over_by')
            ->take(self::MAX_RESULTS)
            ->values()
            ->all();
    }

    /**
     * @return null|array{id: string, name: string, spent: int, available: int, projected: int, over_by: int, period_end: string}
     */
    private function pace(Budget $budget, CarbonImmutable $today): ?array
    {
        $period = $budget->periods->first();

        // A period still assigning its history has a spent total that is only
        // partly filled in, and would read as comfortably under budget.
        if ($period === null || $period->processing_historical) {
            return null;
        }

        $available = $period->availableAmount();

        if ($available <= 0) {
            return null;
        }

        $elapsed = $this->elapsedDays($period, $today);
        $total = $this->totalDays($period);

        if ($elapsed < self::MIN_ELAPSED_DAYS || $elapsed >= $total) {
            return null;
        }

        $spent = (int) ($period->getAttribute('spent_amount') ?? 0);
        $projected = (int) round($spent / $elapsed * $total);

        if ($projected <= $available) {
            return null;
        }

        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'spent' => $spent,
            'available' => $available,
            'projected' => $projected,
            'over_by' => $projected - $available,
            'period_end' => $period->end_date->toDateString(),
        ];
    }

    /** Days of the period already lived, counting today as spent. */
    private function elapsedDays(BudgetPeriod $period, CarbonImmutable $today): int
    {
        return (int) CarbonImmutable::parse($period->start_date)->diffInDays($today) + 1;
    }

    private function totalDays(BudgetPeriod $period): int
    {
        return (int) CarbonImmutable::parse($period->start_date)
            ->diffInDays(CarbonImmutable::parse($period->end_date)) + 1;
    }
}
