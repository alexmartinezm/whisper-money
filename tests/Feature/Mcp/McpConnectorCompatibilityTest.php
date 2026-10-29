<?php

use App\Enums\OAuthApprovalRefusal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as IlluminateRoute;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;
use Laravel\Passport\Token;
use League\OAuth2\Server\AuthorizationServer;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
| The owner's claude.ai connector signs in to `/mcp/oauth` with OAuth. These
| walk what it does, as it does it, and pin what the OAuth hardening taken from
| upstream must not change for a connection that already exists.
*/

/**
 * A `tools/call` for list_spaces, the bearer token as the only credential.
 */
function callConnector(string $accessToken): TestResponse
{
    forgetSignedInUser();

    return withHeaders(['Authorization' => "Bearer {$accessToken}"])->postJson('/mcp/oauth', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => ['name' => 'list_spaces', 'arguments' => (object) []],
    ]);
}

/**
 * Register the connector through dynamic client registration.
 */
function registerConnector(): Client
{
    $registered = postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [CLAUDE_CALLBACK],
    ])->assertCreated()->json();

    return Passport::client()->newQuery()->findOrFail($registered['client_id']);
}

/**
 * Sign the user in through the consent screen and exchange the code.
 *
 * @param  list<string>|null  $scopes
 * @return array{access_token: string, refresh_token: string}
 */
function connectConnector(User $user, Client $client, ?array $scopes): array
{
    [$verifier, $challenge] = pkcePair();

    $consent = requestOAuthConsent($user, $client, $scopes, $challenge)->assertOk();
    $code = oauthRedirectQuery(approveOAuthConsent($user, $client, $consent)->assertRedirect())['code'] ?? null;

    return post('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $client->id,
        'redirect_uri' => CLAUDE_CALLBACK,
        'code_verifier' => $verifier,
        'code' => $code,
    ])->assertOk()->json();
}

/**
 * @return array{access_token: string, refresh_token: string}
 */
function refreshConnector(Client $client, string $refreshToken): array
{
    return post('/oauth/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $client->id,
        'refresh_token' => $refreshToken,
    ])->assertOk()->json();
}

/**
 * A connection as it was authorized before `mcp:use` became the default scope:
 * a connector that asked for no scope got a token with none.
 *
 * @return array{access_token: string, refresh_token: string}
 */
function connectBeforeDefaultScope(User $user, Client $client): array
{
    $defaultScope = Passport::$defaultScope;

    Passport::$defaultScope = '';
    app()->forgetInstance(AuthorizationServer::class);

    try {
        return connectConnector($user, $client, null);
    } finally {
        Passport::$defaultScope = $defaultScope;
        app()->forgetInstance(AuthorizationServer::class);
    }
}

it('walks the connector from discovery to a refreshed token', function () {
    $resource = get('/.well-known/oauth-protected-resource/mcp/oauth')->assertOk()->json();
    $server = get('/.well-known/oauth-authorization-server')->assertOk()->json();

    expect($resource['scopes_supported'])->toBe(['mcp:use'])
        ->and($server['scopes_supported'])->toBe(['mcp:use'])
        ->and($server['grant_types_supported'])->toBe(['authorization_code', 'refresh_token']);

    $user = User::factory()->create();
    $client = registerConnector();

    $tokens = connectConnector($user, $client, $resource['scopes_supported']);
    callConnector($tokens['access_token'])->assertOk()->assertSee('Personal', false);

    $refreshed = refreshConnector($client, $tokens['refresh_token']);
    callConnector($refreshed['access_token'])->assertOk()->assertSee('Personal', false);

    expect(Token::query()->where('user_id', $user->id)->pluck('scopes')->all())
        ->toBe([['mcp:use'], ['mcp:use']]);
});

it('connects a client that requests every scope the authorization server lists', function () {
    $scopes = get('/.well-known/oauth-authorization-server')->assertOk()->json('scopes_supported');

    $tokens = connectConnector(User::factory()->create(), registerConnector(), $scopes);

    callConnector($tokens['access_token'])->assertOk();
});

it('connects a client that requests no scope at all', function () {
    $tokens = connectConnector(User::factory()->create(), registerConnector(), null);

    callConnector($tokens['access_token'])->assertOk();
});

it('reconnects without asking again while the connection is alive', function () {
    $user = User::factory()->create();
    $client = registerConnector();
    connectConnector($user, $client, ['mcp:use']);
    [, $challenge] = pkcePair();

    $response = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertRedirect();

    expect(oauthRedirectQuery($response))->toHaveKey('code');
});

it('serves each resource its own scope with cached routes, as production runs', function (string $path, string $scope) {
    $request = Request::create($path);
    $route = Route::getRoutes()->match($request);
    $route->prepareForSerialization();

    /** @var IlluminateRoute $restored */
    $restored = unserialize(serialize($route));
    $restored->setRouter(app('router'))->setContainer(app())->bind($request);
    app()->instance(IlluminateRoute::class, $restored);

    expect($restored->run()->getData(true)['scopes_supported'])->toBe([$scope]);
})->with([
    'MCP' => ['/.well-known/oauth-protected-resource/mcp/oauth', 'mcp:use'],
    'API' => ['/.well-known/oauth-protected-resource/api/v1', 'api:use'],
]);

