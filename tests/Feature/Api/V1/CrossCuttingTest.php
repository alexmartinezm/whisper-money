<?php

use App\Http\ApiV1\ApiV1Exception;
use App\Http\Middleware\ApiV1\HandleIdempotencyKey;
use App\Models\ApiRequest;
use App\Models\User;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

use function Pest\Laravel\call;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

beforeEach(fn () => registerApiV1TestRoutes());

/**
 * A POST carrying an Idempotency-Key, as the middleware sees it once the
 * caller is authenticated with a personal access token.
 */
function idempotentRequest(string $content = '{"name":"Trips"}', ?User $user = null): Request
{
    $user ??= User::factory()->create();
    $user->withAccessToken($user->tokens()->first() ?? $user->createToken('api', ['api:read', 'api:write'])->accessToken);

    $request = Request::create('/api/v1/_test/echo', 'POST', content: $content);
    $request->headers->set('Idempotency-Key', 'k');
    $request->setUserResolver(fn (): User => $user);

    return $request;
}

/**
 * Make the default cache throw when the middleware claims a key ('claim') or
 * stores a finished response ('store'), and work otherwise.
 */
function useCacheFailingOn(string $step): void
{
    Cache::extend('flaky', fn () => new class(new ArrayStore, $step) extends Repository
    {
        public function __construct(ArrayStore $store, private string $step)
        {
            parent::__construct($store);
        }

        public function add($key, $value, $ttl = null): bool
        {
            throw_if($this->step === 'claim', new RuntimeException('cache is down'));

            return parent::add($key, $value, $ttl);
        }

        public function put($key, $value, $ttl = null): bool
        {
            throw_if($this->step === 'store' && isset($value['status']), new RuntimeException('cache is down'));

            return parent::put($key, $value, $ttl);
        }
    });

    config(['cache.stores.flaky' => ['driver' => 'flaky'], 'cache.default' => 'flaky']);
}

/**
 * Everything written to the cache from now on, last write last.
 *
 * @return Collection<int, array{key: string, value: mixed, seconds: ?int}>
 */
function spyOnCacheWrites(): Collection
{
    $writes = new Collection;

    Event::listen(KeyWritten::class, fn (KeyWritten $event) => $writes->push([
        'key' => $event->key,
        'value' => $event->value,
        'seconds' => $event->seconds,
    ]));

    return $writes;
}

describe('rate limit', function () {
    it('allows 60 requests a minute per user and reports the budget in headers', function () {
        $user = User::factory()->create();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60')
            ->assertHeader('X-RateLimit-Remaining', '59');

        for ($i = 2; $i <= 60; $i++) {
            withHeaders($headers)->getJson('/api/v1/spaces')->assertOk();
        }

        withHeaders($headers)->getJson('/api/v1/spaces')
            ->assertTooManyRequests()
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // Another user has a bucket of their own.
        withHeaders(apiV1Bearer(User::factory()->create()))->getJson('/api/v1/spaces')->assertOk();
    });

    it('caps requests per IP before authentication, so bogus tokens cannot flood the API', function () {
        $headers = ['Authorization' => 'Bearer not-a-real-token'];

        for ($i = 1; $i <= 120; $i++) {
            withHeaders($headers)->getJson('/api/v1/spaces')->assertUnauthorized();
        }

        withHeaders($headers)->getJson('/api/v1/spaces')
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('code', 'too_many_requests');

        // Another IP has a bucket of its own.
        withHeaders($headers)->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->getJson('/api/v1/spaces')
            ->assertUnauthorized();
    });

    it('throttles a user the plan gate refuses, and records the 429s', function () {
        config(['subscriptions.enabled' => true]);
        $headers = apiV1Bearer(User::factory()->create());

        for ($i = 1; $i <= 60; $i++) {
            withHeaders($headers)->getJson('/api/v1/spaces')->assertForbidden();
        }

        withHeaders($headers)->getJson('/api/v1/spaces')->assertTooManyRequests();

        expect(ApiRequest::query()->pluck('status')->countBy()->all())->toBe([403 => 60, 429 => 1]);
    });

    it('throttles the shared demo account', function () {
        config(['app.demo.email' => 'demo@example.test']);
        $headers = apiV1Bearer(User::factory()->create(['email' => 'demo@example.test']));

        for ($i = 1; $i <= 60; $i++) {
            withHeaders($headers)->getJson('/api/v1/spaces')->assertForbidden();
        }

        withHeaders($headers)->getJson('/api/v1/spaces')->assertTooManyRequests();
    });
});

