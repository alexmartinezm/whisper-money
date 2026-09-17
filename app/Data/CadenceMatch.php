<?php

namespace App\Data;

use App\Enums\RecurringCadence;

/**
 * A recognised repetition: which cadence the gaps look like, and the observed
 * median gap between them.
 *
 * The gap no longer projects anything. Whether it is 28 days or 31, a monthly
 * charge is billed on a day of the month, and stepping any number of days from
 * the last one walks it through the calendar — thirty days after the 5th is the
 * 4th, then the 3rd. `RecurringCadence::advance()` moves by calendar months
 * instead. The observed gap is still worth keeping: it is what separates a
 * charge that repeats on a schedule from one that merely repeats, and the
 * outlook uses it to tell a long month from a longer cadence.
 */
final readonly class CadenceMatch
{
    public function __construct(
        public RecurringCadence $cadence,
        public int $medianGapDays,
    ) {}
}
