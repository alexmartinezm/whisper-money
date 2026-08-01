<?php

namespace App\Enums;

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
