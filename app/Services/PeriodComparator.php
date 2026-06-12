<?php

namespace App\Services;

use Carbon\Carbon;

class PeriodComparator
{
    public function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to
    ) {}

    public function previous(): self
    {
        $from = $this->from->copy()->startOfDay();
        $exclusiveEnd = $this->to->copy()->startOfDay()->addDay();

        $months = (int) $from->diffInMonths($exclusiveEnd);

        // Period boundaries are anchored to a month start day (calendar or
        // custom), so whole-month ranges shift by months rather than by day
        // count. This keeps the previous period correctly aligned even when
        // adjacent months differ in length (e.g. a 25th-to-24th salary month).
        if ($months >= 1) {
            return new self(
                $from->copy()->subMonthsNoOverflow($months),
                $from->copy()->subDay()->endOfDay(),
            );
        }

        $days = (int) $from->diffInDays($exclusiveEnd);

        return new self(
            $from->copy()->subDays($days),
            $from->copy()->subDay()->endOfDay(),
        );
    }

    public static function fromRequest(array $validated): self
    {
        return new self(
            Carbon::parse($validated['from']),
            Carbon::parse($validated['to'])
        );
    }
}
