<?php

use App\Features\CustomMonthStartDay;
use App\Models\User;
use App\Services\UserMonthPeriodService;
use Carbon\Carbon;
use Laravel\Pennant\Feature;

function customStartDayUser(int $startDay): User
{
    $user = User::factory()->create(['month_start_day' => $startDay]);
    Feature::for($user)->activate(CustomMonthStartDay::class);

    return $user;
}

it('returns natural month periods by default', function () {
    $user = customStartDayUser(1);
    $period = app(UserMonthPeriodService::class)->monthContaining($user, Carbon::parse('2026-02-14'));

    expect($period['from']->toDateString())->toBe('2026-02-01')
        ->and($period['to']->toDateString())->toBe('2026-03-01')
        ->and($period['end_inclusive']->toDateString())->toBe('2026-02-28');
});

it('returns salary month periods for custom start days', function () {
    $user = customStartDayUser(25);
    $period = app(UserMonthPeriodService::class)->monthContaining($user, Carbon::parse('2026-02-14'));
    $previousPeriod = app(UserMonthPeriodService::class)->monthContaining($user, $period['from']->copy()->subDay());

    expect($period['from']->toDateString())->toBe('2026-01-25')
        ->and($period['to']->toDateString())->toBe('2026-02-25')
        ->and($period['end_inclusive']->toDateString())->toBe('2026-02-24')
        ->and($previousPeriod['from']->toDateString())->toBe('2025-12-25')
        ->and($previousPeriod['to']->toDateString())->toBe('2026-01-25');
});

it('starts a new salary month on the configured day', function () {
    $user = customStartDayUser(28);
    $period = app(UserMonthPeriodService::class)->monthContaining($user, Carbon::parse('2026-02-28'));

    expect($period['from']->toDateString())->toBe('2026-02-28')
        ->and($period['to']->toDateString())->toBe('2026-03-28');
});

it('falls back to the first for invalid stored values', function () {
    $user = customStartDayUser(2);

    expect(app(UserMonthPeriodService::class)->startDay($user))->toBe(1);
});

it('ignores the stored start day while the feature is disabled', function () {
    $user = User::factory()->create(['month_start_day' => 25]);

    $service = app(UserMonthPeriodService::class);
    $period = $service->monthContaining($user, Carbon::parse('2026-02-14'));

    expect($service->startDay($user))->toBe(1)
        ->and($period['from']->toDateString())->toBe('2026-02-01')
        ->and($period['end_inclusive']->toDateString())->toBe('2026-02-28');
});
