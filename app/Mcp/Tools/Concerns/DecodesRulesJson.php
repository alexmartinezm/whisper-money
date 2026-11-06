<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Label;
use App\Models\Space;
use App\Services\AutomationRules\AutomationRuleWriter;
use App\Services\AutomationRules\RuleConditions;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;

trait DecodesRulesJson
{
    /**
     * Decode and validate a JsonLogic condition argument, accepting either a
     * JSON object or a JSON-encoded string. What is accepted is
     * RuleConditions' to decide, as for `/api/v1/automation-rules`.
     *
     * @return array<string, mixed>
     */
    protected function rulesJson(Request $request, string $key = 'rules_json'): array
    {
        $rulesJson = $request->get($key);

        if (is_string($rulesJson)) {
            $rulesJson = json_decode($rulesJson, true);
        }

        $error = RuleConditions::error($rulesJson, $key);

        if ($error !== null) {
            throw ValidationException::withMessages([$key => $error]);
        }

        return $rulesJson;
    }

    /**
     * The writer's plain rules, without the one on the labels' shape: this
     * surface has always taken `action_label_ids` as a list, a bare id or null
     * (see requestedIds()), which ruleLabels() reads and caps.
     *
     * @param  array<string, array<mixed>>  $rules
     * @return array<string, array<mixed>>
     */
    protected function plainFieldRules(array $rules): array
    {
        return Arr::except($rules, 'action_label_ids');
    }

    /**
     * The labels the rule applies, among those of the space, capped as the
     * writer's rules cap a list.
     *
     * @return Collection<int, Label>
     */
    protected function ruleLabels(Request $request, Space $space): Collection
    {
        if (count($this->requestedIds($request, 'action_label_ids')) > AutomationRuleWriter::MaxLabels) {
            throw ValidationException::withMessages([
                'action_label_ids' => 'A rule applies at most '.AutomationRuleWriter::MaxLabels.' labels.',
            ]);
        }

        return $this->labelsInSpace($request, $space, 'action_label_ids');
    }

    /**
     * The shared `rules_json` schema description. Both write tools point at the
     * same text so an agent reads the same format whichever one it starts from.
     */
    protected function rulesJsonDescription(): string
    {
        return RuleConditions::Description;
    }
}
