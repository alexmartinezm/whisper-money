<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

function monthlyBudgetWithPeriods(array $months): Budget
{
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    foreach ($months as [$start, $end]) {
        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $end,
            'allocated_amount' => 10000,
        ]);
    }

    return $budget;
}

test('budget period generation fills the direct successor before extending a distant future period', function () {
    Carbon::setTestNow('2026-07-30');
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 90000,
    ]);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    expect(BudgetPeriod::query()
        ->where('budget_id', $budget->id)
        ->whereDate('start_date', '2026-08-01')
        ->value('allocated_amount'))->toBe(40000);
});

/**
 * The command used to append one period per already-ended period on every run,
 * so a budget's chain grew every night - in production one reached 6,165 periods
 * ending in the year 2143, and loading them all is what finally killed the
 * scheduled command with a fatal error.
 */
it('keeps the chain two periods ahead however often it runs', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = monthlyBudgetWithPeriods([
        ['2026-05-01', '2026-05-31'],
        ['2026-06-01', '2026-06-30'],
        ['2026-07-01', '2026-07-31'],
        ['2026-08-01', '2026-08-31'],
    ]);

    foreach (range(1, 3) as $ignored) {
        $this->artisan('budgets:generate-periods')->assertSuccessful();
    }

    $periods = BudgetPeriod::where('budget_id', $budget->id)
        ->orderBy('start_date')
        ->get();

    expect($periods)->toHaveCount(6)
        ->and($periods->last()->end_date->toDateString())->toBe('2026-10-31');
});

it('does not disturb the period the user is spending in', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = monthlyBudgetWithPeriods([
        ['2026-07-01', '2026-07-31'],
        ['2026-08-01', '2026-08-31'],
    ]);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    $current = $budget->fresh()->getCurrentPeriod();

    expect($current->start_date->toDateString())->toBe('2026-08-01')
        ->and($current->end_date->toDateString())->toBe('2026-08-31')
        ->and($current->allocated_amount)->toBe(10000);
});

it('restarts a budget whose last period ended months ago on today', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = monthlyBudgetWithPeriods([['2026-02-01', '2026-02-28']]);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    $current = $budget->fresh()->getCurrentPeriod();

    expect($current)->not->toBeNull()
        ->and($current->start_date->toDateString())->toBe('2026-08-01');
});
