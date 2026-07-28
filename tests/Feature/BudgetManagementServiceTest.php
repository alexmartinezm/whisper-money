<?php

use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\User;
use App\Services\BudgetManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function managedBudgetWithPeriods(User $user, string $spaceId, int $startDay = 1): Budget
{
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
        'period_start_day' => $startDay,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $budget->categories()->attach($category->id);

    return $budget;
}

function addManagedPeriod(Budget $budget, string $start, string $end, int $amount = 1000, int $carry = 0): BudgetPeriod
{
    return $budget->periods()->create([
        'start_date' => $start,
        'end_date' => $end,
        'allocated_amount' => $amount,
        'carried_over_amount' => $carry,
        'processing_historical' => false,
    ]);
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('updates the current and future monthly periods on their application date', function () {
    Queue::fake();
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000, 200);
    addManagedPeriod($budget, '2026-09-01', '2026-09-30');
    addManagedPeriod($budget, '2026-10-01', '2026-10-31');

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-08-01'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeTrue()
        ->and($result['adjustment']['affected_period_count'])->toBe(3)
        ->and($result['adjustment']['effective_from'])->toBe('2026-08-01');
    expect($budget->periods()->where('allocated_amount', 2500)->count())->toBe(3)
        ->and($budget->periods()->where('start_date', '2026-08-01')->value('carried_over_amount'))->toBe(200);
});

it('preserves the current period when a monthly update is applied after its start', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000);
    addManagedPeriod($budget, '2026-09-01', '2026-09-30');

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-08-02'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeFalse()
        ->and($budget->periods()->where('start_date', '2026-08-01')->value('allocated_amount'))->toBe(1000)
        ->and($budget->periods()->where('start_date', '2026-09-01')->value('allocated_amount'))->toBe(2500);
});

it('uses the real start date for displaced monthly periods and creates a successor when needed', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id, 15);
    addManagedPeriod($budget, '2026-08-15', '2026-09-14', 1000);

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-09-01'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeFalse()
        ->and($budget->periods()->where('start_date', '2026-08-15')->value('allocated_amount'))->toBe(1000)
        ->and($budget->periods()->where('start_date', '2026-09-15')->value('allocated_amount'))->toBe(2500);
});

it('reconciles notification flags without sending notifications during allocation edits', function () {
    Queue::fake();
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $current = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000);
    $current->update(['close_to_limit_notified' => true, 'over_limit_notified' => true]);

    app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2000],
        CarbonImmutable::parse('2026-08-01'),
    );

    expect($current->fresh()->close_to_limit_notified)->toBeFalse()
        ->and($current->fresh()->over_limit_notified)->toBeFalse();
});

it('rejects a budget update without a mutable field', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);

    expect(fn () => app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        [],
        CarbonImmutable::parse('2026-08-01'),
    ))->toThrow(ValidationException::class);
});
