<?php

use App\Enums\RecurringCadence;
use Carbon\CarbonImmutable;

it('keeps a monthly charge on its day of the month', function () {
    $september = CarbonImmutable::parse('2026-09-05');

    expect(RecurringCadence::Monthly->advance($september, 5)->toDateString())
        ->toBe('2026-10-05');
});

it('does not walk a monthly charge forward across a year', function () {
    $date = CarbonImmutable::parse('2026-09-05');

    foreach (range(1, 12) as $ignored) {
        $date = RecurringCadence::Monthly->advance($date, 5);
    }

    // Projected in days this lands in mid-September, because twelve thirty-day
    // steps are five days short of a year.
    expect($date->toDateString())->toBe('2027-09-05');
});

it('brings an end-of-month charge back after a short month', function () {
    // The contract is billed on the last day. February clips it to the 28th,
    // and March has to return to the 31st rather than stay clipped.
    $dates = [];
    $date = CarbonImmutable::parse('2026-01-31');

    foreach (range(1, 4) as $ignored) {
        $date = RecurringCadence::Monthly->advance($date, 31);
        $dates[] = $date->toDateString();
    }

    expect($dates)->toBe(['2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31']);
});

it('measures weekly and biweekly cadences in days', function () {
    $monday = CarbonImmutable::parse('2026-09-07');

    expect(RecurringCadence::Weekly->advance($monday)->toDateString())->toBe('2026-09-14')
        ->and(RecurringCadence::Biweekly->advance($monday)->toDateString())->toBe('2026-09-21');
});

it('advances quarterly and yearly cadences by calendar months', function () {
    $date = CarbonImmutable::parse('2026-11-30');

    expect(RecurringCadence::Quarterly->advance($date, 30)->toDateString())->toBe('2027-02-28')
        ->and(RecurringCadence::Yearly->advance($date, 30)->toDateString())->toBe('2027-11-30');
});

it('does not pull a date that disagrees with the anchor', function () {
    // A stored next date that is neither on the anchor nor clipped to a month
    // end disagrees with it. Honouring the anchor here would turn a monthly
    // charge into a 20-day one, and a 30-day forecast would show it twice.
    expect(RecurringCadence::Monthly->advance(CarbonImmutable::parse('2026-09-21'), 11)->toDateString())
        ->toBe('2026-10-21');
});

it('falls back to the day of the date it is given', function () {
    // Rows written before the billing day was recorded still have to project
    // somewhere sensible.
    expect(RecurringCadence::Monthly->advance(CarbonImmutable::parse('2026-09-12'))->toDateString())
        ->toBe('2026-10-12');
});
