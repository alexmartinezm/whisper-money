<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\BankingConnection;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    config([
        'services.enablebanking.app_id' => 'test-app-id',
        'services.enablebanking.private_key_path' => '/tmp/fake-key.pem',
        'services.enablebanking.redirect_url' => 'https://example.com/callback',
    ]);
});

test('users can start bank authorization', function () {
    $user = User::factory()->onboarded()->create();
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-123',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'enablebanking',
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Pending->value,
    ]);
});

test('free tier users cannot start bank authorization when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
    ]);
});

test('users can start bank authorization during onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->notOnboarded()->create();
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-onboarding-123',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);

    $this->assertDatabaseHas('banking_connections', [
        'user_id' => $user->id,
        'provider' => 'enablebanking',
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
        'status' => BankingConnectionStatus::Pending->value,
    ]);
});

test('a free-plan signup cannot start bank authorization during onboarding', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->notOnboarded()->create(['signup_plan' => 'free']);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);

    $this->assertDatabaseMissing('banking_connections', [
        'user_id' => $user->id,
    ]);
});

test('subscribed users can start bank authorization when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test_auth',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-456',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);
});

test('authorization requires aspsp_name and country', function () {
    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->postJson('/open-banking/authorize', []);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['aspsp_name', 'country']);
});

test('callback with error redirects with error message and deletes pending connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-denied',
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access&state=state-token-denied');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
});

test('callback with error during onboarding redirects to the accounts step', function () {
    $user = User::factory()->notOnboarded()->create();
    Account::factory()->create(['user_id' => $user->id]);
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-onboarding-denied',
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access&state=state-token-onboarding-denied');

    $response->assertRedirect(route('onboarding', ['step' => 'create-account']));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
});

test('callback with error during reconnect preserves existing connection', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-reconnect-denied',
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access&state=state-token-reconnect-denied');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'User denied access');

    $connection->refresh();
    expect($connection->trashed())->toBeFalse();
    expect($connection->status)->toBe(BankingConnectionStatus::Error);
    expect($connection->error_message)->toBe('User denied access');
});

test('callback with error and nothing pending still reports the error', function () {
    $user = User::factory()->onboarded()->create();

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'User denied access');
});

test('callback with error and no description falls back to a generic message', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-no-description',
    ]);

    $response = $this->actingAs($user)->get('/open-banking/callback?error=access_denied&state=state-token-no-description');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'Authorization was denied or cancelled.');

    expect(BankingConnection::find($connection->id))->toBeNull();
});

test('callback does not blame the user when the bank fails the authorization', function (string $errorCode) {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => "state-token-{$errorCode}",
    ]);

    $response = $this->actingAs($user)->get("/open-banking/callback?error={$errorCode}&state=state-token-{$errorCode}");

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'We could not complete the connection with your bank. Please try again later.');

    expect(BankingConnection::find($connection->id))->toBeNull();
})->with(['server_error', 'invalid_request', 'invalid_client']);

test('callback ignores the provider description unless the user denied the consent', function () {
    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->pending()->create(['user_id' => $user->id]);

    // Real invalid_client description from production: prose addressed to us
    // rather than to the user, and in a language we never asked for.
    $response = $this->actingAs($user)->get(
        '/open-banking/callback?error=invalid_client&error_description=Alg%C3%BAn+reto+es+err%C3%B3neo.+SOLUCION%3A+Debe+reintentar+el+flujo.'
    );

    $response->assertSessionHas('error', 'We could not complete the connection with your bank. Please try again later.');
});

test('callback stores the bank failure on a reconnect instead of blaming the user', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-reconnect-failure',
    ]);
    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)->get('/open-banking/callback?error=server_error&state=state-token-reconnect-failure');

    $response->assertSessionHas('error', 'We could not complete the connection with your bank. Please try again later.');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error)
        ->and($connection->error_message)->toBe('We could not complete the connection with your bank. Please try again later.');
});

test('callback logs the bank behind an authorization error', function () {
    Log::spy();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Banco Mediolanum',
        'state_token' => 'state-token-logged',
    ]);

    $this->actingAs($user)->get('/open-banking/callback?error=server_error&state=state-token-logged');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'EnableBanking authorization error'
            && $context['error'] === 'server_error'
            && $context['aspsp_name'] === 'Banco Mediolanum'
            && $context['connection_id'] === $connection->id);
});

test('callback without code redirects with error', function () {
    $user = User::factory()->onboarded()->create();
    $response = $this->actingAs($user)->get('/open-banking/callback');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error');
});

test('callback rejects a non-string authorization code instead of failing', function () {
    $user = User::factory()->onboarded()->create();

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldNotReceive('createSession');

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code[]=abc');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'No authorization code received.');
});

test('callback clears the state token when the session exchange fails', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-fails',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andThrow(new RuntimeException('Provider is unreachable'));

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?code=test-code&state=state-token-fails');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'Failed to connect to your bank. Please try again.');

    // A state token left behind would be matched by a later callback for another bank.
    $connection->refresh();
    expect($connection->state_token)->toBeNull();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
});

test('callback with valid code stores pending accounts and redirects to mapping', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
    ]);

    $accounts = [
        [
            'uid' => 'ext-account-1',
            'currency' => 'EUR',
            'name' => 'My Checking Account',
            'account_id' => ['iban' => 'ES1234567890123456789012'],
        ],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-123',
            'accounts' => $accounts,
            'aspsp' => ['name' => 'Test Bank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('open-banking.map-accounts', $connection));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-123');
    expect($connection->pending_accounts_data)->toEqual($accounts);

    $this->assertDatabaseMissing('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    Queue::assertNothingPushed();
});

test('callback with an authorized session but no accounts closes the connection with an actionable error', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Trade Republic',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-without-accounts',
            'accounts' => [],
            'aspsp' => ['name' => 'Trade Republic', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);
    $mockProvider->shouldReceive('revokeSession')
        ->with('session-without-accounts')
        ->once();

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));
    // Named, and not "please try again": the bank was asked and shared
    // nothing, so trying again lands here every time.
    $response->assertSessionHas('error', 'Trade Republic did not share any accounts through open banking. Not every account type is available this way — contact us if yours should be.');
    $this->assertSoftDeleted('banking_connections', ['id' => $connection->id]);
    Queue::assertNothingPushed();
});

