<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Models\MonthlySummary;
use App\Services\MonthlySummary\AnalysisWriter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Ai\Exceptions\ProviderOverloadedException;

beforeEach(function (): void {
    // Two attempts keep the back-off inside the test's patience while still
    // exercising the "more than one" path the retry loop exists for.
    config(['ai_monthly_summary.attempts' => 2]);
});

it('reports once when no attempt reaches the provider', function (): void {
    // Making a connection failure transient also made a provider that is never
    // reachable invisible: with logs off, the per-attempt warning only lands in
    // Sentry as a breadcrumb on somebody else's event. Exhausting every attempt
    // is its own event - the reader just lost their analysis for the month.
    Exceptions::fake();
    MonthlySummaryAgent::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    $summary = MonthlySummary::factory()->create();

    expect(app(AnalysisWriter::class)->draft($summary, $summary->user))->toBeNull();

    Exceptions::assertReportedCount(1);
});

it('does not report a hiccup the next attempt recovers from', function (): void {
    Exceptions::fake();

    // A sequence array cannot express this: the fake only advances its index
    // once a response is marshalled, so an entry that throws is handed back
    // again on the next attempt. Count the calls here instead.
    $attempt = 0;
    MonthlySummaryAgent::fake(function () use (&$attempt): string {
        $attempt++;

        if ($attempt === 1) {
            throw ProviderOverloadedException::forProvider('gemini');
        }

        return 'Cerraste el mes en verde.';
    });

    $summary = MonthlySummary::factory()->create();

    expect(app(AnalysisWriter::class)->draft($summary, $summary->user))
        ->toBe('Cerraste el mes en verde.');

    Exceptions::assertNothingReported();
});

it('reports a provider bug immediately instead of spending the attempts on it', function (): void {
    // A misconfigured provider or an SDK change is not a hiccup: retrying it
    // would only waste the window, so it is reported on the first throw.
    Exceptions::fake();
    MonthlySummaryAgent::fake(fn () => throw new RuntimeException('unknown model'));

    $summary = MonthlySummary::factory()->create();

    expect(app(AnalysisWriter::class)->draft($summary, $summary->user))->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'unknown model');
    Exceptions::assertReportedCount(1);
});
