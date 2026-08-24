<?php

use App\Support\QueueWorkerLoop;

function queueLostConnection(): PDOException
{
    return new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
}

it('is not polling before the worker has looped once', function () {
    expect(new QueueWorkerLoop)->isPolling()->toBeFalse();
});

it('is polling once the daemon starts a turn of its loop', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();

    expect($loop)->isPolling()->toBeTrue();
});

it('stops polling while a job is running', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();
    $loop->enterJob();

    expect($loop)->isPolling()->toBeFalse();
});

/** This is the `stopIfNecessary()` window: after the job, before the next turn. */
it('is polling again once the job is over', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();
    $loop->enterJob();
    $loop->leaveJob();

    expect($loop)->isPolling()->toBeTrue();
});

/** A worker killed mid-job never fires JobProcessed, so the next turn has to clear it. */
it('clears a job left in flight when the next turn starts', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();
    $loop->enterJob();
    $loop->enterLoop();

    expect($loop)->isPolling()->toBeTrue();
});

it('treats a lost connection during the poll as noise', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();

    expect($loop->isLostConnectionWhilePolling(queueLostConnection()))->toBeTrue();
});

it('reports a lost connection raised inside a job', function () {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();
    $loop->enterJob();

    expect($loop->isLostConnectionWhilePolling(queueLostConnection()))->toBeFalse();
});

/** No `Looping` ever fires in a web request, which is what keeps HTTP reportable. */
it('reports a lost connection outside the worker loop', function () {
    expect((new QueueWorkerLoop)->isLostConnectionWhilePolling(queueLostConnection()))->toBeFalse();
});

it('recognises the wordings a restart produces', function (Throwable $exception, bool $expected) {
    $loop = new QueueWorkerLoop;
    $loop->enterLoop();

    expect($loop->isLostConnectionWhilePolling($exception))->toBe($expected);
})->with([
    'mysql gone away' => [fn () => queueLostConnection(), true],
    'mysql lost connection' => [fn () => new PDOException('SQLSTATE[HY000] [2013] Lost connection to MySQL server'), true],
    'redis refused' => [fn () => new RedisException('Connection refused'), true],
    'redis reset' => [fn () => new RedisException('Connection reset by peer'), true],
    'an actual query error is not noise' => [fn () => new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'transactions' doesn't exist"), false],
    'another exception type is not noise' => [fn () => new RuntimeException('MySQL server has gone away'), false],
]);
