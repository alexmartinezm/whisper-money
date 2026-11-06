<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\DecodesRulesJson;
use App\Models\User;
use App\Services\AutomationRules\AutomationRuleWriter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description(<<<'TEXT'
Edit an automation rule. Only the fields you pass are changed. The rule must always keep at
least one action (a category or labels), so clearing the last one is refused and leaves the
rule untouched. `action_label_ids` replaces the whole label set rather than adding to it.

`rules_json` is a JsonLogic object; see create_automation_rule for the variables it can
match on. Its `amount` variable is the one exception to this API's minor-units rule: it is
in MAJOR units (12.50, not 1250). Passing cents here silently rewrites the rule to match
amounts a hundred times larger.
TEXT)]
class UpdateAutomationRule extends WriteTool
{
    use DecodesRulesJson;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'automation_rule_id' => $schema->string()->description('Id of the automation rule to edit.')->required(),
            'title' => $schema->string()->description('New rule name.'),
            'priority' => $schema->integer()->min(0)->description('New priority (lower is evaluated first).'),
            'rules_json' => $schema->object()->description('Replacement condition object (it replaces the rule\'s conditions entirely). '.$this->rulesJsonDescription()),
            'action_category_id' => $schema->string()->nullable()->description('New category id to assign, or null to clear.'),
            'action_label_ids' => $schema->array()->items($schema->string())->description('Replacement set of label ids (replaces all existing labels).'),
            'action_note' => $schema->string()->nullable()->description('New note to append, or null to clear.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(AutomationRuleWriter::updateRules());

        $space = $this->resolveSpace($request, $user);
        $rule = $this->ruleInSpace($request, $space);

        $changes = $this->requestedFields($request, [
            'title' => fn () => $request->string('title')->toString(),
            'priority' => fn () => $request->integer('priority'),
            'rules_json' => fn () => $this->rulesJson($request),
            'action_category_id' => fn () => $request->filled('action_category_id')
                ? $this->categoryInSpace($request, $space, 'action_category_id')->id
                : null,
            'action_note' => fn () => $this->nullableString($request, 'action_note'),
        ]);

        $labelIds = $request->has('action_label_ids')
            ? $this->labelsInSpace($request, $space, 'action_label_ids')->pluck('id')->all()
            : null;

        $rule = app(AutomationRuleWriter::class)->update($rule, $changes, $labelIds);

        return $this->json(['automation_rule' => $this->presentAutomationRule($rule)]);
    }
}
