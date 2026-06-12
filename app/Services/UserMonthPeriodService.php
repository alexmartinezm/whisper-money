<?php

namespace App\Services;

use App\Features\CustomMonthStartDay;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Laravel\Pennant\Feature;

class UserMonthPeriodService
{
    /** @var list<int> */
    public const ALLOWED_START_DAYS = [1, 25, 26, 27, 28];

    public function startDay(User $user): int
    {
        // The custom start day only takes effect while the feature is active,
        // so rolling the flag back cleanly reverts everyone to calendar months
        // instead of stranding them on a salary month they can no longer edit.
        if (! Feature::for($user)->active(CustomMonthStartDay::class)) {
            return 1;
        }

        $startDay = (int) ($user->month_start_day ?? 1);

        return in_array($startDay, self::ALLOWED_START_DAYS, true) ? $startDay : 1;
    }

    /**
     * @return array{from: Carbon, to: Carbon, end_inclusive: Carbon}
     */
    public function current(User $user, ?CarbonInterface $date = null): array
    {
        return $this->monthContaining($user, $date ?? Carbon::now($user->timezone));
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
            'end_inclusive' => $to->copy()->subDay()->endOfDay(),
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
