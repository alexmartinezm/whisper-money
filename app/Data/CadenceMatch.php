<?php

namespace App\Data;

use App\Enums\RecurringCadence;

/**
 * A recognised repetition: which cadence the gaps look like, and the observed
 * median gap that projects the next occurrence. The observed gap is kept
 * alongside the cadence because a "monthly" charge lands on 28–31 day gaps and
 * projecting from the canonical 30 would drift over a year.
 */
final readonly class CadenceMatch
{
    public function __construct(
        public RecurringCadence $cadence,
        public int $medianGapDays,
    ) {}
}
