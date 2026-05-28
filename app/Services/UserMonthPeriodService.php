<?php

namespace App\Services;

use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class UserMonthPeriodService
{
    /** @var list<int> */
    public const ALLOWED_START_DAYS = [1, 25, 26, 27, 28];

    public function startDay(User $user): int
    {
        $startDay = (int) ($user->month_start_day ?? 1);

        return in_array($startDay, self::ALLOWED_START_DAYS, true) ? $startDay : 1;
    }

    /**
     * @return array{from: Carbon, to: Carbon, end_inclusive: Carbon}
     */
    public function current(User $user, ?CarbonInterface $date = null): array
    {
        return $this->monthContaining($user, $date ?? Carbon::now());
    }

    /**
     * @return array{from: Carbon, to: Carbon, end_inclusive: Carbon}
     */
    public function monthContaining(User $user, CarbonInterface $date): array
    {
        $start = $this->monthStartOnOrBefore(Carbon::instance($date->toDateTime()), $this->startDay($user));
        $to = $start->copy()->addMonthNoOverflow();

        return [
            'from' => $start,
            'to' => $to,
            'end_inclusive' => $to->copy()->subDay(),
        ];
    }

    private function monthStartOnOrBefore(Carbon $date, int $startDay): Carbon
    {
        $start = $date->copy()->startOfDay()->day($startDay);

        if ($start->gt($date)) {
            $start->subMonthNoOverflow();
        }

        return $start;
    }
}
