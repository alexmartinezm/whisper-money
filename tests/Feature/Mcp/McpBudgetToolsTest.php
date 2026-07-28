<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\UpdateBudget;
use App\Models\Budget;
use App\Models\Category;
use App\Models\User;
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
