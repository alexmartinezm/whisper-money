<?php

use App\Services\PeriodComparator;
use Carbon\Carbon;

function comparator(string $from, string $to): PeriodComparator
{
    return new PeriodComparator(Carbon::parse($from), Carbon::parse($to));
}

it('shifts a natural calendar month back a whole month', function () {
    $previous = comparator('2026-02-01', '2026-02-28')->previous();

    expect($previous->from->toDateString())->toBe('2026-01-01')
        ->and($previous->to->toDateString())->toBe('2026-01-31');
});

it('shifts a 31-day salary month back to the previous salary month', function () {
    $previous = comparator('2026-01-25', '2026-02-24')->previous();

    expect($previous->from->toDateString())->toBe('2025-12-25')
        ->and($previous->to->toDateString())->toBe('2026-01-24');
});

it('shifts a short salary month back without losing the payday days', function () {
    // Feb 25 - Mar 24 is only 28 days; a day-count shift would land on Jan 27
    // and drop Jan 25-26. The previous salary month must still start on the 25th.
    $previous = comparator('2026-02-25', '2026-03-24')->previous();

    expect($previous->from->toDateString())->toBe('2026-01-25')
        ->and($previous->to->toDateString())->toBe('2026-02-24');
});

it('treats an end-inclusive end-of-day bound as the same period', function () {
    $period = new PeriodComparator(
        Carbon::parse('2026-01-25')->startOfDay(),
        Carbon::parse('2026-02-24')->endOfDay(),
    );

    $previous = $period->previous();

    // The 23:59:59.999999 upper bound must not bleed an extra day into the
    // previous window (which would start on Dec 24 instead of Dec 25).
    expect($previous->from->toDateString())->toBe('2025-12-25')
        ->and($previous->to->toDateString())->toBe('2026-01-24');
});

it('shifts a quarter back a full quarter', function () {
    $previous = comparator('2026-01-25', '2026-04-24')->previous();

    expect($previous->from->toDateString())->toBe('2025-10-25')
        ->and($previous->to->toDateString())->toBe('2026-01-24');
});

it('shifts a year back a full year', function () {
    $previous = comparator('2026-01-25', '2027-01-24')->previous();

    expect($previous->from->toDateString())->toBe('2025-01-25')
        ->and($previous->to->toDateString())->toBe('2026-01-24');
});

it('falls back to a day-count shift for sub-month ranges', function () {
    $previous = comparator('2026-03-10', '2026-03-16')->previous();

    expect($previous->from->toDateString())->toBe('2026-03-03')
        ->and($previous->to->toDateString())->toBe('2026-03-09');
});