test('a callback that closes an empty connection describes the session before revoking it', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Trade Republic',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')->andReturn([
        'session_id' => 'session-secret-123',
        'status' => 'AUTHORIZED',
        'accounts' => [],
        'accounts_data' => [],
        'aspsp' => ['name' => 'Trade Republic', 'country' => 'ES'],
        'access' => ['valid_until' => '2026-11-24T13:19:41Z'],
    ]);
    $mockProvider->shouldReceive('revokeSession')->once();

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        if ($event->message === 'EnableBanking session has no accounts') {
            $logged[] = $event->context;
        }
    });

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    // The revoke and the delete that follow destroy every trace of why the
    // session was empty, so this has to be written before them.
    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toMatchArray([
            'stage' => 'callback',
            'session_status' => 'AUTHORIZED',
            'accounts_count' => 0,
            'accounts_data_count' => 0,
            'has_session_id' => true,
            'access_valid_until' => '2026-11-24T13:19:41Z',
            'aspsp_name' => 'Trade Republic',
            'aspsp_country' => 'ES',
        ])
        ->and(json_encode($logged))->not->toContain('session-secret-123');
});

test('a failed session exchange logs which bank it was', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Trade Republic',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-123',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')->andThrow(new RuntimeException('boom'));

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    Log::spy();

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?code=test-code&state='.$connection->state_token);

    $response->assertSessionHas('error', 'Failed to connect to your bank. Please try again.');

    Log::shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $context): bool => $message === 'EnableBanking session creation failed'
            && $context['aspsp_name'] === 'Trade Republic'
            && $context['connection_id'] === $connection->id)
        ->once();
});

test('callback during onboarding redirects a logged-in user directly to the connections step', function () {
    Queue::fake();

    $user = User::factory()->notOnboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-onboarding',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'My Checking Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'aspsp' => ['name' => 'Test Bank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('onboarding', ['step' => 'create-account']));

    $this->assertDatabaseHas('accounts', [
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);
});

// Reauthorize tests

test('reauthorize returns 403 when user does not own the connection', function () {
    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $owner->id,
    ]);

    $response = $this->actingAs($other)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertForbidden();
});

test('reauthorize returns 422 for non-EnableBanking connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->indexaCapital()->error()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertUnprocessable();
    $response->assertJson(['error' => 'Only EnableBanking connections can be re-authorized.']);
});

test('reauthorize returns 422 for connections that are neither active, errored nor expired', function (BankingConnectionStatus $status) {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => $status,
        'valid_until' => now()->addDays(90),
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertUnprocessable();
    $response->assertJson(['error' => 'Only active, error, expired or unfinished reconnects can be re-authorized.']);
})->with([
    BankingConnectionStatus::Pending,
    BankingConnectionStatus::AwaitingMapping,
    BankingConnectionStatus::Revoked,
]);

test('reauthorize renews a healthy active connection so the consent never lapses', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->addDays(3),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'renewed-auth-id',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();
    $response->assertJson(['redirect_url' => 'https://bank.example.com/reauthorize']);

    // Active throughout, so syncing carries on with the consent it still has
    // while the user is off at their bank.
    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->authorization_id)->toBe('renewed-auth-id');
});

test('callback with error during an early renewal leaves the working connection alone', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->addDays(3),
        'state_token' => 'state-token-renewal-abandoned',
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=User+denied+access&state=state-token-renewal-abandoned');

    $response->assertSessionHas('error', 'User denied access');

    // The consent it already holds is untouched, so backing out of the bank's
    // screen must not cost the user a connection that syncs fine.
    $connection->refresh();
    expect($connection->trashed())->toBeFalse();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->error_message)->toBeNull();
});

test('an early renewal pushes the consent window out without the connection ever breaking', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'status' => BankingConnectionStatus::Active,
        'valid_until' => now()->addDays(3),
        'state_token' => 'state-token-early-renewal',
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'ext-account-renewed',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'renewed-session',
            'accounts' => [
                [
                    'uid' => 'ext-account-renewed',
                    'currency' => 'EUR',
                    'name' => 'Renewed Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?code=test-code&state=state-token-early-renewal');

    $response->assertSessionHas('success', 'Bank account reconnected successfully.');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->session_id)->toBe('renewed-session');
    expect($connection->valid_until->isAfter(now()->addDays(80)))->toBeTrue();
    expect($connection->isExpiringSoon())->toBeFalse();

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('reauthorize starts new authorization and sets connection to pending for error connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    $originalAuthorizationId = $connection->authorization_id;

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->with('CaixaBank', 'ES', config('services.enablebanking.redirect_url'), Mockery::type('string'))
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-456',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();
    $response->assertJsonStructure(['redirect_url', 'connection_id']);
    $response->assertJson([
        'redirect_url' => 'https://bank.example.com/reauthorize',
        'connection_id' => $connection->id,
    ]);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-456');
    expect($connection->authorization_id)->not->toBe($originalAuthorizationId);
    expect($connection->error_message)->toBeNull();
});

test('reauthorize restarts a re-authorization the user abandoned at the bank', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create(['user_id' => $user->id]);
    Account::factory()->connected()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'restarted-auth-id',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();
    $response->assertJson(['redirect_url' => 'https://bank.example.com/reauthorize']);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('restarted-auth-id');
});

test('reauthorize starts new authorization for expired connections', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-789',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertOk();

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-789');
});

test('reconnect link redirects expired connections to bank authorization', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->expired()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Santander',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->with('Santander', 'ES', config('services.enablebanking.redirect_url'), Mockery::type('string'))
        ->once()
        ->andReturn([
            'url' => 'https://bank.example.com/reauthorize',
            'authorization_id' => 'new-auth-id-987',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get(route('open-banking.reconnect', $connection));

    $response->assertRedirect('https://bank.example.com/reauthorize');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->authorization_id)->toBe('new-auth-id-987');
});

