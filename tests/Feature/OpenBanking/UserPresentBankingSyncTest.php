<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingSyncLogStatus;
use App\Enums\BankingSyncTrigger;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\BankingSyncLog;
use App\Models\User;
use App\Services\Banking\EnableBankingPsuContext;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An EnableBanking connection whose background access the bank has cut off for
 * the next three hours: the state a Sync Now from the user has to get through.
 *
 * @param  array<string, mixed>  $attributes
 */
function backedOffEnableBankingConnection(array $attributes = []): BankingConnection
{
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'last_synced_at' => now()->subDay(),
        'rate_limited_until' => now()->addHours(3),
        'error_message' => 'Rate limit exceeded. Please wait a few minutes and try again.',
        'consecutive_sync_failures' => 0,
        ...$attributes,
    ]);

    Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-1',
    ]);

    app()->instance(BankingProviderInterface::class, enableBankingProviderForTest());

    return $connection;
}

function userPresentSync(BankingConnection $connection): SyncBankingConnectionJob
{
    return new SyncBankingConnectionJob(
        $connection,
        trigger: BankingSyncTrigger::Manual,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    );
}

/**
 * @param  array<string, mixed>|Closure  $transactions
 * @param  array<string, mixed>|Closure  $balances
 */
function fakeEnableBanking(array|Closure $transactions = [], array|Closure $balances = []): void
{
    Http::fake([
        'api.enablebanking.com/accounts/*/transactions*' => $transactions instanceof Closure
            ? $transactions
            : Http::response($transactions ?: ['transactions' => [], 'continuation_key' => null]),
        'api.enablebanking.com/accounts/*/balances*' => $balances instanceof Closure
            ? $balances
            : Http::response($balances ?: ['balances' => []]),
    ]);
}

function latestSyncLog(BankingConnection $connection): BankingSyncLog
{
    return BankingSyncLog::query()
        ->where('banking_connection_id', $connection->id)
        ->latest('created_at')
        ->firstOrFail();
}

test('a manual sync the user is present for reaches the bank through the background backoff with their PSU headers', function () {
    $connection = backedOffEnableBankingConnection([
        'interactive_rate_limited_until' => now()->subMinute(),
    ]);
    $backoff = $connection->rate_limited_until;
    fakeEnableBanking();

    runSync(userPresentSync($connection));

    expect(Http::recorded())->not->toBeEmpty();

    foreach (Http::recorded() as [$request]) {
        /** @var Request $request */
        expect($request->header('Psu-Ip-Address'))->toBe(['192.0.2.44'])
            ->and($request->header('Psu-User-Agent'))->toBe(['SyntheticBrowser/1.0']);
    }

    $connection->refresh();

    // The bank still meters our own access as before, so the scheduled sync
    // keeps waiting while the user's own fetch went through.
    expect($connection->last_synced_at->isToday())->toBeTrue()
        ->and($connection->rate_limited_until->toDateTimeString())->toBe($backoff->toDateTimeString())
        ->and($connection->interactive_rate_limited_until)->toBeNull()
        ->and(latestSyncLog($connection)->status)->toBe(BankingSyncLogStatus::Success)
        ->and(latestSyncLog($connection)->metadata)->toMatchArray(['trigger' => 'manual', 'user_present' => true])
        ->and(app(EnableBankingPsuContext::class)->headers())->toBe([]);
});

test('a scheduled sync never claims the user is present', function () {
    $connection = backedOffEnableBankingConnection(['rate_limited_until' => null]);
    fakeEnableBanking();

    runSync(new SyncBankingConnectionJob($connection));

    expect(Http::recorded())->not->toBeEmpty();

    foreach (Http::recorded() as [$request]) {
        expect($request->hasHeader('Psu-Ip-Address'))->toBeFalse()
            ->and($request->hasHeader('Psu-User-Agent'))->toBeFalse();
    }

    expect(latestSyncLog($connection)->metadata)->not->toHaveKey('user_present');
});

test('a 429 to a user-present call holds back Sync Now without shortening the background backoff', function () {
    $this->freezeTime();

    $connection = backedOffEnableBankingConnection();
    $backoff = $connection->rate_limited_until;
    fakeEnableBanking(transactions: fn () => Http::response(
        ['code' => 429, 'message' => 'Too many requests'],
        429,
        ['Retry-After' => '120'],
    ));

    runSync(userPresentSync($connection));

    $connection->refresh();

    expect($connection->rate_limited_until->toDateTimeString())->toBe($backoff->toDateTimeString())
        ->and($connection->interactive_rate_limited_until->toDateTimeString())
        ->toBe(now()->addSeconds(120)->toDateTimeString())
        ->and($connection->isRateLimitedFor(userPresent: true))->toBeTrue();
});

