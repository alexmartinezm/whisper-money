<?php

use App\Services\CurrencyConversionService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Cache::flush();
});

/**
 * The deadlock the database cache store raises when concurrent requests race
 * to `insert ignore` the same rate row.
 */
function deadlockOnCacheInsert(): QueryException
{
    return new QueryException(
        'mysql',
        'insert ignore into `cache` (`key`, `value`, `expiration`) values (?, ?, ?)',
        ['currency-rates:eur:2026-01-15', 'serialized-rates', 1767225600],
        new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'),
    );
}

/**
 * Answers each provider URL by date and source, counting the calls: a release
 * by default 404, `['2026-01-15' => [500, 404]]` for the primary and the
 * fallback source, a status of 200 for a release.
 *
 * @param  array<string, array{0: int, 1: int}>  $statusByDate
 */
function fakeRateSources(array $statusByDate): stdClass
{
    $calls = new stdClass;
    $calls->count = 0;

    Http::fake(function (Request $request) use ($statusByDate, $calls) {
        $calls->count++;
        preg_match('/\d{4}-\d{2}-\d{2}/', $request->url(), $date);
        $status = ($statusByDate[$date[0]] ?? [404, 404])[str_contains($request->url(), 'jsdelivr') ? 0 : 1];

        return $status === 200
            ? Http::response(['eur' => ['btc' => 0.5]])
            : Http::response('', $status);
    });

    return $calls;
}

test('converts crypto to fiat using CDN rates', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
                'eth' => 0.0004,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;

    // 1 EUR = 0.000015 BTC → 1 BTC = 1/0.000015 = 66666.67 EUR
    // 2 BTC = 133333.33 EUR
    $result = $service->convert('BTC', 'EUR', 2.0, '2026-01-15');

    expect($result)->toBe(2.0 / 0.000015);
});

test('returns quantity when source equals target', function () {
    Http::fake();

    $service = new CurrencyConversionService;
    $result = $service->convert('EUR', 'EUR', 150.0, '2026-01-15');

    expect($result)->toBe(150.0);
    Http::assertNothingSent();
});

test('returns zero when source currency not found', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('UNKNOWN', 'EUR', 10.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('uses fallback URL when primary fails', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Server Error', 500),
        'currency-api.pages.dev/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.00002,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(1.0 / 0.00002);
});

test('degrades to zero when both primary and fallback fail', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Server Error', 500),
        'currency-api.pages.dev/*' => Http::response('Server Error', 500),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('degrades to zero and stops walking dates when the source times out', function () {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'EUR', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);

    // Only the first candidate date is attempted (primary + fallback); a timing
    // out source must not be retried across every historical lookback date.
    expect($attempts)->toBe(2);
});

test('caches rates across service instances so repeat lookups skip HTTP', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => ['btc' => 0.000015],
        ]),
    ]);

    (new CurrencyConversionService)->convert('BTC', 'EUR', 1.0, '2026-01-15');
    (new CurrencyConversionService)->convert('BTC', 'EUR', 1.0, '2026-01-15');

    Http::assertSentCount(1);
});

test('returns zero when base currency is unknown and all sources return 404', function () {
    Http::fake([
        'cdn.jsdelivr.net/*' => Http::response('Not Found', 404),
        'currency-api.pages.dev/*' => Http::response('Not Found', 404),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('BTC', 'XXX', 1.0, '2026-01-15');

    expect($result)->toBe(0.0);
});

test('falls back to previous historical date when requested release is missing', function () {
    Http::fake([
        'cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@2025-12-10/*' => Http::response('Not Found', 404),
        'currency-api.pages.dev/v1/2025-12-10/*' => Http::response('Not Found', 404),
        'cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@2025-12-09/*' => Http::response([
            'usd' => [
                'eur' => 0.9,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('EUR', 'USD', 90.0, '2025-12-10');

    expect($result)->toBe(100.0);
    Http::assertSentCount(3);
});

test('caches rates so same currency and date makes only one HTTP request', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
                'eth' => 0.0004,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $service->convert('BTC', 'EUR', 1.0, '2026-01-15');
    $service->convert('ETH', 'EUR', 5.0, '2026-01-15');

    Http::assertSentCount(1);
});

test('different dates make separate requests', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => [
                'btc' => 0.000015,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $service->convert('BTC', 'EUR', 1.0, '2026-01-15');
    $service->convert('BTC', 'EUR', 1.0, '2026-01-16');

    Http::assertSentCount(2);
});

test('converts new latam fiat currency using CDN rates', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/usd*' => Http::response([
            'usd' => [
                'ars' => 1400.0,
            ],
        ]),
    ]);

    $service = new CurrencyConversionService;
    $result = $service->convert('ARS', 'USD', 2800.0, '2026-01-15');

    expect($result)->toBe(2.0);
});

test('returns the fetched rates when the cache write deadlocks (regression for PHP-LARAVEL-J)', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => ['btc' => 0.000015],
        ]),
    ]);

    Log::spy();

    Cache::shouldReceive('get')->andReturnNull();
    Cache::shouldReceive('put')->once()->andThrow(deadlockOnCacheInsert());

    $rates = (new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15');

    expect($rates)->toBe(['btc' => 0.000015]);

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $message === 'Currency rate cache write failed'
            && $context['key'] === 'currency-rates:eur:2026-01-15');
});

