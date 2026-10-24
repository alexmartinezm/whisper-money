<?php

namespace App\Mcp\Tools;

use App\Enums\BudgetExpenseType;
use App\Mcp\Tools\Concerns\InteractsWithBudgets;
use App\Models\User;
use App\Services\BudgetManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Update a budget name, allocation, tracked categories, tracked labels and/or expense type. Tracking changes preserve closed-period history and recalculate the active period. Cadence and rollover remain immutable. An allocation change applies from today to every later period, except that a period holding a one-off amount keeps it and only its regular allocation moves; to change a single period, use update_budget_period.')]
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
            'expense_type' => $this->expenseTypeSchema($schema),
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
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
        ]);

        $changes = $this->requestedChanges($request);
        if ($changes === []) {
            return Response::error('Provide a mutable budget field.');
        }

        $space = $this->resolveSpace($request, $user);
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

    /**
     * The mutable fields the request carries, read only when present: only
     * what the agent passes changes.
     *
     * @return array<string, mixed>
     */
    private function requestedChanges(Request $request): array
    {
        $readers = [
            'name' => fn (): string => $request->string('name')->toString(),
            'allocated_amount' => fn (): int => $request->integer('allocated_amount'),
            'category_ids' => fn (): array => $request->array('category_ids'),
            'label_ids' => fn (): array => $request->array('label_ids'),
            'expense_type' => fn (): ?BudgetExpenseType => $request->enum('expense_type', BudgetExpenseType::class),
        ];

        return collect($readers)
            ->filter(fn (callable $read, string $field): bool => $request->has($field))
            ->map(fn (callable $read): mixed => $read())
            ->all();
    }
}
