<?php

use App\Enums\RecurringCadence;
use App\Services\Recurring\CadenceClassifier;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->classifier = new CadenceClassifier;
});

/**
 * @param  list<string>  $dates
 * @return list<CarbonImmutable>
 */
function dates(array $dates): array
{
    return array_map(fn (string $date): CarbonImmutable => CarbonImmutable::parse($date), $dates);
}

it('recognises a weekly cadence', function () {
    $match = $this->classifier->classify(dates([
        '2026-05-04', '2026-05-11', '2026-05-18', '2026-05-25',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Weekly)
        ->and($match->medianGapDays)->toBe(7);
});

it('recognises a biweekly cadence', function () {
    $match = $this->classifier->classify(dates([
        '2026-04-03', '2026-04-17', '2026-05-01', '2026-05-15',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Biweekly)
        ->and($match->medianGapDays)->toBe(14);
});

it('recognises a monthly cadence across months of different lengths', function () {
    $match = $this->classifier->classify(dates([
        '2026-01-15', '2026-02-15', '2026-03-15', '2026-04-15', '2026-05-15',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Monthly);
});

it('recognises a monthly cadence billed at the end of the month', function () {
    $match = $this->classifier->classify(dates([
        '2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30', '2026-05-31',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Monthly);
});

it('recognises a quarterly cadence', function () {
    $match = $this->classifier->classify(dates([
        '2025-08-10', '2025-11-10', '2026-02-10', '2026-05-10',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Quarterly);
});

it('recognises a yearly cadence', function () {
    $match = $this->classifier->classify(dates([
        '2024-03-01', '2025-03-01', '2026-03-01',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Yearly);
});

it('rejects irregular gaps', function () {
    $match = $this->classifier->classify(dates([
        '2026-01-03', '2026-01-19', '2026-03-02', '2026-03-05', '2026-05-28',
    ]));

    expect($match)->toBeNull();
});

it('rejects a sequence with only one gap', function () {
    $match = $this->classifier->classify(dates(['2026-04-01', '2026-05-01']));

    expect($match)->toBeNull();
});

it('rejects an empty sequence', function () {
    expect($this->classifier->classify([]))->toBeNull();
});

it('survives a duplicate charge in one month because it uses the median', function () {
    // A retried payment adds a zero-day gap. A mean would be dragged far enough
    // to lose the cadence; the median should not notice.
    $match = $this->classifier->classify(dates([
        '2026-01-10', '2026-02-10', '2026-02-10', '2026-03-10', '2026-04-10', '2026-05-10',
    ]));

    expect($match->cadence)->toBe(RecurringCadence::Monthly);
});

it('rejects a merchant visited at random', function () {
    $match = $this->classifier->classify(dates([
        '2026-05-02', '2026-05-03', '2026-05-09', '2026-05-21', '2026-05-22',
    ]));

    expect($match)->toBeNull();
});