test('memoizes rates in-request when the cache write deadlocks', function () {
    Http::fake([
        'cdn.jsdelivr.net/*currencies/eur*' => Http::response([
            'eur' => ['btc' => 0.000015],
        ]),
    ]);

    Cache::shouldReceive('get')->andReturnNull();
    Cache::shouldReceive('put')->andThrow(deadlockOnCacheInsert());

    $service = new CurrencyConversionService;
    $service->getRatesForCurrency('EUR', '2026-01-15');
    $service->getRatesForCurrency('EUR', '2026-01-15');

    Http::assertSentCount(1);
});

test('the cache write guard does not swallow a broken cache read', function () {
    Http::fake();

    Cache::shouldReceive('get')->andThrow(deadlockOnCacheInsert());

    expect(fn () => (new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15'))
        ->toThrow(QueryException::class);
});

// What every caller but the day totals relies on, checked against main's
// service as well: a server error never ends the walk there.

test('a plain lookup walks past a date whose sources both answer 500, to a release', function () {
    $calls = fakeRateSources(['2026-01-15' => [500, 500], '2026-01-14' => [200, 404]]);

    expect((new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15'))->toBe(['btc' => 0.5])
        ->and($calls->count)->toBe(3)
        ->and(Cache::get('currency-rates:eur:2026-01-15'))->toBe(['btc' => 0.5]);
});

test('a plain lookup walks past a 500 and 404 pair, to a release', function (array $pair) {
    $calls = fakeRateSources(['2026-01-15' => $pair, '2026-01-14' => [404, 200]]);

    expect((new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15'))->toBe(['btc' => 0.5])
        ->and($calls->count)->toBe(4);
})->with([
    'primary failing' => [[500, 404]],
    'fallback failing' => [[404, 500]],
]);

test('a plain lookup with no release in reach tries every date and caches the empty map briefly', function () {
    $calls = fakeRateSources(['2026-01-15' => [500, 500]]);

    expect((new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15'))->toBe([])
        ->and($calls->count)->toBe(16)
        ->and(Cache::get('currency-rates:eur:2026-01-15'))->toBe([]);

    $this->travel(601)->seconds();

    expect(Cache::get('currency-rates:eur:2026-01-15'))->toBeNull();
});

test('a plain lookup keeps a walked release for thirty days', function () {
    fakeRateSources(['2026-01-14' => [200, 404]]);

    (new CurrencyConversionService)->getRatesForCurrency('EUR', '2026-01-15');

    $this->travel(29)->days();
    expect(Cache::get('currency-rates:eur:2026-01-15'))->toBe(['btc' => 0.5]);

    $this->travel(2)->days();
    expect(Cache::get('currency-rates:eur:2026-01-15'))->toBeNull();
});

test('a bounded lookup stops at the first failing date and stores nothing', function (array $pair) {
    $calls = fakeRateSources(['2026-01-15' => $pair, '2026-01-14' => [200, 200]]);

    expect((new CurrencyConversionService)->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBeNull()
        ->and($calls->count)->toBe(2)
        ->and(Cache::get('currency-rates:eur:2026-01-15'))->toBeNull()
        ->and(Cache::get('currency-rates-no-release:eur:2026-01-15'))->toBeNull();
})->with([
    'both failing' => [[500, 503]],
    'primary failing, fallback without a release' => [[500, 404]],
    'fallback failing, primary without a release' => [[404, 500]],
]);

test('a bounded lookup counts a timeout as a failure', function () {
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    expect((new CurrencyConversionService)->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBeNull()
        ->and($attempts)->toBe(2);
});

test('a bounded lookup uses the other source when one fails', function () {
    $calls = fakeRateSources(['2026-01-15' => [500, 200]]);

    expect((new CurrencyConversionService)->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBe(['btc' => 0.5])
        ->and($calls->count)->toBe(2);
});

test('a bounded lookup walks past a 404 and keeps the release as a plain lookup does', function () {
    $calls = fakeRateSources(['2026-01-14' => [200, 404]]);

    expect((new CurrencyConversionService)->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBe(['btc' => 0.5])
        ->and($calls->count)->toBe(3)
        ->and(Cache::get('currency-rates:eur:2026-01-15'))->toBe(['btc' => 0.5]);
});

test('a bounded lookup notes a date with no release under its own key, which a plain lookup never reads', function () {
    $calls = fakeRateSources([]);
    $service = new CurrencyConversionService;

    expect($service->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBe([])
        ->and($calls->count)->toBe(16)
        ->and(Cache::get('currency-rates:eur:2026-01-15'))->toBeNull();

    expect($service->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBe([])
        ->and($calls->count)->toBe(16);

    $service->getRatesForCurrency('EUR', '2026-01-15');

    expect($calls->count)->toBe(32);
});

test('a failed bounded lookup leaves the next plain lookup as if it had not run', function () {
    $calls = fakeRateSources(['2026-01-15' => [500, 404], '2026-01-14' => [200, 404]]);
    $service = new CurrencyConversionService;

    expect($service->ratesOrNullOnFailure('EUR', '2026-01-15'))->toBeNull();

    $calls->count = 0;

    expect($service->getRatesForCurrency('EUR', '2026-01-15'))->toBe(['btc' => 0.5])
        ->and($calls->count)->toBe(3);
});
