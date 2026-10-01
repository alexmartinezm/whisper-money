<?php

use App\Contracts\BankingConnectionSyncer;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\EnableBankingPsuContext;
use App\Services\Banking\Sync\BankingConnectionSyncerFactory;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response as HttpResponse;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function invokeManualPsuJobMethod(SyncBankingConnectionJob $job, string $method, mixed ...$arguments): mixed
{
    $reflection = new ReflectionMethod($job, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($job, ...$arguments);
}

function enableBankingPsuTestConnection(?Carbon $rateLimitedUntil = null): BankingConnection
{
    return new BankingConnection([
        'provider' => BankingProvider::EnableBanking,
        'rate_limited_until' => $rateLimitedUntil,
    ]);
}

it('only marks a fresh first manual job as interactive', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));

    $job = new SyncBankingConnectionJob(
        new BankingConnection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
    $connection = enableBankingPsuTestConnection();

    expect(property_exists(SyncBankingConnectionJob::class, 'psuContextDispatchedAt'))->toBeTrue()
        ->and($job->psuContextDispatchedAt)->toEqual(now())
        ->and(invokeManualPsuJobMethod($job, 'hasInteractivePsuContext', $connection))->toBeTrue();

    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);
    $job->job = $queueJob;

    expect(invokeManualPsuJobMethod($job, 'hasInteractivePsuContext', $connection))->toBeFalse();

    Carbon::setTestNow(Carbon::parse('2026-10-01 12:06:00'));
    $job->job = null;

    expect(invokeManualPsuJobMethod($job, 'hasInteractivePsuContext', $connection))->toBeFalse();
});

it('does not treat a manual job without a dispatch timestamp as interactive', function () {
    $job = new SyncBankingConnectionJob(
        new BankingConnection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
    $job->psuContextDispatchedAt = null;

    expect(invokeManualPsuJobMethod(
        $job,
        'hasInteractivePsuContext',
        enableBankingPsuTestConnection(),
    ))->toBeFalse();
});

it('preserves a later cooldown when a balance-limited success has no new deadline', function () {
    $backoff = now()->addHour();
    $connection = enableBankingPsuTestConnection($backoff);
    $job = new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );

    $resolved = invokeManualPsuJobMethod($job, 'balanceRateLimitBackoff', $connection, []);

    expect($resolved)->not->toBeNull()
        ->and($resolved->toDateTimeString())->toBe($backoff->toDateTimeString());
});

it('uses the later of an existing cooldown and a balance rate-limit deadline', function () {
    $backoff = now()->addHour();
    $connection = enableBankingPsuTestConnection($backoff);
    $job = new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );

    $resolved = invokeManualPsuJobMethod($job, 'balanceRateLimitBackoff', $connection, [
        'balance_rate_limit' => [
            'retry_after' => '60',
            'message' => 'burst limit',
        ],
    ]);

    expect($resolved->toDateTimeString())->toBe($backoff->toDateTimeString());
});

it('uses the later cooldown when a manual 429 provides a shorter Retry-After', function () {
    $backoff = now()->addHour();
    $connection = enableBankingPsuTestConnection($backoff);
    $job = new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
    $exception = new RequestException(new HttpResponse(
        new Response(429, ['Retry-After' => '60'], '{}'),
    ));

    $resolved = invokeManualPsuJobMethod($job, 'resolveRateLimitBackoffUntil', $connection, $exception);

    expect($resolved->toDateTimeString())->toBe($backoff->toDateTimeString());
});

it('clears PSU headers before delayed and retried manual syncs', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));

    $connection = enableBankingPsuTestConnection();
    $connection->forceFill(['status' => BankingConnectionStatus::Active]);
    $connection->setRelation('user', new User([
        'id' => 'synthetic-user',
        'email' => 'synthetic@example.com',
    ]));

    $syncer = Mockery::mock(BankingConnectionSyncer::class);
    $syncer->shouldReceive('expires')->twice()->andReturnFalse();
    $factory = Mockery::mock(BankingConnectionSyncerFactory::class);
    $factory->shouldReceive('make')->twice()->andReturn($syncer);
    $context = new EnableBankingPsuContext;

    $delayedJob = new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
    $delayedJob->psuContextDispatchedAt = now()->subMinutes(6);
    $context->set('192.0.2.44', 'SyntheticBrowser/1.0');

    expect(invokeManualPsuJobMethod(
        $delayedJob,
        'prepareSync',
        $connection,
        $factory,
        $context,
        microtime(true),
    ))->toBe($syncer)
        ->and($context->headers())->toBe([]);

    $retriedJob = new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(2);
    $retriedJob->job = $queueJob;
    $context->set('192.0.2.44', 'SyntheticBrowser/1.0');

    expect(invokeManualPsuJobMethod(
        $retriedJob,
        'prepareSync',
        $connection,
        $factory,
        $context,
        microtime(true),
    ))->toBe($syncer)
        ->and($context->headers())->toBe([]);

    $rateLimitedConnection = enableBankingPsuTestConnection(now()->addHour());

    expect(invokeManualPsuJobMethod(
        $retriedJob,
        'shouldSkipRateLimited',
        $rateLimitedConnection,
        false,
    ))->toBeTrue()
        ->and(invokeManualPsuJobMethod(
            $retriedJob,
            'shouldSkipRateLimited',
            $rateLimitedConnection,
            true,
        ))->toBeFalse();
});

it('carries the manual PSU request identity through queue serialization', function () {
    expect(property_exists(SyncBankingConnectionJob::class, 'psuIpAddress'))->toBeTrue()
        ->and(property_exists(SyncBankingConnectionJob::class, 'psuUserAgent'))->toBeTrue();

    $job = new SyncBankingConnectionJob(
        new BankingConnection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );

    $serialized = $job->__serialize();

    expect($serialized['psuIpAddress'])->toBe('192.0.2.44')
        ->and($serialized['psuUserAgent'])->toBe('SyntheticBrowser/1.0');
});

it('does not serialize PSU identity for scheduled jobs', function () {
    $job = new SyncBankingConnectionJob(
        new BankingConnection,
        trigger: BankingSyncTrigger::Scheduled,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );

    $serialized = $job->__serialize();

    expect($job->psuIpAddress)->toBeNull()
        ->and($job->psuUserAgent)->toBeNull()
        ->and(array_key_exists('psuIpAddress', $serialized))->toBeFalse()
        ->and(array_key_exists('psuUserAgent', $serialized))->toBeFalse();
});
