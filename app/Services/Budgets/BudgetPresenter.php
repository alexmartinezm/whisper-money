<?php

namespace App\Services\Budgets;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;

/**
 * The parts of a budget the budget MCP tools return and `/api/v1/budgets`
 * documents, so both read a budget the same way. Amounts are in minor units.
 */
class BudgetPresenter
{
    /**
     * What the budget tracks, by name.
     *
     * @return array{categories: list<array{id: string, name: string}>, labels: list<array{id: string, name: string}>}
     */
    public function tracking(Budget $budget): array
    {
        return [
            'categories' => $budget->categories
                ->sortBy(fn (Category $category): string => $category->name.'|'.$category->id)
                ->values()
                ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'labels' => $budget->labels
                ->sortBy(fn (Label $label): string => $label->name.'|'.$label->id)
                ->values()
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->all(),
        ];
    }

    /**
     * A period with what was spent against it, read from the `spent_amount` a
     * query summed when one did, so a list asks for it once.
     *
     * @return array<string, mixed>
     */
    public function period(BudgetPeriod $period): array
    {
        $spent = array_key_exists('spent_amount', $period->getAttributes())
            ? (int) $period->spent_amount
            : $period->spentAmount();
        $available = $period->availableAmount();

        return [
            ...$this->allocation($period),
            'carried_over_amount' => (int) ($period->carried_over_amount ?? 0),
            'available_amount' => $available,
            'spent_amount' => $spent,
            'remaining_amount' => $available - $spent,
            'status' => $period->limitStatus($spent),
            'processing_historical' => (bool) $period->processing_historical,
        ];
    }

    /**
     * A period's dates and allocation. `regular_allocated_amount` is what the
     * period goes back to when `is_one_off` is true, and equals
     * `allocated_amount` otherwise.
     *
     * @return array<string, mixed>
     */
    public function allocation(BudgetPeriod $period): array
    {
        return [
            'id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'allocated_amount' => (int) $period->allocated_amount,
            'is_one_off' => $period->hasOneOffAllocation(),
            'regular_allocated_amount' => $period->regularAllocatedAmount(),
        ];
    }
}
