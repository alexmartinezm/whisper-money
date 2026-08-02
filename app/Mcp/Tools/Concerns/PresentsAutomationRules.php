<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\AutomationRule;
use App\Models\Label;

/**
 * The automation-rule shape shared by the rule write tools and
 * list_automation_rules, so a rule reads the same whichever tool returned it.
 *
 * There is deliberately no `enabled` key: the table has no such column, only a
 * soft delete. A rule that exists is a rule that runs.
 */
trait PresentsAutomationRules
{
    /**
     * @return array<string, mixed>
     */
    protected function presentAutomationRule(AutomationRule $rule): array
    {
        $rule->loadMissing('labels:id,name');

        return [
            'id' => $rule->id,
            'title' => $rule->title,
            'priority' => $rule->priority,
            'rules_json' => $rule->rules_json,
            'action_category_id' => $rule->action_category_id,
            'action_note' => $rule->action_note,
            'origin' => $rule->origin->value,
            'labels' => $rule->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