test('free tier users cannot reauthorize after onboarding when subscriptions are enabled', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->error()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'error_message' => 'Authentication failed. Your credentials may have expired or been revoked.',
    ]);

    $response = $this->actingAs($user)->postJson("/open-banking/connections/{$connection->id}/reauthorize");

    $response->assertStatus(402);
    $response->assertJson(['redirect' => route('subscribe')]);
});

// Reconnect callback tests

test('callback with existing accounts updates session without creating new accounts', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'last_synced_at' => now()->subWeek(),
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'existing-ext-account-1',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-456',
            'accounts' => [
                [
                    'uid' => 'existing-ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'CaixaBank Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('success', 'Bank account reconnected successfully.');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->session_id)->toBe('new-session-456');
    expect($connection->error_message)->toBeNull();

    // No duplicate accounts should have been created
    $this->assertDatabaseCount('accounts', 1);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('callback with existing accounts skips mapping on reconnect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'existing-ext-account-1',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-789',
            'accounts' => [],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    // Must redirect to connections page, NOT to the account-mapping route
    $response->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);

    Queue::assertPushed(SyncBankingConnectionJob::class);
});

test('callback matches the pending reconnect by institution when multiple reconnects are open', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $bbvaConnection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'BBVA',
        'aspsp_country' => 'ES',
        'created_at' => now()->subHours(2),
    ]);
    $ingConnection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'ING',
        'aspsp_country' => 'ES',
        'created_at' => now()->subHour(),
    ]);

    $bbvaAccount = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $bbvaConnection->id,
        'external_account_id' => 'old-bbva-uid',
        'iban' => 'ES0000000000000000008058',
    ]);
    $ingAccount = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $ingConnection->id,
        'external_account_id' => 'old-ing-uid',
        'iban' => 'ES0000000000000000001111',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-bbva-session',
            'accounts' => [
                [
                    'uid' => 'new-bbva-uid',
                    'currency' => 'EUR',
                    'name' => 'BBVA Account',
                    'account_id' => ['iban' => 'ES0000000000000000008058'],
                ],
            ],
            'aspsp' => ['name' => 'BBVA', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $response->assertRedirect(route('settings.connections.index'));

    expect($bbvaConnection->refresh()->status)->toBe(BankingConnectionStatus::Active);
    expect($bbvaConnection->session_id)->toBe('new-bbva-session');
    expect($bbvaAccount->refresh()->external_account_id)->toBe('new-bbva-uid');
    expect($ingConnection->refresh()->status)->toBe(BankingConnectionStatus::Pending);
    expect($ingConnection->session_id)->toBeNull();
    expect($ingAccount->refresh()->external_account_id)->toBe('old-ing-uid');

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->id === $bbvaConnection->id);
});

// refreshAccountIds tests

test('reconnect callback updates external_account_id when enable banking issues new account uids', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    $account = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-1',
        'iban' => 'ES1234567890123456789012',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            'accounts' => [
                [
                    'uid' => 'new-uid-1',
                    'currency' => 'EUR',
                    'name' => 'CaixaBank Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $account->refresh();
    expect($account->external_account_id)->toBe('new-uid-1');
    expect($account->iban)->toBe('ES1234567890123456789012');
});

test('reconnect callback matches accounts by iban before falling back to position', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    // Two accounts; create them in reverse IBAN order to confirm positional matching
    // would produce wrong results, while IBAN matching produces correct results.
    $accountA = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-a',
        'iban' => 'ES0000000000000000000001',
        'created_at' => now()->subMinutes(2),
    ]);

    $accountB = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-b',
        'iban' => 'ES0000000000000000000002',
        'created_at' => now()->subMinutes(1),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            // Enable Banking returns accounts in a different order this time
            'accounts' => [
                [
                    'uid' => 'new-uid-b',
                    'currency' => 'EUR',
                    'name' => 'Account B',
                    'account_id' => ['iban' => 'ES0000000000000000000002'],
                ],
                [
                    'uid' => 'new-uid-a',
                    'currency' => 'EUR',
                    'name' => 'Account A',
                    'account_id' => ['iban' => 'ES0000000000000000000001'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($accountA->refresh()->external_account_id)->toBe('new-uid-a');
    expect($accountB->refresh()->external_account_id)->toBe('new-uid-b');
});

test('reconnect callback uses positional fallback for accounts without stored iban', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    $accountA = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-a',
        'iban' => null, // legacy account without IBAN stored
        'created_at' => now()->subMinutes(2),
    ]);

    $accountB = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-b',
        'iban' => null,
        'created_at' => now()->subMinutes(1),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            'accounts' => [
                [
                    'uid' => 'new-uid-a',
                    'currency' => 'EUR',
                    'name' => 'Account A',
                    'account_id' => ['iban' => 'ES0000000000000000000001'],
                ],
                [
                    'uid' => 'new-uid-b',
                    'currency' => 'EUR',
                    'name' => 'Account B',
                    'account_id' => ['iban' => 'ES0000000000000000000002'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    // Positional match: index 0 → accountA (oldest), index 1 → accountB
    expect($accountA->refresh()->external_account_id)->toBe('new-uid-a');
    expect($accountA->refresh()->iban)->toBe('ES0000000000000000000001'); // IBAN populated from new session
    expect($accountB->refresh()->external_account_id)->toBe('new-uid-b');
    expect($accountB->refresh()->iban)->toBe('ES0000000000000000000002');
});

test('reconnect callback unlinks an account the new session no longer returns', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
    ]);

    $kept = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-kept',
        'iban' => 'ES0000000000000000000001',
        'created_at' => now()->subMinutes(2),
    ]);

    // No IBAN to match on and nothing left for the positional fallback.
    $dropped = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-dropped',
        'iban' => null,
        'created_at' => now()->subMinutes(1),
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-abc',
            'accounts' => [
                [
                    'uid' => 'new-uid-kept',
                    'currency' => 'EUR',
                    'name' => 'Account',
                    'account_id' => ['iban' => 'ES0000000000000000000001'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($connection->refresh()->status)->toBe(BankingConnectionStatus::Active);
    expect($kept->refresh()->external_account_id)->toBe('new-uid-kept');
    expect($dropped->refresh()->external_account_id)->toBeNull();
});

test('callback stores iban in pending accounts data', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Test Bank',
        'aspsp_country' => 'ES',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-new',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'My Account',
                    'account_id' => ['iban' => 'ES9999999999999999999999'],
                ],
            ],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $connection->refresh();
    expect($connection->pending_accounts_data)->toHaveCount(1);
    expect($connection->pending_accounts_data[0]['account_id']['iban'])->toBe('ES9999999999999999999999');
});

