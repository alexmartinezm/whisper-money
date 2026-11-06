<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CurrencyConversionService
{
    private const PRIMARY_URL = 'https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@';

    private const FALLBACK_URL = 'https://currency-api.pages.dev/v1/';

    private const HISTORICAL_LOOKBACK_DAYS = 7;

    private const HTTP_CONNECT_TIMEOUT_SECONDS = 3;

    private const HTTP_TIMEOUT_SECONDS = 5;

    private const CACHE_TTL_HISTORICAL_SECONDS = 60 * 60 * 24 * 30;

    private const CACHE_TTL_LATEST_SECONDS = 60 * 60 * 6;

    private const CACHE_TTL_UNAVAILABLE_SECONDS = 60 * 10;

    /** @var array<string, array<string, float>> Keyed by "{currency}:{date}" */
    private array $rateCache = [];

    /**
     * Convert a quantity from one currency to another on a given date.
     *
     * @param  string  $source  Source currency code (e.g., "btc", "eth", "usd")
     * @param  string  $target  Target currency code (e.g., "eur", "usd")
     * @param  float  $quantity  Amount to convert
     * @param  string  $date  Date string (YYYY-MM-DD) or "latest"
     */
    public function convert(string $source, string $target, float $quantity, string $date = 'latest'): float
    {
        $source = strtolower($source);
        $target = strtolower($target);

        if ($source === $target) {
            return $quantity;
        }

        $rates = $this->getRatesForCurrency($target, $date);

        if (! isset($rates[$source]) || $rates[$source] == 0) {
            Log::debug('Currency rate not found', [
                'source' => $source,
                'target' => $target,
                'date' => $date,
            ]);

            return 0.0;
        }

        return $quantity / $rates[$source];
    }

    /**
     * Fetch all rates for a base currency on a given date.
     *
     * Returns a map of currency code => rate relative to the base currency.
     * Results are cached in-memory for the duration of the request.
     *
     * @return array<string, float>
     */
    public function getRatesForCurrency(string $currency, string $date): array
    {
        $currency = strtolower($currency);
        $cacheKey = "{$currency}:{$date}";

        if (isset($this->rateCache[$cacheKey])) {
            return $this->rateCache[$cacheKey];
        }

        $persistentKey = "currency-rates:{$cacheKey}";

        $rates = Cache::get($persistentKey);

        if ($rates === null) {
            $rates = $this->fetchRates($currency, $date);
            $this->cacheRates($persistentKey, $rates, $date);
        }

        $this->rateCache[$cacheKey] = $rates;

        return $rates;
    }

    /**
     * The rates of one date for a caller getting through many dates in one
     * response, which has to know when to stop asking: an answer that tells a
     * failure from a date with no release, at the cost of at most both sources
     * of one date when the sources are down.
     *
     * Rates it finds are cached as {@see self::getRatesForCurrency()} caches
     * them. It never writes an empty map where that method reads: a failure
     * stores nothing, and a date with no release is noted under a key of its
     * own, so whatever happens here, the next plain lookup behaves as if this
     * one had not run.
     *
     * @return array<string, float>|null The rates, empty when no release covers
     *                                   the date, null when a source failed
     */
    public function ratesOrNullOnFailure(string $currency, string $date): ?array
    {
        $currency = strtolower($currency);
        $cacheKey = "{$currency}:{$date}";
        $persistentKey = "currency-rates:{$cacheKey}";
        $noReleaseKey = "currency-rates-no-release:{$cacheKey}";

        $held = $this->rateCache[$cacheKey] ?? Cache::get($persistentKey);

        if ($held !== null) {
            return $held;
        }

        if (Cache::get($noReleaseKey) !== null) {
            return [];
        }

        $rates = $this->fetchRates($currency, $date, stopAtFailure: true);

        if ($rates === null) {
            return null;
        }

        if ($rates === []) {
            $this->cacheRates($noReleaseKey, $rates, $date);

            return [];
        }

        $this->cacheRates($persistentKey, $rates, $date);

        return $this->rateCache[$cacheKey] = $rates;
    }

    /**
     * Persist a fetched rate map, best-effort.
     *
     * The database cache store turns a write into an `insert ignore into
     * cache`, so concurrent requests missing the same hot rate key race for
     * the same row and InnoDB deadlocks one of them. The rates are already in
     * hand by then, so losing that race is no reason to fail the request: the
     * request that won it populated the row, and the next miss re-attempts the
     * write anyway.
     *
     * Any write failure is swallowed, not only the deadlock: each one costs a
     * value the next miss re-fetches, so none of them is worth a 500. Only the
     * write is guarded — a structurally broken cache backend still surfaces,
     * because the read a few lines above is not.
     *
     * @param  array<string, float>  $rates
     */
    private function cacheRates(string $persistentKey, array $rates, string $date): void
    {
        try {
            Cache::put($persistentKey, $rates, $this->cacheTtlFor($date, $rates));
        } catch (QueryException $e) {
            Log::warning('Currency rate cache write failed', [
                'key' => $persistentKey,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Fetch rates from CDN with fallback.
     *
     * A missing release (404) walks back to earlier historical dates, and so
     * does a source answering with an error, but an unreachable source
     * (connection refused or timeout) aborts the walk: the same timeout would
     * repeat for every candidate date and risk exhausting the request's
     * execution time. Failures degrade to an empty rate map rather than
     * throwing, so a slow CDN never crashes the calling endpoint.
     *
     * With $stopAtFailure a source answering with anything but a release or a
     * 404 aborts the walk too, even when the other one answers 404, and a walk
     * ended by a failure of either kind returns null instead of an empty map.
     *
     * @return ($stopAtFailure is true ? array<string, float>|null : array<string, float>)
     */
    private function fetchRates(string $currency, string $date, bool $stopAtFailure = false): ?array
    {
        $failed = false;

        foreach ($this->candidateDates($date) as $candidateDate) {
            $release = $this->fetchRelease($currency, $candidateDate);

            if ($release['rates'] !== null) {
                return $release['rates'];
            }

            $failed = $failed || $release['unreachable'] || $release['errored'];

            if ($release['unreachable'] || ($stopAtFailure && $release['errored'])) {
                break;
            }
        }

        Log::warning('Currency rates unavailable', [
            'currency' => $currency,
            'date' => $date,
        ]);

        return $stopAtFailure && $failed ? null : [];
    }

    /**
     * One date's release, from the first source that has it, and how the
     * sources that did not answered.
     *
     * @return array{rates: array<string, float>|null, unreachable: bool, errored: bool}
     */
    private function fetchRelease(string $currency, string $date): array
    {
        $release = ['rates' => null, 'unreachable' => false, 'errored' => false];

        foreach ($this->rateUrls($currency, $date) as $url) {
            try {
                $response = Http::connectTimeout(self::HTTP_CONNECT_TIMEOUT_SECONDS)
                    ->timeout(self::HTTP_TIMEOUT_SECONDS)
                    ->get($url);
            } catch (ConnectionException $e) {
                $release['unreachable'] = true;

                Log::warning('Currency rate source unreachable', [
                    'currency' => $currency,
                    'date' => $date,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($response->notFound()) {
                continue;
            }

            if ($response->successful()) {
                $release['rates'] = $response->json($currency) ?? [];

                return $release;
            }

            $release['errored'] = true;

            Log::warning('Currency rate source returned an error', [
                'currency' => $currency,
                'date' => $date,
                'status' => $response->status(),
            ]);
        }

        return $release;
    }

    /**
     * Resolve the cache lifetime for a fetched rate map.
     *
     * Historical releases are immutable, so cache them long. The "latest"
     * release changes daily. An empty result means the sources were missing or
     * unreachable; cache it briefly so a transient outage recovers quickly.
     *
     * @param  array<string, float>  $rates
     */
    private function cacheTtlFor(string $date, array $rates): int
    {
        if ($rates === []) {
            return self::CACHE_TTL_UNAVAILABLE_SECONDS;
        }

        return $date === 'latest'
            ? self::CACHE_TTL_LATEST_SECONDS
            : self::CACHE_TTL_HISTORICAL_SECONDS;
    }

    /**
     * @return array<int, string>
     */
    private function candidateDates(string $date): array
    {
        if ($date === 'latest') {
            return [$date];
        }

        $parsedDate = Carbon::createFromFormat('Y-m-d', $date);

        return collect(range(0, self::HISTORICAL_LOOKBACK_DAYS))
            ->map(fn (int $days): string => $parsedDate->copy()->subDays($days)->toDateString())
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function rateUrls(string $currency, string $date): array
    {
        return [
            self::PRIMARY_URL."{$date}/v1/currencies/{$currency}.min.json",
            self::FALLBACK_URL."{$date}/currencies/{$currency}.min.json",
        ];
    }
}
