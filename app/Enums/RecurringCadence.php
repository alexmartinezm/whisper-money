<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum RecurringCadence: string
{
    case Weekly = 'weekly';
    case Biweekly = 'biweekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Biweekly => 'Bi-weekly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Yearly => 'Yearly',
        };
    }

    /**
     * The canonical spacing between occurrences, used to project the next one.
     */
    public function intervalDays(): int
    {
        return match ($this) {
            self::Weekly => 7,
            self::Biweekly => 14,
            self::Monthly => 30,
            self::Quarterly => 91,
            self::Yearly => 365,
        };
    }

    /**
     * The months this cadence spans, or null when it is measured in days.
     */
    public function monthsPerOccurrence(): ?int
    {
        return match ($this) {
            self::Weekly, self::Biweekly => null,
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
        };
    }

    /**
     * The next occurrence after a date.
     *
     * A monthly charge is billed on a day of the month, not every thirty days,
     * and projecting it in days walks it forward: rent taken on the 5th of
     * September lands on the 6th of October, then the 7th of November. Over a
     * year of projection the drift is larger than the tolerance the reminder
     * and the forecast are built on.
     *
     * `$anchorDay` is the day of the month the charge is really billed on. It
     * is re-applied from the start of each target month rather than carried
     * from the previous occurrence, because a contract billed on the 31st is
     * clipped to the 28th in February and has to come back to the 31st in
     * March — carrying the clipped day forward would erode the anchor for good.
     */
    public function advance(CarbonImmutable $from, ?int $anchorDay = null, int $times = 1): CarbonImmutable
    {
        $months = $this->monthsPerOccurrence();

        if ($months === null) {
            return $from->addDays($this->intervalDays() * $times);
        }

        $target = $from->startOfMonth()->addMonthsNoOverflow($months * $times);
        $day = $this->billingDay($from, $anchorDay);

        return $target->day(min($day, $target->daysInMonth));
    }

    /**
     * Which day the next occurrence should land on.
     *
     * The anchor wins when the date is already on it, and when the date is the
     * last day of its month — that is what a charge billed on the 31st looks
     * like in February, and returning it to the 31st in March is the whole
     * point of keeping an anchor.
     *
     * Anywhere else the date's own day wins. A date that is neither on the
     * anchor nor clipped to a month end disagrees with the anchor, and pulling
     * it across would invent a gap the contract does not have: advancing the
     * 21st towards an anchor on the 11th turns a monthly charge into a 20-day
     * one, and a projection would then show it twice inside a 30-day window.
     */
    private function billingDay(CarbonImmutable $from, ?int $anchorDay): int
    {
        if ($anchorDay === null) {
            return $from->day;
        }

        return $from->day === $anchorDay || $from->day === $from->daysInMonth
            ? $anchorDay
            : $from->day;
    }

    /**
     * How many times this cadence bills in an average month, so series of
     * different cadences can be compared and summed on one scale.
     */
    public function monthlyFactor(): float
    {
        return match ($this) {
            self::Weekly => 52 / 12,
            self::Biweekly => 26 / 12,
            self::Monthly => 1.0,
            self::Quarterly => 1 / 3,
            self::Yearly => 1 / 12,
        };
    }
}
