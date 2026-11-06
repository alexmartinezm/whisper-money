<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget period in progress today that overlaps the cashflow's range, with
 * what is left of it.
 *
 * @property array{budget_id: string, budget_period_id: string, name: string, start_date: string, end_date: string, expense_type: string, allocated: int, spent: int, margin: int} $resource
 */
class CashflowBudgetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'budget_id' => $this->resource['budget_id'],
            'budget_period_id' => $this->resource['budget_period_id'],
            'name' => $this->resource['name'],
            'start_date' => $this->resource['start_date'],
            'end_date' => $this->resource['end_date'],
            'expense_type' => $this->resource['expense_type'],
            'allocated' => $this->resource['allocated'],
            'spent' => $this->resource['spent'],
            'margin' => $this->resource['margin'],
        ];
    }
}
