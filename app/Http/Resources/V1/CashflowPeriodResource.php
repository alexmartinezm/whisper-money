<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cashflow of one period, in minor units of `currency`. Income and
 * expenses are signed nets: a refund lowers the expense, and a period whose
 * refunds outweigh its spending has a negative one.
 *
 * @property array<string, mixed> $resource
 */
class CashflowPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'income' => $this->resource['income'],
            'expense' => $this->resource['expense'],
            'net' => $this->resource['net'],
            'savings_rate' => $this->resource['savings_rate'],
            'savings' => $this->resource['savings'],
            'investments' => $this->resource['investments'],
            'fixed_expense' => $this->resource['fixed_expense'],
            'variable_expense' => $this->resource['variable_expense'],
            'general_expense' => $this->resource['general_expense'],
            'reserved' => $this->when(array_key_exists('reserved', $this->resource), fn (): mixed => $this->resource['reserved']),
            'budgets' => $this->when(array_key_exists('budgets', $this->resource), fn (): mixed => CashflowBudgetResource::collection($this->resource['budgets'])),
        ];
    }
}
