<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBudgets;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List active budgets in an accessible space. Amounts are minor units. Reads do not create missing periods.')]
class ListBudgets extends McpTool
{
    use InteractsWithBudgets;

    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $asOf = today();
        $budgets = Budget::query()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->with([
                'categories' => fn ($query) => $query->orderBy('name')->orderBy('id'),
                'labels' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $periods = BudgetPeriod::query()
            ->whereIn('budget_id', $budgets->modelKeys())
            ->where(function ($query) use ($asOf): void {
                $query->where(function ($current) use ($asOf): void {
                    $current->whereDate('start_date', '<=', $asOf)->whereDate('end_date', '>=', $asOf);
                })->orWhereDate('start_date', '>', $asOf);
            })
            ->withSum('budgetTransactions as spent_amount', 'amount')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get()
            ->groupBy('budget_id');

        return $this->json([
            'space_id' => $space->id,
            'as_of' => $asOf->toDateString(),
            'budgets' => $budgets->map(function (Budget $budget) use ($periods, $asOf): array {
                $budgetPeriods = $periods->get($budget->id, collect());
                $current = $budgetPeriods->first(fn (BudgetPeriod $period): bool => $period->start_date <= $asOf && $period->end_date >= $asOf);
                $next = $budgetPeriods->first(fn (BudgetPeriod $period): bool => $period->start_date > $asOf);

                return $this->presentBudget($budget, $current, $next);
            })->all(),
        ]);
    }
}
