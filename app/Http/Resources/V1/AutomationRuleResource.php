<?php

namespace App\Http\Resources\V1;

use App\Models\AutomationRule;
use App\Services\AutomationRules\RuleConditions;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An automation rule: conditions a new transaction is checked against, and
 * the category, labels and note it gets when it meets them.
 *
 * @mixin AutomationRule
 */
class AutomationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('labels:id');

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'title' => $this->title,
            'priority' => $this->priority,
            'conditions' => RuleConditions::decoded($this->rules_json) ?: (object) [],
            'action_category_id' => $this->action_category_id,
            'action_label_ids' => $this->labels->pluck('id')->values()->all(),
            // Still encrypted on the device: empty, as the CSV export writes it.
            'action_note' => $this->action_note_iv === null ? $this->action_note : '',
            'origin' => $this->origin->value,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
