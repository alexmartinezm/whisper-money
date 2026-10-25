<?php

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateBudgetPeriod;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callBudgetWriteTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp-budget-test', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('creates a budget with periods and queues historical assignment after the transaction', function () {
    Queue::fake();
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    $response = callBudgetWriteTool($user, CreateBudget::class, [
        'name' => 'Household',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 100000,
    ]);

    $response->assertOk()->assertSee('Household')->assertSee('processing_historical');

    $budget = Budget::query()->where('user_id', $user->id)->where('name', 'Household')->firstOrFail();
    expect($budget->space_id)->toBe($space->id)
        ->and($budget->periods()->count())->toBe(2);
    Queue::assertPushed(AssignHistoricalTransactionsToBudget::class, 2);
});

it('rejects categories from another user or space when creating a budget', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignCategory = Category::factory()->create([
        'user_id' => $other->id,
        'space_id' => $other->personalSpace->id,
    ]);

    callBudgetWriteTool($user, CreateBudget::class, [
        'name' => 'Forbidden',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$foreignCategory->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 100,
    ])->assertHasErrors();

    expect(Budget::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('updates only the name and future allocation and reports the adjustment', function () {
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    callBudgetWriteTool($user, CreateBudget::class, [
        'name' => 'Original',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 500,
    ])->assertOk();
    $budget = Budget::query()->where('name', 'Original')->firstOrFail();

    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'name' => 'Renamed',
        'allocated_amount' => 750,
    ])->assertOk()->assertSee('Renamed')->assertSee('affected_period_count');

    $budget->refresh();
    expect($budget->name)->toBe('Renamed')
        ->and($budget->periods()->where('allocated_amount', 750)->exists())->toBeTrue();
});

it('updates budget categories and labels through MCP', function () {
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $oldCategory = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $newCategory = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $budget = Budget::factory()->monthly()->forCategories($oldCategory)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);

    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'category_ids' => [$newCategory->id],
        'label_ids' => [$label->id],
    ])->assertOk()->assertSee($newCategory->name)->assertSee($label->name);

    expect($budget->fresh()->categories->modelKeys())->toBe([$newCategory->id])
        ->and($budget->fresh()->labels->modelKeys())->toBe([$label->id]);
});

it('rejects budget tracking references from another space through MCP', function () {
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $oldCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);
    $foreignCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $otherSpace->id,
    ]);
    $budget = Budget::factory()->monthly()->forCategories($oldCategory)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);

    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'category_ids' => [$foreignCategory->id],
    ])->assertHasErrors();

    expect($budget->fresh()->categories->modelKeys())->toBe([$oldCategory->id]);
});

it('sets the allocation of a single period through MCP', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    callBudgetWriteTool($user, CreateBudget::class, [
        'name' => 'Trips',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 50000,
    ])->assertOk();
    $budget = Budget::query()->where('name', 'Trips')->firstOrFail();

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => '2026-10-01',
        'allocated_amount' => 70000,
    ])->assertOk()->assertSee('following_period')->assertSee('2026-11-01');

    expect($budget->periods()->whereDate('start_date', '2026-10-01')->value('allocated_amount'))->toBe(70000)
        ->and($budget->periods()->whereDate('start_date', '2026-11-01')->value('allocated_amount'))->toBe(50000);
});

it('keeps a one-off period through a regular allocation change and resets it through MCP', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    callBudgetWriteTool($user, CreateBudget::class, [
        'name' => 'Trips',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 50000,
    ])->assertOk();
    $budget = Budget::query()->where('name', 'Trips')->firstOrFail();
    $november = fn () => $budget->periods()->whereDate('start_date', '2026-11-01')->firstOrFail();

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => '2026-11-01',
        'allocated_amount' => 70000,
    ])->assertOk()->assertSee('"is_one_off":true');

    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'allocated_amount' => 55000,
    ])->assertOk()->assertSee('one_off_period_ids')->assertSee($november()->id);

    expect($november()->allocated_amount)->toBe(70000)
        ->and($november()->regularAllocatedAmount())->toBe(55000);

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => '2026-11-01',
        'reset' => true,
    ])->assertOk();

    expect($november()->allocated_amount)->toBe(55000)
        ->and($november()->hasOneOffAllocation())->toBeFalse();
});

it('asks for either an amount or a reset', function (array $arguments) {
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $user = User::factory()->create();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'period_start_day' => 1,
    ]);

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => '2026-10-01',
        ...$arguments,
    ])->assertHasErrors();

    expect($budget->periods()->count())->toBe(0);
})->with([
    'neither' => [[]],
    'both' => [['allocated_amount' => 70000, 'reset' => true]],
]);

