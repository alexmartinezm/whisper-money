<?php

namespace App\Http\Resources\V1;

use App\Models\BudgetPeriod;
use App\Services\Budgets\BudgetPresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One period of a budget: the stretch of days its limit applies to, and what
 * was spent against it. Amounts are in the minor units of the budget's
 * `currency`.
 *
 * @mixin BudgetPeriod
 */
class BudgetPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(BudgetPresenter::class)->period($this->resource);
    }
}
