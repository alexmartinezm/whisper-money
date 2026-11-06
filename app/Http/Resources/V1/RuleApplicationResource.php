<?php

namespace App\Http\Resources\V1;

use App\Services\AutomationRules\RuleApplication;
use App\Services\AutomationRules\RuleApplicationStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What applying an automation rule to existing transactions did: a preview,
 * a run done right away or one queued for the background.
 *
 * @mixin RuleApplication
 */
class RuleApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'automation_rule_id' => $this->automationRuleId,
            'total' => $this->total,
            'sample' => TransactionResource::collection($this->sample),
            'applied' => $this->applied,
            'updated' => $this->updated,
        ];
    }

    /**
     * A run handed to the queue is still in progress: `202 Accepted`.
     */
    public function withResponse(Request $request, JsonResponse $response): void
    {
        if ($this->status === RuleApplicationStatus::Queued) {
            $response->setStatusCode(202);
        }
    }
}