describe('CORS', function () {
    it('answers a preflight from any origin on the public API, without credentials', function () {
        call('OPTIONS', '/api/v1/spaces', server: [
            'HTTP_ORIGIN' => 'https://some-spa.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization, idempotency-key',
        ])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    });

    it('exposes the rate-limit headers to browser clients', function () {
        withHeaders([...apiV1Bearer(User::factory()->create()), 'Origin' => 'https://some-spa.example'])
            ->getJson('/api/v1/spaces')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Expose-Headers', 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After, Idempotent-Replayed');
    });

    it('keeps the internal session API same-origin', function () {
        $response = withHeaders(['Origin' => 'https://some-spa.example'])->getJson('/api/transactions');

        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    });
});

describe('idempotency', function () {
    it('replays the first response for a repeated key instead of running the request again', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        $first = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();

        expect($replay->getContent())->toBe($first->getContent());
        $replay->assertHeader('Idempotent-Replayed', 'true');
        $first->assertHeaderMissing('Idempotent-Replayed');
    });

    it('runs the request every time without a key', function () {
        $headers = apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']);

        $first = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips']);
        $second = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips']);

        expect($first->json('data.id'))->not->toBe($second->json('data.id'));
    });

    it('rejects a key reused with a different body', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Holidays'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
    });

    it('rejects a key reused against another query string', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo?space=a', ['name' => 'Trips'])->assertCreated();

        withHeaders($headers)->postJson('/api/v1/_test/echo?space=b', ['name' => 'Trips'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Idempotency-Key');
    });

    it('does not keep a failed response, so the client can fix the body and retry with the same key', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Far too long a name'])->assertUnprocessable();

        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])
            ->assertCreated()
            ->assertHeaderMissing('Idempotent-Replayed');
    });

    it('scopes keys to the user', function () {
        $body = ['name' => 'Trips'];
        $first = withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);
        $other = withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);

        expect($other->json('data.id'))->not->toBe($first->json('data.id'));
        $other->assertHeaderMissing('Idempotent-Replayed');
    });

    it('scopes keys to the credential, so two tokens of one user never replay each other', function () {
        $user = User::factory()->create();
        $body = ['name' => 'Trips'];

        $first = withHeaders([...apiV1Bearer($user, ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);
        $other = withHeaders([...apiV1Bearer($user, ['api:read', 'api:write']), 'Idempotency-Key' => 'k'])
            ->postJson('/api/v1/_test/echo', $body);

        expect($other->json('data.id'))->not->toBe($first->json('data.id'));
        $other->assertHeaderMissing('Idempotent-Replayed');
    });

    it('scopes keys to the OAuth client, and replays across a refreshed access token', function () {
        $user = User::factory()->create();
        $body = ['name' => 'Trips'];
        $post = function (Client $client) use ($user, $body) {
            app('auth')->forgetGuards();
            Passport::actingAs($user, ['api:use'], client: $client);

            return withHeaders(['Idempotency-Key' => 'k'])->postJson('/api/v1/_test/echo', $body);
        };

        $first = $post(oauthClient('First app'));
        $sameClient = $post(Client::query()->findOrFail($first->baseRequest->attributes->get('oauth_client_id')));
        $otherClient = $post(oauthClient('Second app'));

        $sameClient->assertHeader('Idempotent-Replayed', 'true');
        expect($sameClient->json('data.id'))->toBe($first->json('data.id'));
        $otherClient->assertHeaderMissing('Idempotent-Replayed');
        expect($otherClient->json('data.id'))->not->toBe($first->json('data.id'));
    });

    it('keeps the stored body encrypted, and only for an hour', function () {
        $headers = [...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => 'create-1'];
        $writes = spyOnCacheWrites();

        $first = withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertCreated();

        $stored = $writes->last();
        expect($stored['seconds'])->toBeLessThanOrEqual(3600)
            ->and(serialize($stored['value']))->not->toContain('Trips')
            ->and(serialize($stored['value']))->not->toContain($first->json('data.id'))
            ->and(array_keys($stored['value']))->toBe(['fingerprint', 'status', 'content_type', 'location', 'body'])
            ->and(Crypt::decryptString($stored['value']['body']))->toBe($first->getContent());

        $this->travel(59)->minutes();
        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertHeader('Idempotent-Replayed', 'true');

        $this->travel(2)->minutes();
        withHeaders($headers)->postJson('/api/v1/_test/echo', ['name' => 'Trips'])->assertHeaderMissing('Idempotent-Replayed');
    });

    it('does not run a slow write twice, however long it takes', function () {
        $writes = 0;
        $request = idempotentRequest();
        $middleware = new HandleIdempotencyKey;

        $response = $middleware->handle($request, function () use (&$writes, $middleware, $request) {
            $writes++;
            // The retry arrives while the first write is still running, long
            // past the 30 seconds a lock around it used to last.
            $this->travel(10)->minutes();

            expect(fn () => $middleware->handle($request, function () use (&$writes) {
                $writes++;

                return new Response('second', 201);
            }))->toThrow(fn (ApiV1Exception $exception) => expect($exception->errorCode)->toBe('idempotency_conflict'));

            return new Response('first', 201);
        });

        expect($writes)->toBe(1)
            ->and($response->getContent())->toBe('first');
    });

    it('answers a committed write normally when its response cannot be stored, and refuses a retry', function () {
        $writes = 0;
        $request = idempotentRequest();
        $write = function () use (&$writes) {
            $writes++;

            return new Response('created', 201);
        };
        Log::spy();
        useCacheFailingOn('store');

        $response = (new HandleIdempotencyKey)->handle($request, $write);

        expect($response->getStatusCode())->toBe(201)
            ->and($response->getContent())->toBe('created');

        expect(fn () => (new HandleIdempotencyKey)->handle($request, $write))
            ->toThrow(fn (ApiV1Exception $exception) => expect($exception->getStatusCode())->toBe(409));
        expect($writes)->toBe(1);
    });

    it('runs nothing when the key cannot be claimed', function () {
        $writes = 0;
        useCacheFailingOn('claim');

        expect(fn () => (new HandleIdempotencyKey)->handle(idempotentRequest(), function () use (&$writes) {
            $writes++;

            return new Response('created', 201);
        }))->toThrow(RuntimeException::class, 'cache is down');

        expect($writes)->toBe(0);
    });

    it('lets a retry through after a request that threw before answering', function () {
        $request = idempotentRequest();

        expect(fn () => (new HandleIdempotencyKey)->handle($request, fn () => throw new RuntimeException('rolled back')))
            ->toThrow(RuntimeException::class, 'rolled back');

        $retry = (new HandleIdempotencyKey)->handle($request, fn () => new Response('created', 201));

        expect($retry->getContent())->toBe('created');
    });

    it('refuses a different request on a key still in flight', function () {
        $request = idempotentRequest();
        $middleware = new HandleIdempotencyKey;

        $middleware->handle($request, function () use ($middleware, $request) {
            $other = idempotentRequest(content: '{"name":"Holidays"}', user: $request->user());

            expect(fn () => $middleware->handle($other, fn () => new Response('second', 201)))
                ->toThrow(ValidationException::class);

            return new Response('first', 201);
        });
    });

    it('rejects a key longer than 255 characters', function () {
        withHeaders([...apiV1Bearer(User::factory()->create(), ['api:read', 'api:write']), 'Idempotency-Key' => str_repeat('k', 256)])
            ->postJson('/api/v1/_test/echo')
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_idempotency_key');
    });
});

describe('errors', function () {
    it('answers an unknown endpoint with the JSON error shape, even without an Accept header', function () {
        $this->get('/api/v1/nope')
            ->assertNotFound()
            ->assertExactJson(['message' => 'The route api/v1/nope could not be found.', 'code' => 'not_found']);
    });

    it('answers a wrong method with the JSON error shape', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->deleteJson('/api/v1/spaces')
            ->assertMethodNotAllowed()
            ->assertJsonPath('code', 'method_not_allowed');
    });

    it('hides the internals of a server error', function () {
        Log::spy();

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->get('/api/v1/_test/boom')
            ->assertInternalServerError()
            ->assertExactJson(['message' => 'Internal Server Error', 'code' => 'internal_server_error']);
    });

    it('keeps the standard validation body for 422s', function () {
        $foreign = User::factory()->create()->personalSpace;

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->get("/api/v1/_test/space?space={$foreign->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => "You do not have access to a space with id {$foreign->id}. List the valid ids with GET /api/v1/spaces.",
                'errors' => ['space' => ["You do not have access to a space with id {$foreign->id}. List the valid ids with GET /api/v1/spaces."]],
            ]);
    });

    it('leaves the internal API errors untouched', function () {
        getJson('/api/transactions')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    });
});