test('a 429 to a user-present balance call holds back Sync Now and still keeps the run', function () {
    $this->freezeTime();

    $connection = backedOffEnableBankingConnection();
    $backoff = $connection->rate_limited_until;
    fakeEnableBanking(balances: fn () => Http::response(
        ['code' => 429, 'message' => 'Too many requests'],
        429,
        ['Retry-After' => '60'],
    ));

    runSync(userPresentSync($connection));

    $connection->refresh();

    expect($connection->last_synced_at->isToday())->toBeTrue()
        ->and($connection->rate_limited_until->toDateTimeString())->toBe($backoff->toDateTimeString())
        ->and($connection->interactive_rate_limited_until->toDateTimeString())
        ->toBe(now()->addSeconds(60)->toDateTimeString());
});

test('a user-present run keeps the background backoff even when it ends outside the PSU window', function () {
    $connection = backedOffEnableBankingConnection();
    $backoff = $connection->rate_limited_until;
    $job = userPresentSync($connection);
    $job->psuContextDispatchedAt = now()->subSeconds(290);

    // The run starts ten seconds inside the window and ends twenty past it.
    fakeEnableBanking(transactions: function () {
        $this->travel(30)->seconds();

        return Http::response(['transactions' => [], 'continuation_key' => null]);
    });

    runSync($job);

    $connection->refresh();

    expect($connection->last_synced_at)->not->toBeNull()
        ->and($connection->rate_limited_until->toDateTimeString())->toBe($backoff->toDateTimeString());
});

test('the retry of a manual sync inside the PSU window is still the user-present request', function () {
    $connection = backedOffEnableBankingConnection();
    $job = userPresentSync($connection);
    $job->job = Mockery::mock(Job::class);
    $job->job->shouldReceive('attempts')->andReturn(2);
    fakeEnableBanking();

    runSync($job);

    expect(Http::recorded())->not->toBeEmpty()
        ->and(Http::recorded()[0][0]->header('Psu-Ip-Address'))->toBe(['192.0.2.44'])
        ->and(latestSyncLog($connection)->status)->toBe(BankingSyncLogStatus::Success);
});

test('a manual sync that cannot vouch for the user waits out the background backoff', function (Closure $strip) {
    $connection = backedOffEnableBankingConnection();
    $backoff = $connection->rate_limited_until;
    $job = userPresentSync($connection);
    $strip($job);
    fakeEnableBanking();

    runSync($job);

    Http::assertNothingSent();

    $log = latestSyncLog($connection);

    expect($log->status)->toBe(BankingSyncLogStatus::Skipped)
        ->and($log->metadata['reason'])->toBe('rate_limited')
        ->and($connection->refresh()->rate_limited_until->toDateTimeString())->toBe($backoff->toDateTimeString());
})->with([
    'queued past the PSU window' => [function (SyncBankingConnectionJob $job): void {
        $job->psuContextDispatchedAt = now()->subMinutes(6);
    }],
    'serialized before the timestamp existed' => [function (SyncBankingConnectionJob $job): void {
        $job->psuContextDispatchedAt = null;
    }],
    'no user agent on the request' => [function (SyncBankingConnectionJob $job): void {
        $job->psuUserAgent = '';
    }],
]);

test('a limit on user-present access holds the next manual sync too', function () {
    $connection = backedOffEnableBankingConnection([
        'interactive_rate_limited_until' => now()->addMinutes(2),
    ]);
    fakeEnableBanking();

    runSync(userPresentSync($connection));

    Http::assertNothingSent();
    expect(latestSyncLog($connection)->status)->toBe(BankingSyncLogStatus::Skipped);
});

test('only a manual job carries the PSU identity across the queue', function () {
    $connection = backedOffEnableBankingConnection();

    $manual = userPresentSync($connection)->__serialize();
    $scheduled = (new SyncBankingConnectionJob(
        $connection,
        psuIpAddress: '192.0.2.44',
        psuUserAgent: 'SyntheticBrowser/1.0',
    ))->__serialize();

    expect($manual['psuIpAddress'])->toBe('192.0.2.44')
        ->and($manual['psuUserAgent'])->toBe('SyntheticBrowser/1.0')
        ->and($scheduled)->not->toHaveKey('psuIpAddress')
        ->and($scheduled)->not->toHaveKey('psuUserAgent');
});
