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
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless(Feature::for($user)->active(RecurringTransactions::class), 404);

        $jobId = (string) Str::uuid();

        Cache::put(
            DetectRecurringSeriesJob::cacheKeyForJobId($user->id, $jobId),
            ['status' => 'queued', 'series_count' => 0],
            now()->addMinutes(30),
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
