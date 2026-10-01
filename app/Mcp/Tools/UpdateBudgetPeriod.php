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

#[Description('Set the allocation of a single budget period, the one in progress or a future one up to 12 months ahead, and leave every other period as it is. Use it for a one-off month such as an extraordinary expense; use update_budget to change the allocation of every period from now on. A later allocation change through update_budget overwrites the one-off amounts of future periods.')]
class UpdateBudgetPeriod extends WriteTool
{
    use InteractsWithBudgets;

    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'budget_id' => $schema->string()->description('Budget id.')->required(),
            'date' => $schema->string()->description('Any date inside the period to change, as YYYY-MM-DD. For a monthly budget starting on day 1, any day of that month (e.g. 2026-12-01). Closed periods cannot change.')->required(),
            'allocated_amount' => $schema->integer()->min(0)->description('Allocation for that period only, in minor units.')->required(),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'budget_id' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'allocated_amount' => ['required', 'integer', 'min:0'],
        ]);

        $space = $this->resolveSpace($request, $user);
        $result = $this->budgets->updatePeriodAllocation(
            $user,
            $space,
            $request->string('budget_id')->toString(),
            CarbonImmutable::parse($request->string('date')->toString())->startOfDay(),
            $request->integer('allocated_amount'),
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
