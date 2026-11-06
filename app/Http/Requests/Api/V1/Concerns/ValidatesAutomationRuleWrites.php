<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use App\Models\Label;
use App\Services\AutomationRules\RuleConditions;
use Closure;

/**
 * What the automation rule create and update requests share: the
 * AutomationRuleWriter rules and the RuleConditions checks the MCP tools
 * validate with too, and the category and labels the rule applies, checked to
 * be the caller's own in the request's space. The conditions come as a JSON object, never as
 * the JSON-encoded string the MCP also accepts.
 */
trait ValidatesAutomationRuleWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the AutomationRuleWriter rules for the write
     * @param  string  $presence  `present` on create, `sometimes` on update: an empty object is RuleConditions' to refuse
     * @return array<string, array<mixed>>
     */
    protected function automationRuleFields(array $rules, string $presence): array
    {
        return [
            'title' => $rules['title'],
            'priority' => $rules['priority'],
            'conditions' => ['bail', $presence, $this->validConditions(...)],
            'action_category_id' => ['sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
            'action_label_ids' => $rules['action_label_ids'],
            'action_label_ids.*' => ['string', 'distinct:ignore_case', $this->inSpace(Label::class, 'a label')],
            'action_note' => $rules['action_note'],
        ];
    }

    /**
     * The validated fields as AutomationRuleWriter takes them: only the ones
     * sent, the conditions under `rules_json`, and without the labels.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $validated = $this->safe()->except(['conditions', 'action_label_ids']);

        if ($this->has('conditions')) {
            $validated['rules_json'] = $this->validated('conditions');
        }

        return $validated;
    }

    /**
     * The labels sent, or null when the request leaves them alone.
     *
     * @return list<string>|null
     */
    public function labelIds(): ?array
    {
        return $this->has('action_label_ids') ? array_values($this->validated('action_label_ids')) : null;
    }

    /**
     * Read the conditions as sent. The global TrimStrings and
     * ConvertEmptyStringsToNull middleware would otherwise rewrite the values
     * they compare against (`" netflix "`, `""`), so a rule would match
     * something else than the same conditions sent through the MCP.
     */
    protected function prepareForValidation(): void
    {
        $sent = json_decode($this->getContent(), true);

        if (is_array($sent) && array_key_exists('conditions', $sent)) {
            $this->merge(['conditions' => $sent['conditions']]);
        }
    }

    private function validConditions(string $attribute, mixed $value, Closure $fail): void
    {
        $error = RuleConditions::error($value, $attribute);

        if ($error !== null) {
            $fail($error);
        }
    }
}