describe('connections authorized before this change', function () {
    it('keeps working when they carry no scope, refreshed or not', function () {
        $user = User::factory()->create();
        $client = registerConnector();

        $tokens = connectBeforeDefaultScope($user, $client);
        expect(Token::query()->sole()->scopes)->toBe([]);

        callConnector($tokens['access_token'])->assertOk()->assertSee('Personal', false);

        $refreshed = refreshConnector($client, $tokens['refresh_token']);
        callConnector($refreshed['access_token'])->assertOk()->assertSee('Personal', false);
    });

    it('stay out of the API when they carry no scope', function () {
        $tokens = connectBeforeDefaultScope(User::factory()->create(), registerConnector());
        forgetSignedInUser();

        withHeaders(['Authorization' => "Bearer {$tokens['access_token']}"])
            ->getJson('/api/v1/spaces')
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    });

    it('are listed and can be disconnected when they carry no scope', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        $tokens = connectBeforeDefaultScope($user, $client);

        actingAs($user)->get(route('mcp.index'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('connectedApps', 1)
                ->where('connectedApps.0.id', $client->id)
                ->where('connectedApps.0.scopes', ['AI Connector']));

        actingAs($user)->delete(route('mcp.apps.destroy', $client->id))->assertRedirect();

        callConnector($tokens['access_token'])->assertUnauthorized();
    });
});

describe('consent screen', function () {
    /**
     * What the screen showed before it moved to `oauth.authorize`, by language
     * key, so nothing the owner reads when connecting is lost.
     *
     * @return array<string, array<string, string>>
     */
    function consentCopy(): array
    {
        return [
            'Connect :client to :app' => ['client' => 'Claude', 'app' => config('app.name')],
            ':client is asking to read and make changes to your finance data.' => ['client' => 'Claude'],
            'Signed in as' => [],
            'Sends you back to' => [],
            'This connection can:' => [],
            'Read and analyse your transactions, balances, categories and spending.' => [],
            'Create, edit and delete transactions, categories, labels and automation rules.' => [],
            'Transactions synced from your bank cannot be edited or deleted, and bank-connected balances stay untouched. You can disconnect it at any time from the connected app.' => [],
            'Cancel' => [],
            'Connect' => [],
            'Connecting…' => [],
        ];
    }

    it('keeps everything the old screen showed', function (string $locale) {
        $user = User::factory()->create(['locale' => $locale, 'email' => 'owner@example.test']);
        app()->setLocale($locale);
        [, $challenge] = pkcePair();

        $response = requestOAuthConsent($user, registerConnector(), ['mcp:use'], $challenge)
            ->assertOk()
            ->assertSee('owner@example.test', false)
            ->assertSee('https://claude.ai', false)
            ->assertSee('name="auth_token"', false)
            ->assertSee('id="authorizeForm"', false)
            // Self-hosted fonts only: the fork's screen calls no third party.
            ->assertDontSee('fonts.bunny.net', false);

        foreach (consentCopy() as $key => $replacements) {
            $response->assertSee(__($key, $replacements, $locale), false);
        }
    })->with(['en', 'es']);

    it('has every string of the screen in Spanish', function () {
        $translations = json_decode(File::get(lang_path('es.json')), true);

        preg_match_all("/__\\('([^']+)'/", File::get(resource_path('views/oauth/authorize.blade.php')), $matches);

        expect($matches[1])->not->toBeEmpty();

        foreach ($matches[1] as $key) {
            expect(array_key_exists($key, $translations))->toBeTrue("Missing translation: {$key}");
        }
    });

    it('has the refusal reasons in Spanish', function (OAuthApprovalRefusal $refusal) {
        app()->setLocale('en');
        [$title, $message] = [$refusal->title(), $refusal->message()];

        app()->setLocale('es');

        expect($refusal->title())->not->toBe($title)
            ->and($refusal->message())->not->toBe($message);
    })->with(OAuthApprovalRefusal::cases());
});

describe('framing', function () {
    it('forbids framing only, so approving still redirects back to the connector', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        [, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'");

        $approved = approveOAuthConsent($user, $client, $consent)->assertRedirect();

        expect((string) $approved->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK.'?')
            ->and(oauthRedirectQuery($approved))->toHaveKey('code')
            ->and(oauthRedirectQuery($approved)['state'])->toBe('state-token');
    });

    it('still lets the user cancel', function () {
        $user = User::factory()->create();
        $client = registerConnector();
        [, $challenge] = pkcePair();

        $consent = requestOAuthConsent($user, $client, ['mcp:use'], $challenge)->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', $consent->getContent(), $matches);

        $denied = test()->actingAs($user)->delete('/oauth/authorize', [
            'auth_token' => $matches[1],
            'client_id' => $client->id,
            'state' => 'state-token',
        ])->assertRedirect();

        expect((string) $denied->headers->get('Location'))->toStartWith(CLAUDE_CALLBACK)
            ->and(oauthRedirectQuery($denied)['error'] ?? null)->toBe('access_denied');
    });

    it('leaves the machine-to-machine OAuth endpoints answering as before', function () {
        $server = get('/.well-known/oauth-authorization-server')->assertOk();

        expect($server->headers->has('X-Frame-Options'))->toBeFalse();

        $client = registerConnector();
        $tokens = connectConnector(User::factory()->create(), $client, ['mcp:use']);

        expect($tokens['access_token'])->not->toBeEmpty();
    });
});
