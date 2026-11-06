<?php

namespace App\Http\Resources\V1;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a one-off allocation changed: the budget, the single period that holds
 * it, and the one after it, which keeps the regular allocation.
 *
 * @property array{budget: Budget, period: BudgetPeriod, following_period: BudgetPeriod, current_period_changed: bool} $resource
 */
class BudgetPeriodAllocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'budget' => new BudgetResource($this->resource['budget']),
            'period' => new BudgetPeriodResource($this->resource['period']),
            'following_period' => new BudgetPeriodResource($this->resource['following_period']),
        ];
    }
}
