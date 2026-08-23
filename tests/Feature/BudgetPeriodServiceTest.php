<?php

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
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

test('closePeriod carries the leftover into the period that already follows', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);
    // The one the command keeps ahead. It is here so that "the period that
    // follows" cannot pass by accident: with a single successor, taking the
    // nearest and taking the farthest are the same row.
    $september = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $july->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july->fresh());

    // The leftover belongs on the period the user is spending in now, not on a
    // fresh one appended past the end of the chain and not on the one after it.
    expect($august->fresh()->carried_over_amount)->toBe(6000)
        ->and($september->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(3);
});

test('closePeriod never carries into another budget', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);

    // Nothing follows July on this budget, so the only period that follows
    // July anywhere in the table belongs to somebody else.
    $otherBudget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $someoneElses = BudgetPeriod::factory()->create([
        'budget_id' => $otherBudget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $july->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july->fresh());

    expect($someoneElses->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});

test('closePeriod leaves nothing behind for a budget that resets each period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::Reset,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july);

    expect($august->fresh()->carried_over_amount)->toBe(0);
});

test('closePeriod stops appending periods when the same period is closed again', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 10000,
    ]);

    $service = app(BudgetPeriodService::class);
    $service->closePeriod($july);
    $service->closePeriod($july);
    $service->closePeriod($july);

    expect(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});

test('closePeriod builds the missing successor next to the closed period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    $july = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 10000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($july);

    $created = BudgetPeriod::where('budget_id', $budget->id)
        ->where('start_date', '>', $july->end_date)
        ->sole();

    expect($created->start_date->toDateString())->toBe('2026-08-01');
});

test('closePeriod finds a successor that starts on the closed period last day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 0,
        'rollover_type' => RolloverType::CarryOver,
    ]);

    // Periods that touch rather than tile. 25 live budgets look like this - the
    // ones whose start day is 0 or past the 28th, where `calculatePeriodDates`
    // resolves the day to the end of the previous month.
    $ended = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-06-30',
        'end_date' => '2026-07-30',
        'allocated_amount' => 10000,
    ]);
    $current = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-30',
        'end_date' => '2026-08-30',
        'allocated_amount' => 10000,
    ]);

    BudgetTransaction::factory()->create([
        'budget_period_id' => $ended->id,
        'amount' => 4000,
    ]);

    app(BudgetPeriodService::class)->closePeriod($ended->fresh());

    // Skipping that shared day used to send the fallback back through
    // `calculatePeriodDates`, which resolved to the closed period itself - so
    // the leftover was carried onto the period it came from.
    expect($current->fresh()->carried_over_amount)->toBe(6000)
        ->and($ended->fresh()->carried_over_amount)->toBe(0)
        ->and(BudgetPeriod::where('budget_id', $budget->id)->count())->toBe(2);
});

test('generatePreviousPeriod hands back the period immediately before', function (
    BudgetPeriodType $type,
    int $startDay,
    string $today,
    string $expectedStart,
    string $expectedEnd,
) {
    Carbon::setTestNow(Carbon::parse("{$today} 09:00:00"));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => $type,
        'period_start_day' => $startDay,
    ]);

    $service = app(BudgetPeriodService::class);
    $current = $service->generatePeriod($budget, 10000, Carbon::parse($today));
    $previous = $service->generatePreviousPeriod($budget, $current);

    expect($previous->start_date->toDateString())->toBe($expectedStart)
        ->and($previous->end_date->toDateString())->toBe($expectedEnd)
        // The point of the whole thing: the two must meet, not overlap. Both are
        // handed to AssignHistoricalTransactionsToBudget, so a shared day is a
        // transaction counted twice.
        ->and($previous->end_date->copy()->addDay()->toDateString())
        ->toBe($current->start_date->toDateString());
})->with([
    // A fortnight rewound to a weekday lands mid-fortnight: every one of the 14
    // live biweekly budgets in production overlaps its predecessor by a week.
    'biweekly' => [BudgetPeriodType::Biweekly, 0, '2026-08-20', '2026-08-02', '2026-08-15'],
    'weekly' => [BudgetPeriodType::Weekly, 0, '2026-08-20', '2026-08-09', '2026-08-15'],
    // March is the month that breaks any day-counting version of this: 31 days
    // back from 1 March is January, and February disappears.
    'monthly across a short month' => [BudgetPeriodType::Monthly, 1, '2026-03-15', '2026-02-01', '2026-02-28'],
    'monthly across a 30-day month' => [BudgetPeriodType::Monthly, 1, '2026-05-15', '2026-04-01', '2026-04-30'],
    'monthly across a year boundary' => [BudgetPeriodType::Monthly, 1, '2026-01-15', '2025-12-01', '2025-12-31'],
    'yearly' => [BudgetPeriodType::Yearly, 1, '2026-08-20', '2025-01-01', '2025-12-31'],
]);

test('getCurrentPeriod picks the on-grid window when two periods cover today', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-20 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Biweekly,
        'period_start_day' => 0,
    ]);

    // The shape every biweekly budget created before this fix carries: a
    // spurious period starting a week early, overlapping the real one.
    $spurious = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-09',
        'end_date' => '2026-08-22',
        'allocated_amount' => 10000,
    ]);
    $onGrid = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-16',
        'end_date' => '2026-08-29',
        'allocated_amount' => 10000,
    ]);

    // Unordered, MySQL hands back the earliest start first - the window the
    // user never configured, and one `BudgetController::show` cannot navigate
    // out of.
    expect($budget->getCurrentPeriod()->id)->toBe($onGrid->id)
        ->and($budget->getCurrentPeriod()->id)->not->toBe($spurious->id);
});

test('generatePreviousPeriod does not overlap a budget anchored past the 28th', function () {
    Carbon::setTestNow(Carbon::parse('2026-03-29 09:00:00'));

    $budget = Budget::factory()->create([
        'user_id' => User::factory()->create(['onboarded_at' => now()])->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 29,
    ]);

    $service = app(BudgetPeriodService::class);
    $current = $service->generatePeriod($budget, 10000, Carbon::parse('2026-03-29'));
    $previous = $service->generatePreviousPeriod($budget, $current);

    // Upstream leaves a gap here, owned by its missing ceiling on the start day;
    // monthlyBoundary caps the anchor at the length of the month, so February
    // starts on the 28th and the two windows are contiguous. What both versions
    // must guarantee is the absence of an overlap - stepping back with an
    // overflowing subMonth() would return 03-01..03-31 over a current
    // 03-29..04-28.
    expect($current->start_date->toDateString())->toBe('2026-03-29')
        ->and($previous->start_date->toDateString())->toBe('2026-02-28')
        ->and($previous->end_date->toDateString())->toBe('2026-03-28')
        ->and($previous->end_date->lt($current->start_date))->toBeTrue();
});
