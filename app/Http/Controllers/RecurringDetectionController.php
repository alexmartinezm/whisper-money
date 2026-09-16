<?php

namespace App\Http\Controllers;

use App\Features\RecurringTransactions;
use App\Jobs\DetectRecurringSeriesJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Lets the recurring screen ask for a fresh scan. Detection walks a year of
 * history, so it is queued and polled rather than run inside the request.
 */
class RecurringDetectionController extends Controller
{
    private const STATUS_TTL_MINUTES = 30;

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(Feature::for($user)->active(RecurringTransactions::class), 404);

        $jobId = (string) Str::uuid();
        $activeKey = DetectRecurringSeriesJob::activeJobKey($user->id);
        $claimLock = Cache::lock(DetectRecurringSeriesJob::claimLockKey($user->id), 30);

        if (! $claimLock->get()) {
            return response()->json(['message' => 'A recurring scan is already being claimed.'], 409);
        }

        try {
            $runningId = (string) Cache::get($activeKey);
            $running = $runningId === ''
                ? null
                : Cache::get(DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $runningId));

            if (is_array($running) && in_array($running['status'] ?? null, ['queued', 'processing'], true)) {
                return response()->json(['job_id' => $runningId], 200);
            }

            Cache::put($activeKey, $jobId, now()->addMinutes(self::STATUS_TTL_MINUTES));
            Cache::put(
                DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $jobId),
                ['status' => 'queued', 'series_count' => 0],
                now()->addMinutes(self::STATUS_TTL_MINUTES),
            );

            DetectRecurringSeriesJob::dispatch($user, $jobId);

            return response()->json(['job_id' => $jobId], 202);
        } catch (Throwable $exception) {
            if (Cache::get($activeKey) === $jobId) {
                Cache::forget($activeKey);
                Cache::forget(DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $jobId));
            }

            throw $exception;
        } finally {
            $claimLock->release();
        }
    }

    public function status(Request $request, string $jobId): JsonResponse
    {
        $progress = Cache::get(
            DetectRecurringSeriesJob::cacheKeyForJobId($request->user()->id, $jobId),
        );

        if ($progress === null) {
            return response()->json(['message' => 'Job not found.'], 404);
        }

        return response()->json($progress);
    }
}