// Authless callback via state token (iOS PWA returns to Safari without a session)

test('start authorization persists a state token and sends it to the provider', function () {
    $user = User::factory()->onboarded()->create();

    $capturedState = null;
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('startAuthorization')
        ->once()
        ->withArgs(function (string $name, string $country, string $url, string $state) use (&$capturedState): bool {
            $capturedState = $state;

            return $name === 'Test Bank' && $country === 'ES' && $state !== '';
        })
        ->andReturn([
            'url' => 'https://bank.example.com/authorize',
            'authorization_id' => 'auth-state-1',
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->postJson('/open-banking/authorize', [
        'aspsp_name' => 'Test Bank',
        'country' => 'ES',
    ])->assertOk();

    $connection = $user->bankingConnections()->first();
    expect($connection->state_token)->not->toBeEmpty();
    expect($connection->state_token)->toBe($capturedState);
});

test('callback finalizes the connection from the state token without an authenticated session', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-abc',
    ]);

    $accounts = [
        [
            'uid' => 'ext-account-1',
            'currency' => 'EUR',
            'name' => 'My Checking Account',
            'account_id' => ['iban' => 'ES1234567890123456789012'],
        ],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-authless',
            'accounts' => $accounts,
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    // No actingAs(): the PWA handed the redirect to Safari, which has no session.
    $response = $this->get('/open-banking/callback?code=test-code&state=state-token-abc');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('open-banking/connection-complete')
        ->where('status', 'success')
    );

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-authless');
    expect($connection->state_token)->toBeNull();
});

test('callback resolves the connection owner from the state token regardless of who is authenticated', function () {
    Queue::fake();

    $owner = User::factory()->onboarded()->create();
    $other = User::factory()->onboarded()->create();

    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $owner->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-owner',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-owner',
            'accounts' => [
                [
                    'uid' => 'ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'Owner Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $response = $this->actingAs($other)->get('/open-banking/callback?code=test-code&state=state-token-owner');

    $response->assertRedirect(route('open-banking.map-accounts', $connection));

    $connection->refresh();
    expect($connection->user_id)->toBe($owner->id);
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-owner');
    expect($connection->state_token)->toBeNull();
});

test('callback without a session or a resolvable state redirects to login', function () {
    $response = $this->get('/open-banking/callback?code=test-code&state=unknown-token');

    $response->assertRedirect(route('login'));
});

test('callback logs the callback it could not attribute to a user', function () {
    Log::spy();

    $this->get('/open-banking/callback?code=test-code&state=unknown-token');

    // Presence only: both the state token and the code are credentials, and
    // this branch is the one that made a dropped callback indistinguishable
    // from a callback the bank never sent.
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'EnableBanking callback could not be attributed to a user'
            && $context === ['has_state' => true, 'has_code' => true]);
});

test('callback records which parameters were missing when it cannot attribute the callback', function () {
    Log::spy();

    $this->get('/open-banking/callback');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context === ['has_state' => false, 'has_code' => false]);
});

test('callback renders the completion page on error without an authenticated session', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'state_token' => 'state-token-error',
    ]);

    // No actingAs(): the error came back to Safari, which has no session.
    $response = $this->get('/open-banking/callback?error=access_denied&error_description=Denied&state=state-token-error');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('open-banking/connection-complete')
        ->where('status', 'error')
        ->where('message', 'Denied')
    );

    expect($connection->fresh()->trashed())->toBeTrue();
});

// The iOS/PWA split-context race: the bank redirect leaves the installed app for
// Safari, and only one of the two contexts gets the return. The orphan cancels the
// authorization the other one is still completing.

test('a valid code restores the connection a cancelled callback deleted', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-race',
    ]);

    $accounts = [
        [
            'uid' => 'ext-account-1',
            'currency' => 'EUR',
            'name' => 'My Checking Account',
            'account_id' => ['iban' => 'ES1234567890123456789012'],
        ],
    ];

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-race',
            'accounts' => $accounts,
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    // The orphaned context aborts the flow seconds after it started.
    $this->get('/open-banking/callback?error=access_denied&error_description=Cancelled+by+user&state=state-token-race');

    expect($connection->fresh()->trashed())->toBeTrue();

    // The context the user actually authorized in comes back with the code.
    $this->actingAs($user)
        ->get('/open-banking/callback?code=test-code&state=state-token-race')
        ->assertRedirect(route('open-banking.map-accounts', $connection));

    $connection->refresh();
    expect($connection->trashed())->toBeFalse();
    expect($connection->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($connection->session_id)->toBe('session-race');
    expect($connection->pending_accounts_data)->toEqual($accounts);
    expect($connection->state_token)->toBeNull();
});

