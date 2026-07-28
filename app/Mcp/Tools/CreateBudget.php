<?php

namespace App\Mcp\Tools;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Mcp\Tools\Concerns\InteractsWithBudgets;
use App\Models\User;
use App\Services\BudgetManagementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Create a recurring budget. Amounts use minor units. Historical assignment runs in the background; tracking, cadence and rollover are fixed after creation.')]
class CreateBudget extends WriteTool
{
    use InteractsWithBudgets;

    public function __construct(private readonly BudgetManagementService $budgets) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Budget name.')->required(),
            'period_type' => $schema->string()->enum(array_column(BudgetPeriodType::cases(), 'value'))->description('Budget cadence.')->required(),
            'period_start_day' => $schema->integer()->description('Monthly: 1-31. Weekly/biweekly: 0-6. Yearly: 1.'),
            'category_ids' => $schema->array()->items($schema->string())->description('Tracked category ids.'),
            'label_ids' => $schema->array()->items($schema->string())->description('Tracked label ids.'),
            'rollover_type' => $schema->string()->enum(array_column(RolloverType::cases(), 'value'))->description('Carry remaining amount or reset.')->required(),
            'allocated_amount' => $schema->integer()->min(0)->description('Allocation in minor units.')->required(),
            'is_catch_all' => $schema->boolean()->description('Track all otherwise unclaimed expenses.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'period_start_day' => ['nullable', 'integer'],
            'category_ids' => ['array'],
            'category_ids.*' => ['string'],
            'label_ids' => ['array'],
            'label_ids.*' => ['string'],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['required', 'integer', 'min:0'],
            'is_catch_all' => ['sometimes', 'boolean'],
        ]);

        $periodType = $request->enum('period_type', BudgetPeriodType::class);
        $this->validateStartDay($periodType, $request->integer('period_start_day'));
        $periodStartDay = $request->has('period_start_day')
            ? $request->integer('period_start_day')
            : ($periodType === BudgetPeriodType::Yearly ? 1 : null);
        $space = $this->resolveSpace($request, $user);
        $result = $this->budgets->create(
            $user,
            $space,
            [
                'name' => $request->string('name')->toString(),
                'period_type' => $periodType->value,
                'period_start_day' => $periodStartDay,
                'rollover_type' => $request->enum('rollover_type', RolloverType::class)->value,
                'allocated_amount' => $request->integer('allocated_amount'),
                'is_catch_all' => $request->boolean('is_catch_all'),
            ],
            $request->array('category_ids'),
            $request->array('label_ids'),
        );
        [$current, $next] = $this->periodsFor($result['budget']);

        return $this->json([
            'space_id' => $space->id,
            'budget' => $this->presentBudget($result['budget'], $current, $next),
            'processing_historical' => true,
        ]);
    }

    private function validateStartDay(BudgetPeriodType $periodType, ?int $startDay): void
    {
        $valid = match ($periodType) {
            BudgetPeriodType::Monthly => $startDay === null || ($startDay >= 1 && $startDay <= 31),
            BudgetPeriodType::Weekly, BudgetPeriodType::Biweekly => $startDay !== null && $startDay >= 0 && $startDay <= 6,
            BudgetPeriodType::Yearly => $startDay === null || $startDay === 1,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['period_start_day' => 'The start day is invalid for the selected cadence.']);
        }
    }
}
