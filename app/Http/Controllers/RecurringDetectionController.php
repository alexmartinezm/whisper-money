<?php

namespace App\Http\Controllers;

use App\Features\RecurringTransactions;
use App\Jobs\DetectRecurringSeriesJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

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

        // The job is unique per user, so a second request would otherwise get a
        // job id whose job is dropped and whose status never leaves "queued".
        // Claim atomically; if someone else holds the claim and their scan is
        // still running, hand back their id so both callers poll the same run.
        if (! Cache::add($activeKey, $jobId, now()->addMinutes(self::STATUS_TTL_MINUTES))) {
            $runningId = (string) Cache::get($activeKey);
            $running = Cache::get(DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $runningId));

            if ($running !== null && in_array($running['status'], ['queued', 'processing'], true)) {
                return response()->json(['job_id' => $runningId], 200);
            }

            // The claim outlived its job; take it over.
            Cache::put($activeKey, $jobId, now()->addMinutes(self::STATUS_TTL_MINUTES));
        }

        Cache::put(
            DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $jobId),
            ['status' => 'queued', 'series_count' => 0],
            now()->addMinutes(self::STATUS_TTL_MINUTES),
        );

        DetectRecurringSeriesJob::dispatch($user, $jobId);

        return response()->json(['job_id' => $jobId], 202);
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