test('a valid code completes a reconnect a cancelled callback marked as failed', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-race-reconnect',
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-ext-account-1',
        'iban' => 'ES1234567890123456789012',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('test-code')
        ->once()
        ->andReturn([
            'session_id' => 'session-race-reconnect',
            'accounts' => [
                [
                    'uid' => 'new-ext-account-1',
                    'currency' => 'EUR',
                    'name' => 'My Checking Account',
                    'account_id' => ['iban' => 'ES1234567890123456789012'],
                ],
            ],
            'aspsp' => ['name' => 'CaixaBank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->get('/open-banking/callback?error=access_denied&error_description=Cancelled+by+user&state=state-token-race-reconnect');

    expect($connection->fresh()->status)->toBe(BankingConnectionStatus::Error);

    $this->actingAs($user)
        ->get('/open-banking/callback?code=test-code&state=state-token-race-reconnect')
        ->assertRedirect(route('settings.connections.index'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->session_id)->toBe('session-race-reconnect');
    expect($connection->error_message)->toBeNull();
    expect($connection->state_token)->toBeNull();
    expect($connection->accounts()->first()->external_account_id)->toBe('new-ext-account-1');
});

test('a code the provider rejects leaves a deleted connection deleted', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-race-rejected',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->with('stale-code')
        ->once()
        ->andThrow(new RuntimeException('Invalid authorization code'));

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->get('/open-banking/callback?error=access_denied&error_description=Cancelled+by+user&state=state-token-race-rejected');

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?code=stale-code&state=state-token-race-rejected');

    $response->assertSessionHas('error', 'Failed to connect to your bank. Please try again.');

    // Only a session the provider issued resurrects a connection, so this one
    // stays trashed and keeps counting as a failed authorization.
    $connection->refresh();
    expect($connection->trashed())->toBeTrue();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending);
    expect($connection->state_token)->toBeNull();
});

test('an error with no resolvable state token deletes nothing', function () {
    $user = User::factory()->onboarded()->create();
    $unrelated = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'state_token' => 'state-token-unrelated',
    ]);

    $response = $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=Cancelled+by+user');

    $response->assertRedirect(route('settings.connections.index'));
    $response->assertSessionHas('error', 'Cancelled by user');

    // The old fallback deleted the latest pending connection, which in production
    // was an attempt started an hour and a quarter earlier.
    expect($unrelated->fresh()->trashed())->toBeFalse();
});

test('reconnect hands back the full scheduled retry budget', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    // A connection parked by repeated auth failures: reconnecting is the only way
    // out, so it must not carry the count that parked it.
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'CaixaBank',
        'aspsp_country' => 'ES',
        'consecutive_sync_failures' => SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES + 1,
    ]);

    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'existing-ext-account-1',
    ]);

    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => 'new-session-789',
            'accounts' => [],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    $this->app->instance(BankingProviderInterface::class, $mockProvider);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Active);
    expect($connection->consecutive_sync_failures)->toBe(0);
});

// Sibling connections to a bank that keeps one session per user

/**
 * @param  array<int, array{uid: string, iban: string}>  $accounts
 */
function mockSharedSession(array $accounts, string $sessionId = 'shared-session'): MockInterface
{
    $mockProvider = Mockery::mock(BankingProviderInterface::class);
    $mockProvider->shouldReceive('createSession')
        ->once()
        ->andReturn([
            'session_id' => $sessionId,
            'accounts' => array_map(fn (array $account): array => [
                'uid' => $account['uid'],
                'currency' => $account['currency'] ?? 'EUR',
                'name' => 'Account',
                'account_id' => ['iban' => $account['iban']],
            ], $accounts),
            'aspsp' => ['name' => 'Joint Bank', 'country' => 'ES'],
            'access' => ['valid_until' => now()->addDays(90)->toIso8601String()],
        ]);

    app()->instance(BankingProviderInterface::class, $mockProvider);

    return $mockProvider;
}

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: BankingConnection, 1: Account}
 */
function connectionWithAccount(User $user, ?string $iban, array $attributes = []): array
{
    $connection = BankingConnection::factory()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Joint Bank',
        'aspsp_country' => 'ES',
        ...$attributes,
    ]);

    $account = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-'.$connection->id,
        'iban' => $iban,
    ]);

    return [$connection, $account];
}

test('reconnecting a connection also repoints a same-bank sibling the new session covers', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', [
        'status' => BankingConnectionStatus::Expired,
        'session_id' => 'revoked-session',
        'error_message' => 'Session expired',
        'consecutive_sync_failures' => 3,
        'valid_until' => now()->subDay(),
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertRedirect(route('settings.connections.index'));

    $sibling->refresh();
    expect($sibling->session_id)->toBe('shared-session');
    expect($sibling->status)->toBe(BankingConnectionStatus::Active);
    expect($sibling->valid_until->isFuture())->toBeTrue();
    expect($sibling->error_message)->toBeNull();
    expect($sibling->consecutive_sync_failures)->toBe(0);
    expect($siblingAccount->refresh()->external_account_id)->toBe('new-uid-2');
    expect($connection->refresh()->session_id)->toBe('shared-session');

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->is($sibling)
        && $job->trigger === BankingSyncTrigger::Reconnect);
    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->is($connection));
});

test('a same-bank sibling the new session does not cover is left untouched', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'other-session']);

    mockSharedSession([['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($sibling->refresh()->session_id)->toBe('other-session');
    expect($siblingAccount->refresh()->external_account_id)->toBe('old-uid-'.$sibling->id);
    Queue::assertNotPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->is($sibling));
});

test('a sibling only partly covered by the new session is left untouched', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $coveredAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'other-session']);
    Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $sibling->id,
        'iban' => 'ES0000000000000000000003',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($sibling->refresh()->session_id)->toBe('other-session');
    expect($coveredAccount->refresh()->external_account_id)->toBe('old-uid-'.$sibling->id);
});

test('a connection to a different bank is never repointed even when an iban matches', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$otherBank, $otherAccount] = connectionWithAccount($user, 'ES0000000000000000000002', [
        'aspsp_name' => 'Other Bank',
        'session_id' => 'other-session',
    ]);
    [$otherCountry] = connectionWithAccount($user, 'ES0000000000000000000002', [
        'aspsp_country' => 'PT',
        'session_id' => 'other-country-session',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($otherBank->refresh()->session_id)->toBe('other-session');
    expect($otherAccount->refresh()->external_account_id)->toBe('old-uid-'.$otherBank->id);
    expect($otherCountry->refresh()->session_id)->toBe('other-country-session');
});

