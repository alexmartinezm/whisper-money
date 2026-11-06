<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class ExchangeRateService
{
    /** How long one {@see self::convertManyOrNull()} keeps asking the provider. */
    private const PROVIDER_BUDGET_SECONDS = 5;

    /** @var array<string, array<string, float>> */
    private array $ratesCache = [];

    /** @var array<string, true> */
    private array $databaseMissCache = [];

    public function __construct(private CurrencyConversionService $currencyApi) {}

    /**
     * Convert an amount in cents from one currency to another on a given date.
     *
     * Uses DB-cached exchange rates, falling back to the external API on cache miss.
     *
     * @param  string  $source  Source currency code (e.g., "EUR")
     * @param  string  $target  Target currency code (e.g., "USD")
     * @param  int  $amountInCents  Amount in the source currency's smallest unit
     * @param  string  $date  Date string (YYYY-MM-DD)
     */
    public function convert(string $source, string $target, int $amountInCents, string $date): int
    {
        $source = strtolower($source);
        $target = strtolower($target);

        if ($source === $target || $amountInCents === 0) {
            return $amountInCents;
        }

        $rates = $this->getRates($target, $date);

        if (! isset($rates[$source]) || $rates[$source] == 0) {
            Log::warning('Exchange rate not found, returning unconverted amount', [
                'source' => $source,
                'target' => $target,
                'date' => $date,
            ]);

            return $amountInCents;
        }

        return (int) round($amountInCents / $rates[$source]);
    }

    /**
     * The same conversion as {@see convert()}, but null when that day holds no
     * rate for the pair.
     *
     * `convert()` answers a missing rate with the amount unconverted, which is
     * the right call for a total that must still add up. Callers that either
     * show the figure or write it to a money column need the difference: an
     * unconverted amount printed as a conversion is a lie, and stored as one it
     * is a wrong balance.
     */
    public function convertOrNull(string $source, string $target, int $amountInCents, string $date): ?int
    {
        if (strcasecmp($source, $target) === 0) {
            return $amountInCents;
        }

        $rates = $this->getRates(strtolower($target), $date);
        $key = strtolower($source);

        if (! isset($rates[$key]) || $rates[$key] == 0) {
            return null;
        }

        return $this->convert($source, $target, $amountInCents, $date);
    }

    /**
     * {@see self::convertOrNull()} over many amounts, in their order, for a
     * response someone is waiting on.
     *
     * Rates the table holds are read in one query and always answer, so a
     * provider failure never blanks a day that has one. The provider is asked
     * one date at a time for the rest, and stops being asked at its first
     * failure or once the budget is spent: the remaining dates answer null,
     * and a later request can pick them up. An outage therefore costs one
     * bounded attempt ({@see CurrencyConversionService::ratesOrNullOnFailure()}),
     * not one per date, and leaves nothing behind that a plain {@see self::getRates()}
     * would read.
     *
     * @param  list<array{from: string, amount: int, date: string}>  $items
     * @return list<?int>
     */
    public function convertManyOrNull(array $items, string $target): array
    {
        $target = strtolower($target);

        // Only amounts in another currency need a rate, so a reader who keeps
        // everything in one currency never reads the rates table here.
        $this->preloadRates($target, array_column(array_filter(
            $items,
            fn (array $item): bool => strcasecmp($item['from'], $target) !== 0,
        ), 'date'));

        $deadline = now()->addSeconds(self::PROVIDER_BUDGET_SECONDS);
        $mayAskProvider = true;

        return array_map(function (array $item) use ($target, $deadline, &$mayAskProvider): ?int {
            if (strcasecmp($item['from'], $target) === 0 || $this->holdsRates($target, $item['date'])) {
                return $this->convertOrNull($item['from'], $target, $item['amount'], $item['date']);
            }

            if (! $mayAskProvider) {
                return null;
            }

            $rates = $this->askProviderOnce($target, $this->normalizeDate($item['date']));
            $mayAskProvider = $rates !== null && now()->lt($deadline);

            return $rates ? $this->convertOrNull($item['from'], $target, $item['amount'], $item['date']) : null;
        }, $items);
    }

    /**
     * Rates found are kept as {@see self::getRates()} keeps them. An empty or
     * failed answer is not: this instance's next plain lookup still gets to
     * ask for itself.
     *
     * @return array<string, float>|null as {@see CurrencyConversionService::ratesOrNullOnFailure()}
     */
    private function askProviderOnce(string $baseCurrency, string $date): ?array
    {
        $rates = $this->currencyApi->ratesOrNullOnFailure($baseCurrency, $date);

        if ($rates) {
            $this->storeRates($baseCurrency, $date, $rates);
            $this->ratesCache[$this->cacheKey($baseCurrency, $date)] = $rates;
        }

        return $rates;
    }

    /**
     * Get all exchange rates for a base currency on a given date.
     *
     * Checks the DB cache first, then fetches from the external API
     * and stores the result for future lookups.
     *
     * @return array<string, float>
     */
    public function getRates(string $baseCurrency, string $date): array
    {
        $baseCurrency = strtolower($baseCurrency);
        $date = $this->normalizeDate($date);
        $cacheKey = $this->cacheKey($baseCurrency, $date);

        if (array_key_exists($cacheKey, $this->ratesCache)) {
            return $this->ratesCache[$cacheKey];
        }

        if (! isset($this->databaseMissCache[$cacheKey])) {
            $cached = ExchangeRate::query()
                ->where('base_currency', $baseCurrency)
                ->where('date', $date)
                ->first();

            if ($cached) {
                return $this->ratesCache[$cacheKey] = $cached->rates;
            }
        }

        $rates = $this->currencyApi->getRatesForCurrency($baseCurrency, $date);

        if (! empty($rates)) {
            $this->storeRates($baseCurrency, $date, $rates);
        }

        return $this->ratesCache[$cacheKey] = $rates;
    }

    /**
     * @param  array<string, float>  $rates
     */
    private function storeRates(string $baseCurrency, string $date, array $rates): void
    {
        ExchangeRate::query()->upsert([
            [
                'base_currency' => $baseCurrency,
                'date' => $date,
                'rates' => json_encode($rates, JSON_THROW_ON_ERROR),
            ],
        ], uniqueBy: ['base_currency', 'date'], update: ['rates']);
    }

    /**
     * Preload cached exchange rates for many dates in one query.
     *
     * @param  iterable<int, string>  $dates
     */
    public function preloadRates(string $baseCurrency, iterable $dates): void
    {
        $baseCurrency = strtolower($baseCurrency);
        $dates = collect($dates)
            ->map(fn (string $date): string => $this->normalizeDate($date))
            ->unique()
            ->reject(fn (string $date): bool => array_key_exists($this->cacheKey($baseCurrency, $date), $this->ratesCache))
            ->values();

        if ($dates->isEmpty()) {
            return;
        }

        ExchangeRate::query()
            ->where('base_currency', $baseCurrency)
            ->whereIn('date', $dates->all())
            ->get(['base_currency', 'date', 'rates'])
            ->each(function (ExchangeRate $exchangeRate): void {
                $date = $exchangeRate->date->toDateString();
                $this->ratesCache[$this->cacheKey($exchangeRate->base_currency, $date)] = $exchangeRate->rates;
            });

        $dates
            ->reject(fn (string $date): bool => array_key_exists($this->cacheKey($baseCurrency, $date), $this->ratesCache))
            ->each(function (string $date) use ($baseCurrency): void {
                $this->databaseMissCache[$this->cacheKey($baseCurrency, $date)] = true;
            });
    }

    private function holdsRates(string $baseCurrency, string $date): bool
    {
        return array_key_exists($this->cacheKey($baseCurrency, $this->normalizeDate($date)), $this->ratesCache);
    }

    private function normalizeDate(string $date): string
    {
        $today = Carbon::today()->toDateString();

        return $date > $today ? $today : $date;
    }

    private function cacheKey(string $baseCurrency, string $date): string
    {
        return strtolower($baseCurrency).'|'.$date;
    }
}
