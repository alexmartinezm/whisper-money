<?php

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetPeriodService;
use Carbon\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

test('generatePeriod advances monthly periods to next month', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-01',
        'end_date' => '2026-05-31',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-06-01');
    expect($next->end_date->toDateString())->toBe('2026-06-30');
});

test('generatePeriod advances weekly periods to next week', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-12 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Weekly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-05-04',
        'end_date' => '2026-05-10',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-05-11');
    expect($next->end_date->toDateString())->toBe('2026-05-17');
});

test('generatePeriod advances yearly periods to next year', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-02 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Yearly,
        'period_start_day' => 1,
    ]);

    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'allocated_amount' => 10000,
    ]);

    $next = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($next->start_date->toDateString())->toBe('2026-01-01');
    expect($next->end_date->toDateString())->toBe('2026-12-31');
});

test('generatePeriod uses period_start_day snap when no prior periods exist', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($period->start_date->toDateString())->toBe('2026-05-01');
    expect($period->end_date->toDateString())->toBe('2026-05-31');
});

test('generatePeriod is idempotent when a period already exists for the start date', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
    ]);

    $existing = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-01',
        'end_date' => '2026-06-30',
        'allocated_amount' => 44500,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget, 100, Carbon::parse('2026-06-15'));

    expect($period->id)->toBe($existing->id);
    expect($period->allocated_amount)->toBe(44500);
    expect(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(1);
});

test('generatePeriod creates current calendar year when yearly budget has no prior periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-05-15 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Yearly,
        'period_start_day' => 1,
    ]);

    $period = app(BudgetPeriodService::class)->generatePeriod($budget);

    expect($period->start_date->toDateString())->toBe('2026-01-01');
    expect($period->end_date->toDateString())->toBe('2026-12-31');
});

test('monthly day 31 clamps to the last day of short months without gaps or overlap', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 31,
    ]);

    $service = app(BudgetPeriodService::class);
    [$januaryStart, $januaryEnd] = $service->calculatePeriodDates($budget, Carbon::parse('2026-01-15'));
    [$februaryStart, $februaryEnd] = $service->calculatePeriodDates($budget, Carbon::parse('2026-02-15'));

    expect($januaryStart->toDateString())->toBe('2025-12-31')
        ->and($januaryEnd->toDateString())->toBe('2026-01-30')
        ->and($februaryStart->toDateString())->toBe('2026-01-31')
        ->and($februaryEnd->toDateString())->toBe('2026-02-27');
});

test('monthly day 30 clamps February in leap and non-leap years', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 30,
    ]);

    $service = app(BudgetPeriodService::class);
    [$leapStart, $leapEnd] = $service->calculatePeriodDates($budget, Carbon::parse('2028-02-15'));
    [$nonLeapStart, $nonLeapEnd] = $service->calculatePeriodDates($budget, Carbon::parse('2027-02-15'));

    expect($leapStart->toDateString())->toBe('2028-01-30')
        ->and($leapEnd->toDateString())->toBe('2028-02-28')
        ->and($nonLeapStart->toDateString())->toBe('2027-01-30')
        ->and($nonLeapEnd->toDateString())->toBe('2027-02-27');
});

test('ensureSuccessor materializes the direct next monthly period from its preceding allocation', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);
    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);

    $successor = app(BudgetPeriodService::class)->ensureSuccessor($budget, $july, 40000);

    expect($successor->start_date->toDateString())->toBe('2026-08-01')
        ->and($successor->end_date->toDateString())->toBe('2026-08-31')
        ->and($successor->allocated_amount)->toBe(40000);
});

test('ensureSuccessor is idempotent for the direct next period', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);
    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);

    $service = app(BudgetPeriodService::class);
    $first = $service->ensureSuccessor($budget, $july, 40000);
    $second = $service->ensureSuccessor($budget, $july, 50000);

    expect($second->id)->toBe($first->id)
        ->and($second->allocated_amount)->toBe(40000)
        ->and(BudgetPeriod::query()
            ->where('budget_id', $budget->id)
            ->whereDate('start_date', '2026-08-01')
            ->count())->toBe(1);
});

test('ensureSuccessor fills the immediate gap even when a distant future period exists', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);
    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'allocated_amount' => 90000,
    ]);

    $successor = app(BudgetPeriodService::class)->ensureSuccessor($budget, $july, 40000);

    expect($successor->start_date->toDateString())->toBe('2026-08-01')
        ->and($successor->allocated_amount)->toBe(40000)
        ->and(BudgetPeriod::query()
            ->where('budget_id', $budget->id)
            ->whereDate('start_date', '2026-10-01')
            ->value('allocated_amount'))->toBe(90000);
});