test('a reconnect whose session only covers a sibling fails instead of unlinking every account', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
    ]);
    $otherAccount = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-other',
        'iban' => null,
    ]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'revoked-session']);

    mockSharedSession([['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002']])
        ->shouldNotReceive('revokeSession');

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertRedirect(route('settings.connections.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'does not cover the accounts'));

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Error)
        ->and($connection->error_message)->toContain('does not cover the accounts')
        ->and($connection->session_id)->toBe('old-session')
        ->and($account->refresh()->external_account_id)->toBe('old-uid-'.$connection->id)
        ->and($otherAccount->refresh()->external_account_id)->toBe('old-uid-other');

    // The session does cover the sibling, so the sibling keeps it.
    expect($sibling->refresh()->session_id)->toBe('shared-session')
        ->and($siblingAccount->refresh()->external_account_id)->toBe('new-uid-2');

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job) => $job->bankingConnection->is($sibling));
    Queue::assertNotPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job) => $job->bankingConnection->is($connection));
});

test('a wrong-login reconnect on a lapsed consent stays expired, where the sweep and the reconnect button still reach it', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
        'valid_until' => now()->subDay(),
    ]);
    connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'revoked-session']);

    mockSharedSession([['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertSessionHas('error');

    expect($connection->refresh()->status)->toBe(BankingConnectionStatus::Expired)
        ->and($connection->session_id)->toBe('old-session');
});

test('a wrong-login reconnect tells the connections page in any language', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['locale' => 'es']);
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'revoked-session']);

    mockSharedSession([['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'no incluye las cuentas'));

    expect($connection->refresh()->error_message)->toBe(BankingConnection::WRONG_LOGIN_ERROR);

    $this->get(route('settings.connections.index'))
        ->assertInertia(fn ($page) => $page->where('connections', fn ($connections) => collect($connections)->firstWhere('id', $connection->id)['failed_on_wrong_login'] === true));
});

test('a reconnect does not pair an account with a session account holding another iban', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
    ]);
    [$sibling] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'revoked-session']);

    mockSharedSession([
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
        ['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003'],
    ])->shouldNotReceive('revokeSession');

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertSessionHas('error');

    expect($account->refresh()->external_account_id)->toBe('old-uid-'.$connection->id)
        ->and($account->iban)->toBe('ES0000000000000000000001')
        ->and($connection->refresh()->status)->toBe(BankingConnectionStatus::Error)
        ->and($sibling->refresh()->session_id)->toBe('shared-session');
});

test('a reconnect unlinks the one account another iban would otherwise have taken', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $kept] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    $other = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => 'ES0000000000000000000004',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($kept->refresh()->external_account_id)->toBe('new-uid-1')
        ->and($other->refresh()->external_account_id)->toBeNull()
        ->and($other->iban)->toBe('ES0000000000000000000004');
});

test('a reconnect matches the same iban written with other spaces and case', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $account] = connectionWithAccount($user, 'es00 0000 0000 0000 0000 0001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([
        ['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003'],
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($account->refresh()->external_account_id)->toBe('new-uid-1');
});

test('a session without ibans still reaches an account by position and keeps its stored iban', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $account] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([['uid' => 'new-uid', 'iban' => null]]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($account->refresh()->external_account_id)->toBe('new-uid')
        ->and($account->iban)->toBe('ES0000000000000000000001');
});

test('an account without a stored iban still reaches a session account by position', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $account] = connectionWithAccount($user, null, ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([['uid' => 'new-uid', 'iban' => 'ES0000000000000000000001']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($account->refresh()->external_account_id)->toBe('new-uid')
        ->and($account->iban)->toBe('ES0000000000000000000001');
});

test('a stored iban does not take an iban-less session account when the session lists ibans', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
        ['uid' => 'card-uid', 'iban' => null],
    ])->shouldReceive('revokeSession')->once();

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'does not cover the accounts'));

    expect($account->refresh()->external_account_id)->toBe('old-uid-'.$connection->id)
        ->and($connection->refresh()->status)->toBe(BankingConnectionStatus::Error);
});

test('a closed account and a card reconnect without trading places', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $closed] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    $card = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => null,
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-9', 'iban' => 'ES0000000000000000000009'],
        ['uid' => 'card-uid', 'iban' => null],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($card->refresh()->external_account_id)->toBe('card-uid')
        ->and($card->iban)->toBeNull()
        ->and($closed->refresh()->external_account_id)->toBeNull()
        ->and($closed->iban)->toBe('ES0000000000000000000001');
});

test('accounts with and without an iban find their own whatever the order', function (bool $ibanFirst, bool $sessionIbanFirst) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Joint Bank',
        'aspsp_country' => 'ES',
    ]);
    $createAccount = fn (?string $iban, int $minutesAgo): Account => Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => $iban,
        'created_at' => now()->subMinutes($minutesAgo),
    ]);

    $withIban = $createAccount('ES0000000000000000000001', $ibanFirst ? 2 : 1);
    $withoutIban = $createAccount(null, $ibanFirst ? 1 : 2);

    $session = [
        ['uid' => 'iban-uid', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'card-uid', 'iban' => null],
    ];

    mockSharedSession($sessionIbanFirst ? $session : array_reverse($session));

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($withIban->refresh()->external_account_id)->toBe('iban-uid')
        ->and($withoutIban->refresh()->external_account_id)->toBe('card-uid');
})->with([
    'iban account first, iban session first' => [true, true],
    'iban account first, card session first' => [true, false],
    'card account first, iban session first' => [false, true],
    'card account first, card session first' => [false, false],
]);

test('an account without an iban prefers an iban-less session account over an earlier one with an iban', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $card] = connectionWithAccount($user, null, ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'card-uid', 'iban' => null],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($card->refresh()->external_account_id)->toBe('card-uid');
});

