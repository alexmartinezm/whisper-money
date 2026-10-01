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

#[Description('Set the allocation of a single budget period, the one in progress or a future one up to 12 months ahead, and leave every other period as it is. Use it for a one-off month such as an extraordinary expense; use update_budget to change the regular allocation of every period from now on, which keeps one-off amounts. Pass reset: true instead of an amount to put the period back on the regular allocation.')]
class UpdateBudgetPeriod extends WriteTool
{
    use InteractsWithBudgets;

    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Budget id.')->required(),
            'date' => $schema->string()->description('Any date inside the period to change, as YYYY-MM-DD. For a monthly budget starting on day 1, any day of that month (e.g. 2026-12-01). Closed periods cannot change.')->required(),
            'allocated_amount' => $schema->integer()->min(0)->description('One-off allocation for that period only, in minor units. Required unless reset is true.'),
            'reset' => $schema->boolean()->description('Set true, without allocated_amount, to drop the period\'s one-off amount and put it back on the regular allocation.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'budget_id' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'allocated_amount' => ['nullable', 'integer', 'min:0'],
            'reset' => ['nullable', 'boolean'],
        ]);

        $reset = $request->boolean('reset');
        if ($reset === $request->filled('allocated_amount')) {
            return Response::error('Pass either allocated_amount or reset: true.');
        }

        $space = $this->resolveSpace($request, $user);
        $result = $this->budgets->updatePeriodAllocation(
            $user,
            $space,
            $request->string('budget_id')->toString(),
            CarbonImmutable::parse($request->string('date')->toString())->startOfDay(),
            $reset ? null : $request->integer('allocated_amount'),
            CarbonImmutable::today(),
        );
        [$current, $next] = $this->periodsFor($result['budget']);
        $period = $result['period'];

        return $this->json([
            'space_id' => $space->id,
            'budget' => $this->presentBudget($result['budget'], $current, $next),
            'period' => $result['current_period_changed'] ? $this->presentCurrentPeriod($period) : $this->presentNextPeriod($period),
            'following_period' => $this->presentNextPeriod($result['following_period']),
        ]);
    }
}
