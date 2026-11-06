<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use App\Models\Label;
use App\Rules\ValidRuleConditions;
use App\Services\AutomationRules\AutomationRuleWriter;

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
            'conditions' => ['bail', $presence, new ValidRuleConditions],
            'action_category_id' => ['sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
            'action_label_ids' => $rules['action_label_ids'],
            ...$this->labelIdFields(),
            'action_note' => $rules['action_note'],
        ];
    }

    /**
     * The rules for each label id. An oversized list gets none: Laravel expands
     * them per item before the cap on the list is ever read, so a huge list
     * would cost its `distinct` and its lookup just to be refused.
     *
     * @return array<string, array<mixed>>
     */
    private function labelIdFields(): array
    {
        $labelIds = $this->input('action_label_ids');

        if (is_array($labelIds) && count($labelIds) > AutomationRuleWriter::MaxLabels) {
            return [];
        }

        return ['action_label_ids.*' => ['string', 'distinct:ignore_case', $this->inSpace(Label::class, 'a label')]];
    }

    /**
     * The validated fields as AutomationRuleWriter takes them: only the ones
     * sent, the conditions under `rules_json`, the category as stored, and
     * without the labels.
     *
     * @return array<string, mixed>
     */
    public function ruleAttributes(): array
    {
        $validated = $this->safe()->except(['conditions', 'action_label_ids']);

        if ($this->has('conditions')) {
            $validated['rules_json'] = $this->validated('conditions');
        }

        if (isset($validated['action_category_id'])) {
            $validated['action_category_id'] = $this->idInSpace(Category::class, 'action_category_id', $validated['action_category_id']);
        }

        return $validated;
    }

    /**
     * The labels sent, as stored, or null when the request leaves them alone.
     *
     * @return list<string>|null
     */
    public function labelIds(): ?array
    {
        if (! $this->has('action_label_ids')) {
            return null;
        }

        return array_map(
            fn (string $id): string => $this->idInSpace(Label::class, 'action_label_ids.*', $id),
            array_values($this->validated('action_label_ids')),
        );
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
}