test('a session without ibans pairs by position but never across currencies', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $account] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([
        ['uid' => 'usd-uid', 'iban' => null, 'currency' => 'USD'],
        ['uid' => 'eur-uid', 'iban' => null, 'currency' => 'EUR'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($account->refresh()->external_account_id)->toBe('eur-uid');
});

test('a session in another currency covers nothing even without ibans', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([['uid' => 'usd-uid', 'iban' => null, 'currency' => 'USD']])
        ->shouldReceive('revokeSession')->once();

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertSessionHas('error');

    expect($account->refresh()->external_account_id)->toBe('old-uid-'.$connection->id)
        ->and($connection->refresh()->status)->toBe(BankingConnectionStatus::Error);
});

test('one iban listed once per currency stays on the right accounts', function (array $session) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $euros] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    $dollars = Account::factory()->create([
        'currency_code' => 'USD',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => 'ES0000000000000000000001',
    ]);

    mockSharedSession($session);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($euros->refresh()->external_account_id)->toBe('eur-uid')
        ->and($dollars->refresh()->external_account_id)->toBe('usd-uid');
})->with([
    'dollars listed first' => [[
        ['uid' => 'usd-uid', 'iban' => 'ES0000000000000000000001', 'currency' => 'USD'],
        ['uid' => 'eur-uid', 'iban' => 'ES0000000000000000000001', 'currency' => 'EUR'],
    ]],
    'euros listed first' => [[
        ['uid' => 'eur-uid', 'iban' => 'ES0000000000000000000001', 'currency' => 'EUR'],
        ['uid' => 'usd-uid', 'iban' => 'ES0000000000000000000001', 'currency' => 'USD'],
    ]],
]);

test('a lone iban relinks even when the bank now reports another currency', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
    ]);

    $provider = mockSharedSession([['uid' => 'usd-uid', 'iban' => 'ES0000000000000000000001', 'currency' => 'USD']]);
    $provider->shouldNotReceive('revokeSession');

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertSessionHas('success');

    expect($account->refresh()->external_account_id)->toBe('usd-uid')
        ->and($connection->refresh()->status)->toBe(BankingConnectionStatus::Active)
        ->and($connection->session_id)->toBe('shared-session');
});

test('a session without ibans pairs in creation order whether or not the account stores one', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $withIban] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    $withIban->forceFill(['created_at' => now()->subDay()])->save();
    $withoutIban = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => null,
    ]);

    mockSharedSession([
        ['uid' => 'first-uid', 'iban' => null],
        ['uid' => 'second-uid', 'iban' => null],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($withIban->refresh()->external_account_id)->toBe('first-uid')
        ->and($withoutIban->refresh()->external_account_id)->toBe('second-uid');
});

test('a currency the bank does not really report vetoes nothing', function (string $reported) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [, $account] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001', 'currency' => $reported]]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($account->refresh()->external_account_id)->toBe('new-uid-1');
})->with(['iso no currency' => 'XXX', 'lower case' => 'eur', 'empty' => '']);

test('cancelling a reconnect at the bank keeps a lapsed consent where the reconnect button reaches it', function (string $validUntil, BankingConnectionStatus $expected) {
    $user = User::factory()->onboarded()->create();
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'state_token' => 'cancelled-reconnect',
        'valid_until' => now()->modify($validUntil),
    ]);

    $this->actingAs($user)
        ->get('/open-banking/callback?error=access_denied&error_description=Cancelled&state=cancelled-reconnect')
        ->assertSessionHas('error');

    expect($connection->refresh()->status)->toBe($expected)
        ->and($connection->error_message)->toBe('Cancelled');
})->with([
    'the consent has lapsed' => ['-1 day', BankingConnectionStatus::Expired],
    'the consent still runs' => ['+30 days', BankingConnectionStatus::Error],
]);

test('a reconnect that loses accounts says how many instead of reporting success', function (int $lost, string $locale, string $expected) {
    Queue::fake();

    $user = User::factory()->onboarded()->create(['locale' => $locale]);
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    Account::factory()->count($lost)->sequence(fn ($sequence) => ['iban' => 'ES000000000000000000001'.$sequence->index])->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);

    mockSharedSession([['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertRedirect(route('settings.connections.index'))
        ->assertSessionMissing('success')
        ->assertSessionHas('error', $expected);

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job) => $job->bankingConnection->is($connection));
})->with([
    'one, in English' => [1, 'en', 'Reconnected, but 1 account no longer syncs. Review it in Manage Accounts.'],
    'two, in English' => [2, 'en', 'Reconnected, but 2 accounts no longer sync. Review them in Manage Accounts.'],
    'two, in Spanish' => [2, 'es', 'Reconectada, pero 2 cuentas ya no se sincronizan. Revísalas en Gestionar cuentas.'],
]);

test('a wrong-login reconnect revokes a session nothing holds', function (array $sessionAccount) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $account] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
    ]);

    mockSharedSession([$sessionAccount])->shouldReceive('revokeSession')->once()->with('shared-session');

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertSessionHas('error');

    expect($connection->refresh()->session_id)->toBe('old-session')
        ->and($account->refresh()->external_account_id)->toBe('old-uid-'.$connection->id);
})->with([
    'no session account has a uid' => [['uid' => null, 'iban' => 'ES0000000000000000000001']],
    'the session lists another iban' => [['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003']],
]);

test('a revoke that fails changes nothing about a failed reconnect', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);

    mockSharedSession([['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003']])
        ->shouldReceive('revokeSession')->once()->andThrow(new RuntimeException('The bank is down.'));

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertRedirect(route('settings.connections.index'))
        ->assertSessionHas('error', fn (string $message): bool => str_contains($message, 'does not cover the accounts'));

    expect($connection->refresh()->status)->toBe(BankingConnectionStatus::Error);
});

