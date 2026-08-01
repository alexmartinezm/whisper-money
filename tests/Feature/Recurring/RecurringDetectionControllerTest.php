<?php

use App\Features\RecurringTransactions;
use App\Jobs\DetectRecurringSeriesJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarded_at' => now()]);
});

it('queues a rescan and reports it as queued', function () {
    Queue::fake();

    $response = $this->actingAs($this->user)
        ->postJson('/recurring/detect')
        ->assertStatus(202)
        ->assertJsonStructure(['job_id']);

    Queue::assertPushed(DetectRecurringSeriesJob::class);

    $jobId = $response->json('job_id');

    $this->actingAs($this->user)
        ->getJson("/recurring/detect/status/{$jobId}")
        ->assertOk()
        ->assertJson(['status' => 'queued']);
});

it('reports a completed scan', function () {
    Queue::fake();

    $jobId = $this->actingAs($this->user)->postJson('/recurring/detect')->json('job_id');

    Cache::put(
        DetectRecurringSeriesJob::cacheKeyForJobId($this->user->id, $jobId),
        ['status' => 'completed', 'series_count' => 3],
        now()->addMinutes(5),
    );

    $this->actingAs($this->user)
        ->getJson("/recurring/detect/status/{$jobId}")
        ->assertOk()
        ->assertJson(['status' => 'completed', 'series_count' => 3]);
});

it('joins the running scan instead of minting a job that will be dropped', function () {
    Queue::fake();

    $first = $this->actingAs($this->user)->postJson('/recurring/detect')
        ->assertStatus(202)
        ->json('job_id');

    // The job is unique per user, so a second request must hand back the
    // running id rather than one whose job the queue will discard.
    $second = $this->actingAs($this->user)->postJson('/recurring/detect')
        ->assertOk()
        ->json('job_id');

    expect($second)->toBe($first);
    Queue::assertPushed(DetectRecurringSeriesJob::class, 1);
});

it('starts a fresh scan once the previous one finished', function () {
    Queue::fake();

    $first = $this->actingAs($this->user)->postJson('/recurring/detect')->json('job_id');

    Cache::put(
        DetectRecurringSeriesJob::cacheKeyForJobId($this->user->id, $first),
        ['status' => 'completed', 'series_count' => 2],
        now()->addMinutes(5),
    );
    Cache::forget(DetectRecurringSeriesJob::activeJobKey($this->user->id));

    $second = $this->actingAs($this->user)->postJson('/recurring/detect')
        ->assertStatus(202)
        ->json('job_id');

    expect($second)->not->toBe($first);
});

it('takes over a claim whose job left no status behind', function () {
    Queue::fake();

    // A crashed worker can leave the claim without any status entry.
    Cache::put(DetectRecurringSeriesJob::activeJobKey($this->user->id), 'stale-id', now()->addMinutes(30));

    $jobId = $this->actingAs($this->user)->postJson('/recurring/detect')
        ->assertStatus(202)
        ->json('job_id');

    expect($jobId)->not->toBe('stale-id');
    Queue::assertPushed(DetectRecurringSeriesJob::class);
});

it('returns 404 for an unknown job', function () {
    $this->actingAs($this->user)
        ->getJson('/recurring/detect/status/does-not-exist')
        ->assertNotFound();
});

it('does not expose another user job status', function () {
    Queue::fake();
    $other = User::factory()->create(['onboarded_at' => now()]);

    $jobId = $this->actingAs($other)->postJson('/recurring/detect')->json('job_id');

    $this->actingAs($this->user)
        ->getJson("/recurring/detect/status/{$jobId}")
        ->assertNotFound();
});

it('returns 404 when the feature is disabled', function () {
    Feature::for($this->user)->deactivate(RecurringTransactions::class);

    $this->actingAs($this->user)->postJson('/recurring/detect')->assertNotFound();
});
