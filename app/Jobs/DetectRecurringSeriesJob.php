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

    /**
     * Holds the id of the scan currently in flight for a user, so a second
     * request joins it instead of minting a job id that ShouldBeUnique will
     * silently drop.
     */
    public static function activeJobKey(string $userId): string
    {
        return "detect_recurring_series_active_{$userId}";
    }

    public function handle(DetectRecurringSeries $detector): void
    {
        $this->publish(['status' => 'processing', 'series_count' => 0]);

        try {
            $count = $detector->forUserEverywhere($this->user);
        } catch (Throwable $exception) {
            $this->publish(['status' => 'failed', 'series_count' => 0]);
            $this->release();

            throw $exception;
        }

        $this->publish(['status' => 'completed', 'series_count' => $count]);
        $this->release();
    }

    public function failed(?Throwable $exception): void
    {
        $this->publish(['status' => 'failed', 'series_count' => 0]);
        $this->release();
    }

    /**
     * Release the in-flight claim so the next rescan can start straight away.
     */
    private function release(): void
    {
        Cache::forget(self::activeJobKey($this->user->id));
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
