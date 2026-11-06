<?php

namespace App\Http\Middleware\ApiV1;

use App\Http\ApiV1\ApiV1Exception;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Optional `Idempotency-Key` header on `/api/v1` POSTs, so a client can retry
 * a create after a timeout without creating it twice. The first response for
 * a (user, credential, key) triple is kept and replayed verbatim, flagged with
 * `Idempotent-Replayed: true`. Reusing the key for a different request is a
 * 422, and a retry racing the original still in flight is a 409.
 *
 * Only successful responses are kept: a retry after a validation error or a
 * 5xx runs again, so the client can fix the body without minting a new key.
 * The middleware cannot tell a 5xx that rolled back from one raised after the
 * write committed, so a failure of the second kind can run the write again.
 *
 * The key is claimed atomically with `Cache::add()` before the request runs,
 * and the entry stays in place until the request ends. Nothing about the write
 * can expire the claim short of it running for the whole TtlSeconds, so a slow
 * write cannot run twice. A claim that is never finished (the worker died, the
 * response could not be stored) keeps refusing the key with a 409 until it
 * expires, and a failed store does not touch the answer the client gets.
 *
 * What is kept is the status, the two headers a replay needs and the body,
 * encrypted with the app key since it can hold financial data. The request
 * fingerprint is keyed for the same reason: a plain hash of a small body could
 * be brute-forced.
 */
class HandleIdempotencyKey
{
    private const string Header = 'Idempotency-Key';

    /**
     * One hour. A retry after a timeout comes within seconds, and a client
     * backing off through a bad deploy within minutes; past the hour it is a
     * new attempt, and the cache should not hold financial responses for it.
     */
    private const int TtlSeconds = 3600;

    private const int MaxKeyLength = 255;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::Header);

        if (! $request->isMethod('POST') || $key === null) {
            return $next($request);
        }

        if ($key === '' || strlen($key) > self::MaxKeyLength) {
            throw ApiV1Exception::badRequest('invalid_idempotency_key', 'The Idempotency-Key header must be between 1 and 255 characters.');
        }

        $cacheKey = 'api-v1:idempotency:'.$this->caller($request).':'.hash('sha256', $key);
        $fingerprint = hash_hmac('sha256', $request->method().' '.$request->getRequestUri()."\n".$request->getContent(), (string) config('app.key'));

        if (! Cache::add($cacheKey, ['fingerprint' => $fingerprint], self::TtlSeconds)) {
            return $this->replay(Cache::get($cacheKey), $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->release($cacheKey);

            throw $exception;
        }

        if ($response->isSuccessful()) {
            $this->store($cacheKey, $fingerprint, $response);
        } else {
            $this->release($cacheKey);
        }

        return $response;
    }

    /**
     * Who the key belongs to: the user and the credential, so two apps of one
     * user never see each other's responses. An OAuth app is its client, whose
     * retries outlive a refreshed access token; a personal access token is
     * itself.
     */
    private function caller(Request $request): string
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        $credential = $token instanceof PersonalAccessToken
            ? 'pat:'.$token->getKey()
            : 'oauth:'.$request->attributes->get('oauth_client_id');

        return $user?->getAuthIdentifier().':'.$credential;
    }

    /**
     * @param  array{fingerprint: string, status?: int, content_type?: ?string, location?: ?string, body?: string}|null  $stored
     */
    private function replay(?array $stored, string $fingerprint): Response
    {
        if ($stored !== null && ! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw ValidationException::withMessages([
                self::Header => 'This Idempotency-Key was already used with a different request.',
            ]);
        }

        if (! isset($stored['status'], $stored['body'])) {
            throw new ApiV1Exception(409, 'idempotency_conflict', 'A request with this Idempotency-Key is still being processed.');
        }

        return new HttpResponse(Crypt::decryptString($stored['body']), $stored['status'], array_filter([
            'Content-Type' => $stored['content_type'] ?? null,
            'Location' => $stored['location'] ?? null,
            'Idempotent-Replayed' => 'true',
        ]));
    }

    /**
     * Rescued: by now the write has committed, so a cache failure is reported
     * rather than answered with a 500 the client would retry.
     */
    private function store(string $cacheKey, string $fingerprint, Response $response): void
    {
        rescue(fn (): bool => Cache::put($cacheKey, [
            'fingerprint' => $fingerprint,
            'status' => $response->getStatusCode(),
            'content_type' => $response->headers->get('Content-Type'),
            'location' => $response->headers->get('Location'),
            'body' => Crypt::encryptString((string) $response->getContent()),
        ], self::TtlSeconds));
    }

    /**
     * Rescued: a claim left behind only refuses retries until it expires.
     */
    private function release(string $cacheKey): void
    {
        rescue(fn (): bool => Cache::forget($cacheKey));
    }
}
