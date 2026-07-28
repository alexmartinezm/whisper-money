<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBudgets;
use App\Models\User;
use App\Services\BudgetManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Update only a budget name and/or its allocated amount. Amount changes affect periods starting on or after the server application date; cadence, tracking and rollover are immutable.')]
class UpdateBudget extends WriteTool
{
    use InteractsWithBudgets;

    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Budget id.')->required(),
            'name' => $schema->string()->description('New budget name.'),
            'allocated_amount' => $schema->integer()->min(0)->description('New allocation in minor units.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'budget_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
        ]);

        if (! $request->has('name') && ! $request->has('allocated_amount')) {
            return Response::error('Provide a name or allocated_amount to update the budget.');
        }

        $space = $this->resolveSpace($request, $user);
        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = $request->string('name')->toString();
        }
        if ($request->has('allocated_amount')) {
            $changes['allocated_amount'] = $request->integer('allocated_amount');
        }

        $result = $this->budgets->update(
            $user,
            $space,
            $request->string('budget_id')->toString(),
            $changes,
            CarbonImmutable::today(),
        );
        [$current, $next] = $this->periodsFor($result['budget']);

        return $this->json([
            'space_id' => $space->id,
            'budget' => $this->presentBudget($result['budget'], $current, $next),
            'adjustment' => $result['adjustment'],
        ]);
    }
}