test('a reconnect that throws halfway leaves everything as it was and tells the user', function () {
    Queue::fake();
    Exceptions::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $first] = connectionWithAccount($user, 'ES0000000000000000000001', [
        'status' => BankingConnectionStatus::Pending,
        'session_id' => 'old-session',
        'state_token' => 'reconnect-state',
    ]);
    $first->forceFill(['created_at' => now()->subDay()])->save();
    $failing = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'old-uid-failing',
        'iban' => 'ES0000000000000000000003',
    ]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'sibling-session']);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
        ['uid' => 'new-uid-3', 'iban' => 'ES0000000000000000000003'],
    ])->shouldReceive('revokeSession')->once()->with('shared-session');

    Account::updating(function (Account $account) use ($failing): void {
        if ($account->is($failing)) {
            throw new RuntimeException('Lost the database halfway through.');
        }
    });

    $this->actingAs($user)->get('/open-banking/callback?code=test-code&state=reconnect-state')
        ->assertRedirect(route('settings.connections.index'))
        ->assertSessionHas('error');

    Exceptions::assertReported(RuntimeException::class);

    $connection->refresh();
    expect($connection->status)->toBe(BankingConnectionStatus::Pending)
        ->and($connection->session_id)->toBe('old-session')
        ->and($connection->state_token)->toBeNull()
        ->and($first->refresh()->external_account_id)->toBe('old-uid-'.$connection->id)
        ->and($failing->refresh()->external_account_id)->toBe('old-uid-failing')
        ->and($sibling->refresh()->session_id)->toBe('sibling-session')
        ->and($siblingAccount->refresh()->external_account_id)->toBe('old-uid-'.$sibling->id);

    Queue::assertNothingPushed();
});

test('a reconnect logs the account it unlinks', function () {
    Queue::fake();
    Log::spy();

    $user = User::factory()->onboarded()->create();
    [$connection] = connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    $dropped = Account::factory()->create([
        'currency_code' => 'EUR',
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'iban' => 'ES0000000000000000000002',
    ]);

    mockSharedSession([['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001']]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($dropped->refresh()->external_account_id)->toBeNull();

    Log::shouldHaveReceived('warning')->withArgs(
        fn (string $message, array $context): bool => $message === 'Unlinked an EnableBanking account its new session does not return'
            && $context['account_id'] === $dropped->id,
    );
});

test('a connection through an api-key provider is never repointed even when its bank and iban match', function (BankingProvider $provider) {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$apiKeyConnection, $apiKeyAccount] = connectionWithAccount($user, 'ES0000000000000000000002', [
        'provider' => $provider,
        'session_id' => 'api-key-session',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')->assertRedirect();

    expect($apiKeyConnection->refresh()->session_id)->toBe('api-key-session');
    expect($apiKeyAccount->refresh()->external_account_id)->toBe('old-uid-'.$apiKeyConnection->id);
    Queue::assertNotPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job) => $job->bankingConnection->is($apiKeyConnection));
})->with(array_filter(BankingProvider::cases(), fn (BankingProvider $provider): bool => $provider->usesApiKey()));

test('another user\'s connection to the same bank is never repointed', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$strangerConnection] = connectionWithAccount(User::factory()->create(), 'ES0000000000000000000002', ['session_id' => 'stranger-session']);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($strangerConnection->refresh()->session_id)->toBe('stranger-session');
});

test('a sibling without stored ibans is never repointed by position', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, null, [
        'status' => BankingConnectionStatus::Expired,
        'session_id' => 'revoked-session',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    $sibling->refresh();
    expect($sibling->session_id)->toBe('revoked-session');
    expect($sibling->status)->toBe(BankingConnectionStatus::Expired);
    expect($siblingAccount->refresh()->external_account_id)->toBe('old-uid-'.$sibling->id);
    expect($siblingAccount->iban)->toBeNull();
});

test('the first connection callback also repoints an existing same-bank sibling', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000001', ['session_id' => 'soon-revoked-session']);
    $connection = BankingConnection::factory()->pending()->create([
        'user_id' => $user->id,
        'aspsp_name' => 'Joint Bank',
        'aspsp_country' => 'ES',
    ]);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-2', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code')
        ->assertRedirect(route('open-banking.map-accounts', ['connection' => $connection]));

    expect($connection->refresh()->status)->toBe(BankingConnectionStatus::AwaitingMapping);
    expect($sibling->refresh()->session_id)->toBe('shared-session');
    expect($sibling->status)->toBe(BankingConnectionStatus::Active);
    expect($siblingAccount->refresh()->external_account_id)->toBe('new-uid-1');

    Queue::assertPushed(SyncBankingConnectionJob::class, fn (SyncBankingConnectionJob $job): bool => $job->bankingConnection->is($sibling)
        && $job->trigger === BankingSyncTrigger::Reconnect);
});

test('the reconnecting connection never takes a sibling\'s uid by position', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    [$connection, $legacyAccount] = connectionWithAccount($user, null, ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['status' => BankingConnectionStatus::Expired]);

    // The sibling's account comes first, where the positional fallback would look.
    mockSharedSession([
        ['uid' => 'sibling-uid', 'iban' => 'ES0000000000000000000002'],
        ['uid' => 'own-uid', 'iban' => 'ES0000000000000000000001'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($siblingAccount->refresh()->external_account_id)->toBe('sibling-uid');
    expect($legacyAccount->refresh()->external_account_id)->toBe('own-uid');
    expect($sibling->refresh()->status)->toBe(BankingConnectionStatus::Active);
});

test('an iban the session lists twice is no evidence for repointing a sibling', function () {
    Queue::fake();

    $user = User::factory()->onboarded()->create();
    connectionWithAccount($user, 'ES0000000000000000000001', ['status' => BankingConnectionStatus::Pending]);
    [$sibling, $siblingAccount] = connectionWithAccount($user, 'ES0000000000000000000002', ['session_id' => 'other-session']);

    mockSharedSession([
        ['uid' => 'new-uid-1', 'iban' => 'ES0000000000000000000001'],
        ['uid' => 'new-uid-eur', 'iban' => 'ES0000000000000000000002'],
        ['uid' => 'new-uid-usd', 'iban' => 'ES0000000000000000000002'],
    ]);

    $this->actingAs($user)->get('/open-banking/callback?code=test-code');

    expect($sibling->refresh()->session_id)->toBe('other-session');
    expect($siblingAccount->refresh()->external_account_id)->toBe('old-uid-'.$sibling->id);
});
