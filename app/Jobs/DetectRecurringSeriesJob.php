<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Recurring\DetectRecurringSeries;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Re-runs detection for one user on demand, so the screen can offer a rescan
 * without blocking the request. Progress is published to the cache and polled,
 * mirroring the automation-rule application flow.
 */
class DetectRecurringSeriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const STATUS_TTL_MINUTES = 30;

    public function __construct(public User $user, public string $jobId) {}

    public function uniqueId(): string
    {
        return $this->user->id;
    }

    public static function cacheKeyForJobId(string $userId, string $jobId): string
    {
        return "detect_recurring_series_job_{$userId}_{$jobId}";
    }

    public function handle(DetectRecurringSeries $detector): void
    {
        $this->publish(['status' => 'processing', 'series_count' => 0]);

        try {
            $count = $detector->forUserEverywhere($this->user);
        } catch (Throwable $exception) {
            $this->publish(['status' => 'failed', 'series_count' => 0]);

            throw $exception;
        }

        $this->publish(['status' => 'completed', 'series_count' => $count]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->publish(['status' => 'failed', 'series_count' => 0]);
    }

    /** @param  array{status: string, series_count: int}  $progress */
    private function publish(array $progress): void
    {
        Cache::put(
            self::cacheKeyForJobId($this->user->id, $this->jobId),
            $progress,
            now()->addMinutes(self::STATUS_TTL_MINUTES),
        );
    }
}
