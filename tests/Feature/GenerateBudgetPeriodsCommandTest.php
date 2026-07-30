<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use Carbon\Carbon;

afterEach(fn () => Carbon::setTestNow());

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