it('rejects a one-off allocation the tool cannot apply', function (string $date) {
    $this->travelTo(CarbonImmutable::parse('2026-10-15'));
    $user = User::factory()->create();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'period_start_day' => 1,
    ]);
    $budget->periods()->create([
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'allocated_amount' => 50000,
        'carried_over_amount' => 0,
    ]);

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => $date,
        'allocated_amount' => 70000,
    ])->assertHasErrors();

    expect($budget->periods()->where('allocated_amount', 70000)->exists())->toBeFalse();
})->with([
    'a closed period' => '2026-09-10',
    'a malformed date' => '01/10/2026',
]);

it('rejects a one-off allocation on a budget outside the selected space', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $other->id,
        'space_id' => $other->personalSpace->id,
    ]);

    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => now()->toDateString(),
        'allocated_amount' => 70000,
    ])->assertHasErrors();

    expect($budget->periods()->count())->toBe(0);
});

it('does not materialize periods while listing budgets', function () {
    $user = User::factory()->create();
    $space = $user->personalSpace;
    Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'period_start_day' => 1,
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk();

    expect(Budget::query()->where('user_id', $user->id)->firstOrFail()->periods()->count())->toBe(0);
});

it('soft deletes a budget without deleting its periods', function () {
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'rollover_type' => RolloverType::Reset,
    ]);
    $period = $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 100,
        'carried_over_amount' => 0,
    ]);

    callBudgetWriteTool($user, DeleteBudget::class, ['budget_id' => $budget->id])->assertOk();

    expect(Budget::query()->find($budget->id))->toBeNull()
        ->and(Budget::withTrashed()->find($budget->id))->not->toBeNull()
        ->and($period->fresh())->not->toBeNull();
});

it('rejects attempts to update a budget outside the selected space', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $other->id,
        'space_id' => $other->personalSpace->id,
    ]);

    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'name' => 'No access',
    ])->assertHasErrors();

    expect($budget->fresh()->name)->not->toBe('No access');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mcpBudgetArguments(Category $category, array $overrides = []): array
{
    return [
        'name' => 'Rent',
        'period_type' => BudgetPeriodType::Monthly->value,
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => RolloverType::Reset->value,
        'allocated_amount' => 100000,
        ...$overrides,
    ];
}

it('creates a budget with the expense type the agent sends, variable by default', function () {
    Queue::fake();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, ['expense_type' => 'fixed']))
        ->assertOk()
        ->assertSee('"expense_type":"fixed"', false);
    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, ['name' => 'Groceries']))
        ->assertOk()
        ->assertSee('"expense_type":"variable"', false);

    expect(Budget::query()->where('name', 'Rent')->sole()->expense_type)->toBe(BudgetExpenseType::Fixed)
        ->and(Budget::query()->where('name', 'Groceries')->sole()->expense_type)->toBe(BudgetExpenseType::Variable);
});

it('keeps a catch-all budget variable whatever expense type the agent sends', function () {
    Queue::fake();
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, [
        'name' => 'Everything else',
        'category_ids' => [],
        'is_catch_all' => true,
        'expense_type' => 'fixed',
    ]))->assertOk();
    $catchAll = Budget::query()->where('name', 'Everything else')->sole();

    callBudgetWriteTool($user, UpdateBudget::class, ['budget_id' => $catchAll->id, 'expense_type' => 'fixed'])
        ->assertOk()
        ->assertSee('"expense_type":"variable"', false);

    expect($catchAll->fresh()->expense_type)->toBe(BudgetExpenseType::Variable);
});

it('changes only the expense type through MCP', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'name' => 'Rent',
    ]);

    callBudgetWriteTool($user, UpdateBudget::class, ['budget_id' => $budget->id, 'expense_type' => 'fixed'])
        ->assertOk()
        ->assertSee('"expense_type":"fixed"', false);

    expect($budget->fresh())
        ->expense_type->toBe(BudgetExpenseType::Fixed)
        ->name->toBe('Rent');
});

it('keeps the expense type through MCP tracking and allocation changes', function () {
    Queue::fake();
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, ['expense_type' => 'fixed']))->assertOk();
    $budget = Budget::query()->where('name', 'Rent')->sole();

    callBudgetWriteTool($user, UpdateBudget::class, ['budget_id' => $budget->id, 'label_ids' => [$label->id]])->assertOk();
    callBudgetWriteTool($user, UpdateBudget::class, ['budget_id' => $budget->id, 'allocated_amount' => 120000])->assertOk();
    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => today()->toDateString(),
        'allocated_amount' => 150000,
    ])->assertOk()->assertSee('"expense_type":"fixed"', false);

    expect($budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed);
});

it('rejects an unknown expense type through MCP', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, UpdateBudget::class, ['budget_id' => $budget->id, 'expense_type' => 'occasional'])
        ->assertHasErrors();

    expect($budget->fresh()->expense_type)->toBe(BudgetExpenseType::Variable);
});

it('lists each budget with its expense type', function () {
    $user = User::factory()->create();
    Budget::factory()->monthly()->fixed()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('"expense_type":"fixed"', false);
});

