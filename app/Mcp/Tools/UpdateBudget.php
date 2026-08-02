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
#[Description('Update a budget name, allocation, tracked categories and/or tracked labels. Tracking changes preserve closed-period history and recalculate the active period. Cadence and rollover remain immutable.')]
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
            'category_ids' => $schema->array()->items($schema->string())->description('Replacement set of tracked category ids.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Replacement set of tracked label ids.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'budget_id' => ['required', 'string'],
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => ['string'],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['string'],
        ]);

        if (! $request->has('name') && ! $request->has('allocated_amount') && ! $request->has('category_ids') && ! $request->has('label_ids')) {
            return Response::error('Provide a mutable budget field.');
        }

        $space = $this->resolveSpace($request, $user);
        $changes = [];
        if ($request->has('name')) {
            $changes['name'] = $request->string('name')->toString();
        }
        if ($request->has('allocated_amount')) {
            $changes['allocated_amount'] = $request->integer('allocated_amount');
        }
        if ($request->has('category_ids')) {
            $changes['category_ids'] = $request->array('category_ids');
        }
        if ($request->has('label_ids')) {
            $changes['label_ids'] = $request->array('label_ids');
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
