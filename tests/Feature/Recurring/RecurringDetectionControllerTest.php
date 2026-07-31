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
