<?php

namespace App\Data;

use App\Models\RecurringSeries;

/**
 * A subscription that used to cost one thing and now costs more.
 *
 * Both amounts are magnitudes in minor units, not the signed ledger figures:
 * the whole point of the message is "this went up", and a comparison between
 * two negatives reads backwards everywhere it is displayed.
 */
final readonly class RecurringPriceRise
{
    public function __construct(
        public RecurringSeries $series,
        public int $previousAmount,
        public int $currentAmount,
    ) {}

    public function increase(): int
    {
        return $this->currentAmount - $this->previousAmount;
    }

    /** The rise as a whole-number percentage, for the copy. */
    public function percentage(): int
    {
        return $this->previousAmount > 0
            ? (int) round($this->increase() / $this->previousAmount * 100)
            : 0;
    }

    /** What the change adds over a year at this cadence. */
    public function yearlyImpact(): int
    {
        return (int) round($this->increase() * $this->series->cadence->monthlyFactor() * 12);
    }
}
