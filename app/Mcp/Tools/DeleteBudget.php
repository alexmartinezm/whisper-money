<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\BudgetManagementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Soft-delete an owned budget. Historical periods and assignments remain stored internally.')]
class DeleteBudget extends WriteTool
{
    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Budget id.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate(['budget_id' => ['required', 'string']]);
        $space = $this->resolveSpace($request, $user);
        $id = $this->budgets->delete($user, $space, $request->string('budget_id')->toString());

        return $this->json(['deleted' => true, 'id' => $id]);
    }
}
