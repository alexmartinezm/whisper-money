<?php

namespace App\Http\Resources\V1;

use App\Models\Budget;
use App\Models\User;
use App\Services\Budgets\BudgetPresenter;
use Closure;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A budget: a spending limit per period over a set of categories and labels,
 * with the period in progress.
 *
 * @mixin Budget
 */
class BudgetResource extends JsonResource
{
    /**
     * What a budget reads, for a list to eager load at once.
     *
     * @return array<int|string, string|Closure>
     */
    public static function relations(): array
    {
        return [
            'categories',
            'labels',
            'currentPeriods' => fn (HasMany $periods) => $periods->withSum('budgetTransactions as spent_amount', 'amount'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        $this->resource->loadMissing(self::relations());
        $period = $this->resource->currentPeriods->first();

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'period_type' => $this->period_type->value,
            'period_start_day' => $this->period_start_day,
            'rollover_type' => $this->rollover_type->value,
            'expense_type' => $this->expense_type->value,
            'is_catch_all' => (bool) $this->is_catch_all,
            ...app(BudgetPresenter::class)->tracking($this->resource),
            'currency' => $user->currency_code ?? 'USD',
            'current_period' => $period === null ? null : new BudgetPeriodResource($period),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
