<?php

use App\Support\QueueWorkerLoop;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;

/**
 * A deploy takes MySQL and Redis away underneath the queue workers, and every one
 * of the five in production reported it: one restart, five identical exceptions.
 * That was 103 of this project's 105 error events, and it buried the one failure
 * that was actually about this application.
 *
 * The real queue events are dispatched here rather than the flags being set by
 * hand, because half of what is under test is that AppServiceProvider listens to
 * the right ones.
 */
function workerWouldReport(Throwable $exception): bool
{
    return app(ExceptionHandler::class)->shouldReport($exception);
}

function anyJob(): Job
{
    return Mockery::mock(Job::class)->shouldIgnoreMissing();
}

function serverGoneAway(): PDOException
{
    return new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
}

beforeEach(function () {
    Event::dispatch(new Looping('database', 'default'));
});

it('does not report a database connection lost while the worker polls', function () {
    expect(workerWouldReport(serverGoneAway()))->toBeFalse();
});

it('does not report a cache connection refused while the worker polls', function () {
    expect(workerWouldReport(new RedisException('Connection refused')))->toBeFalse();
});

it('still reports a connection lost while a job is running', function () {
    Event::dispatch(new JobProcessing('database', anyJob()));

    expect(workerWouldReport(serverGoneAway()))->toBeTrue();
});

/**
 * The worker dispatches JobExceptionOccurred and only then reports, so this is
 * the case that decides whether a job's own lost connection is ever seen.
 */
it('still reports a connection lost by a job that threw', function () {
    Event::dispatch(new JobProcessing('database', anyJob()));
    Event::dispatch(new JobExceptionOccurred('database', anyJob(), serverGoneAway()));

    expect(workerWouldReport(serverGoneAway()))->toBeTrue();
});

/** `stopIfNecessary()` reads the cache here, after the job and before the next turn. */
it('goes back to ignoring the poll once a job has finished cleanly', function () {
    Event::dispatch(new JobProcessing('database', anyJob()));
    Event::dispatch(new JobProcessed('database', anyJob()));

    expect(workerWouldReport(serverGoneAway()))->toBeFalse();
});

/** A worker killed mid-job never fires JobProcessed, so the next turn clears it. */
it('ignores the poll again on the turn after a job that threw', function () {
    Event::dispatch(new JobProcessing('database', anyJob()));
    Event::dispatch(new Looping('database', 'default'));

    expect(workerWouldReport(serverGoneAway()))->toBeFalse();
});

it('still reports a real query failure from the same worker', function () {
    $error = new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'transactions' doesn't exist");

    expect(workerWouldReport($error))->toBeTrue();
});

/** `Looping` never fires in a web request, so nothing there is ever suppressed. */
it('reports a lost connection in a request that never touched the worker loop', function () {
    app()->forgetInstance(QueueWorkerLoop::class);

    expect(workerWouldReport(serverGoneAway()))->toBeTrue();
});
