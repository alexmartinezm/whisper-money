<?php

namespace App\Mcp\Tools;

use App\Enums\RuleOrigin;
use App\Mcp\Tools\Concerns\PresentsAutomationRules;
use App\Models\AutomationRule;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
List the automation rules in a space, in the order they are evaluated. Read this before
creating a rule, to avoid duplicating or contradicting one that already exists, and after
writing one, to confirm what was stored. It also explains an automatic categorization:
`categorized_by_rule_id` on a transaction points at one of these ids.

`rules_json` comes back exactly as stored — the JsonLogic condition, whose `amount`
variable is in MAJOR units (12.50), unlike every other amount in this API. `origin`
separates the rules the user wrote (`user`) from those the app inferred (`ai`) or learned
from a correction (`correction`).
TEXT)]
class ListAutomationRules extends McpTool
{
    use PresentsAutomationRules;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
            'origin' => $schema->string()->enum(array_column(RuleOrigin::cases(), 'value'))->description('Only rules of this origin. Defaults to all.'),
            'limit' => $schema->integer()->min(1)->description('Maximum number of rules to return. Defaults to all.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'origin' => ['sometimes', Rule::enum(RuleOrigin::class)],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        $space = $this->resolveSpace($request, $user);

        $rules = AutomationRule::query()
            ->forSpace($space)
            ->with('labels:id,name')
            // The evaluation order, which is also the order that makes two
            // rules comparable: same priority, oldest first. The id breaks the
            // remaining tie so the listing never reshuffles between calls.
            ->orderBy('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->when($request->filled('origin'), fn ($query) => $query->origin($request->enum('origin', RuleOrigin::class)))
            ->when($request->filled('limit'), fn ($query) => $query->limit($request->integer('limit')))
            ->get();

        return $this->json([
            'space_id' => $space->id,
            'automation_rules' => $rules
                ->map(fn (AutomationRule $rule): array => $this->presentAutomationRule($rule))
                ->all(),
        ]);
    }
}
