<?php

use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\User;
use App\Services\BudgetManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

it('updates only the direct successor allocation', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $july = addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $august = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);
    $september = addManagedPeriod($budget, '2026-09-01', '2026-09-30', 40000);

    app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $august->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    );

    expect($august->fresh()->allocated_amount)->toBe(45000)
        ->and($july->fresh()->allocated_amount)->toBe(40000)
        ->and($september->fresh()->allocated_amount)->toBe(40000);
});

it('seeds the following period with the pre-edit planning allocation', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $august = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);

    app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $august->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    );

    expect($budget->periods()->whereDate('start_date', '2026-09-01')->value('allocated_amount'))->toBe(40000);
});

it('rejects planning updates for any period except the direct successor', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $july = addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);
    $september = addManagedPeriod($budget, '2026-09-01', '2026-09-30', 40000);

    foreach ([$july->id, $september->id] as $periodId) {
        expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
            $user,
            $user->personalSpace,
            $budget->id,
            $periodId,
            45000,
            CarbonImmutable::parse('2026-07-30'),
        ))->toThrow(ModelNotFoundException::class);
    }
});

it('rejects planning updates for a budget belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherBudget = managedBudgetWithPeriods($otherUser, $otherUser->personalSpace->id);
    addManagedPeriod($otherBudget, '2026-07-01', '2026-07-31', 40000);
    $otherPeriod = addManagedPeriod($otherBudget, '2026-08-01', '2026-08-31', 40000);

    expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $otherBudget->id,
        $otherPeriod->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    ))->toThrow(ValidationException::class);
});

it('rejects planning updates for a period belonging to another budget', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $otherBudget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $otherPeriod = addManagedPeriod($otherBudget, '2026-08-01', '2026-08-31', 40000);

    expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $otherPeriod->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    ))->toThrow(ModelNotFoundException::class);
});
