<?php

namespace App\Http\Resources\V1;

use App\Models\SavingsGoal;
use App\Services\SavingsGoals\SavingsGoalIncomeWithholding;
use App\Services\SavingsGoals\SavingsGoalPresenter;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A savings goal, tracked through a label: its contributions are the
 * transactions tagged with it. A `one_off` goal carries its `progress`
 * towards a total, a `monthly` goal its `monthly` record; the fields of the
 * other kind are `null`. In a space with other members, the `monthly` figures
 * of a goal measured against the space's income are `null` too, because they
 * would give away what the others earn: see SavingsGoalIncomeWithholding.
 *
 * @mixin SavingsGoal
 */
class SavingsGoalResource extends JsonResource
{
    /**
     * The goal as SavingsGoalPresenter shapes it, when a list worked out the
     * whole page at once.
     *
     * @var array<string, mixed>|null
     */
    private ?array $row = null;

    /**
     * A page of goals, their stats read in one batch.
     *
     * @param  mixed  $resource
     */
    public static function collection($resource): AnonymousResourceCollection
    {
        $collection = parent::collection($resource);
        $goals = $collection->collection->map(fn (self $goal): mixed => $goal->resource)->whereInstanceOf(SavingsGoal::class)->values();
        $page = new EloquentCollection($goals->all());
        $rows = app(SavingsGoalIncomeWithholding::class)->apply($page, app(SavingsGoalPresenter::class)->many($page));

        $collection->collection->each(function (self $goal) use ($rows): void {
            $goal->row = $rows[$goal->resource->id];
        });

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = $this->row ?? app(SavingsGoalIncomeWithholding::class)->apply(
            $this->resource->newCollection([$this->resource]),
            [$this->id => app(SavingsGoalPresenter::class)->one($this->resource)],
        )[$this->id];

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'label_id' => $this->label_id,
            'currency' => $this->user->currency_code ?? 'USD',
            'target_amount' => $row['target_amount'] ?? null,
            'initial_amount' => $row['initial_amount'] ?? null,
            'target_date' => $row['target_date'] ?? null,
            'progress' => $row['progress'] ?? null,
            'monthly_target_type' => $row['monthly_target_type'] ?? null,
            'monthly_target_amount' => $row['monthly_target_amount'] ?? null,
            'monthly_target_rate' => $row['monthly_target_rate'] ?? null,
            'notify_on_month_end_reminder' => $row['notify_on_month_end_reminder'] ?? null,
            'auto_tag_account_id' => $this->auto_tag_account_id,
            'monthly' => $row['monthly'] ?? null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
