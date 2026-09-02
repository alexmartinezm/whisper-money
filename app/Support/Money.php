<?php

namespace App\Support;

use NumberFormatter;

/**
 * Formats a minor-unit amount (cents) with its currency symbol, e.g. "€3.99".
 *
 * Centralizes the money formatting that was duplicated across the stats report
 * commands and the Discord Stripe listener. Currencies without a known symbol
 * fall back to the uppercased currency code plus a trailing space ("CHF 3.99").
 */
final class Money
{
    public static function format(int $cents, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($cents / 100, 2);
    }

    /**
     * The same amount as the reader's locale writes it: "3.520,00 €" in Spanish,
     * "€3,520.00" in English. Used where the figure is read by a person rather
     * than by us — the monthly analysis quotes these verbatim.
     *
     * Every amount in this app is stored at a fixed scale of two decimals, so
     * that is what is asked of the formatter. Adopting per-currency decimals
     * (upstream #889) makes this one of the places that has to learn the
     * currency's own scale.
     */
    public static function formatIn(int $cents, string $currency, string $locale): string
    {
        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);
        $formatter->setAttribute(NumberFormatter::MIN_FRACTION_DIGITS, 2);
        $formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, 2);

        $formatted = $formatter->formatCurrency($cents / 100, strtoupper($currency));

        // An unknown locale or currency leaves the failure on the formatter
        // rather than in the return value, so that is where it has to be read.
        if (intl_is_failure($formatter->getErrorCode())) {
            return self::format($cents, $currency);
        }

        return preg_replace('/\s/u', "\u{202F}", $formatted) ?? $formatted;
    }
}
