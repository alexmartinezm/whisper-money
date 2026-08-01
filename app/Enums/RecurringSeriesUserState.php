<?php

namespace App\Enums;

enum RecurringSeriesUserState: string
{
    case Detected = 'detected';
    case Confirmed = 'confirmed';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Detected => 'Detected',
            self::Confirmed => 'Confirmed',
            self::Ignored => 'Ignored',
        };
    }
}
