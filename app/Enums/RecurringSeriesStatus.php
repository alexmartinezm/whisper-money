<?php

namespace App\Enums;

enum RecurringSeriesStatus: string
{
    case Active = 'active';
    case Lapsed = 'lapsed';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Lapsed => 'Lapsed',
        };
    }
}