it('creates a quarterly budget through MCP with or without the start day', function (array $startDay) {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-12'));
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);
    $arguments = mcpBudgetArguments($category, ['name' => 'Car maintenance', 'period_type' => BudgetPeriodType::Quarterly->value]);
    unset($arguments['period_start_day']);

    callBudgetWriteTool($user, CreateBudget::class, [...$arguments, ...$startDay])
        ->assertOk()
        ->assertSee('"period_type":"quarterly"', false)
        ->assertSee('"period_start_day":1', false);

    $budget = Budget::query()->where('name', 'Car maintenance')->sole();

    expect($budget->period_start_day)->toBe(1)
        ->and($budget->periods()->orderBy('start_date')->get()->map(fn ($period): array => [$period->start_date->toDateString(), $period->end_date->toDateString()])->all())
        ->toBe([['2026-07-01', '2026-09-30'], ['2026-10-01', '2026-12-31']]);
})->with([
    'omitted' => [[]],
    'the first' => [['period_start_day' => 1]],
    'null' => [['period_start_day' => null]],
]);

it('rejects a quarterly start day other than the first through MCP', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, [
        'period_type' => BudgetPeriodType::Quarterly->value,
        'period_start_day' => 15,
    ]))->assertHasErrors();

    expect(Budget::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('changes a quarterly allocation from the next quarter and keeps a one-off quarter through MCP', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-12'));
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, [
        'name' => 'Car maintenance',
        'period_type' => BudgetPeriodType::Quarterly->value,
        'allocated_amount' => 60000,
    ]))->assertOk();
    $budget = Budget::query()->where('name', 'Car maintenance')->sole();
    $quarter = fn (string $start) => $budget->periods()->whereDate('start_date', $start)->firstOrFail();

    // A one-off quarter: the period holding 2027-02-10, with the one after it
    // left on the regular allocation.
    callBudgetWriteTool($user, UpdateBudgetPeriod::class, [
        'budget_id' => $budget->id,
        'date' => '2027-02-10',
        'allocated_amount' => 90000,
    ])->assertOk()->assertSee('"is_one_off":true', false)->assertSee('2027-04-01');

    expect($quarter('2027-01-01')->end_date->toDateString())->toBe('2027-03-31')
        ->and($quarter('2027-01-01')->allocated_amount)->toBe(90000)
        ->and($quarter('2027-04-01')->allocated_amount)->toBe(60000);

    // Mid-quarter, a regular change leaves the quarter in progress alone and
    // only moves the one-off quarter's regular allocation.
    callBudgetWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'allocated_amount' => 75000,
    ])->assertOk()->assertSee('"effective_from":"2027-01-01"', false);

    expect($quarter('2026-10-01')->allocated_amount)->toBe(60000)
        ->and($quarter('2027-01-01')->allocated_amount)->toBe(90000)
        ->and($quarter('2027-01-01')->regularAllocatedAmount())->toBe(75000)
        ->and($quarter('2027-04-01')->allocated_amount)->toBe(75000);
});

it('creates a yearly budget through MCP without a start day', function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-12'));
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);
    $arguments = mcpBudgetArguments($category, ['name' => 'Insurance', 'period_type' => BudgetPeriodType::Yearly->value]);
    unset($arguments['period_start_day']);

    callBudgetWriteTool($user, CreateBudget::class, $arguments)->assertOk();

    $budget = Budget::query()->where('name', 'Insurance')->sole();

    expect($budget->period_start_day)->toBe(1)
        ->and($budget->getCurrentPeriod()->start_date->toDateString())->toBe('2026-01-01');
});

it('creates a weekly budget through MCP starting on Sunday, given as 0 or left out', function (array $startDay, ?int $storedStartDay) {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-12'));
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);
    $arguments = mcpBudgetArguments($category, ['name' => 'Groceries', 'period_type' => BudgetPeriodType::Weekly->value]);
    unset($arguments['period_start_day']);

    callBudgetWriteTool($user, CreateBudget::class, [...$arguments, ...$startDay])->assertOk();

    $budget = Budget::query()->where('name', 'Groceries')->sole();

    expect($budget->period_start_day)->toBe($storedStartDay)
        ->and($budget->getCurrentPeriod()->start_date->toDateString())->toBe('2026-11-08');
})->with([
    'Sunday as 0' => [['period_start_day' => 0], 0],
    'omitted' => [[], null],
    'null' => [['period_start_day' => null], null],
]);

it('rejects a biweekly start day outside 0-6 through MCP', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $user->personalSpace->id]);

    callBudgetWriteTool($user, CreateBudget::class, mcpBudgetArguments($category, [
        'period_type' => BudgetPeriodType::Biweekly->value,
        'period_start_day' => 7,
    ]))->assertHasErrors();

    expect(Budget::query()->where('user_id', $user->id)->exists())->toBeFalse();
});
