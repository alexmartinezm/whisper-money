<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cashflow of a date range across your whole account: the summary against
 * the previous period, where the money came from and went, and the monthly
 * trend.
 *
 * @property array{summary: array{current: array<string, mixed>, previous: array<string, mixed>}, sankey: array<string, mixed>, trend: array{data: list<array{month: string, income: int, expense: int, net: int}>}} $resource
 */
class CashflowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $sankey = $this->resource['sankey'];

        return [
            'currency' => $user->currency_code ?? 'USD',
            'summary' => [
                'current' => new CashflowPeriodResource($this->resource['summary']['current']),
                'previous' => new CashflowPeriodResource($this->resource['summary']['previous']),
            ],
            'sankey' => [
                'income_categories' => CategoryAmountResource::collection($sankey['income_categories']),
                'expense_categories' => CategoryAmountResource::collection($sankey['expense_categories']),
                'total_income' => $sankey['total_income'],
                'total_expense' => $sankey['total_expense'],
            ],
            'trend' => $this->resource['trend']['data'],
        ];
    }
}
