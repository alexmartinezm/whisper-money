/**
 * Every money value is stored as an integer in hundredths of its currency's
 * unit (cents), whatever the currency: the server and every importer agree on
 * that scale.
 */
const MINOR_UNITS_PER_MAJOR = 100;

/**
 * A major-unit amount as the integer the server stores, e.g. 3.99 to 399. The
 * currency is part of the signature so callers say which money they mean; this
 * app stores every currency at the same scale.
 */
export function toMinorUnits(
    majorUnits: number,
    // eslint-disable-next-line @typescript-eslint/no-unused-vars -- same scale for every currency
    currencyCode: string,
): number {
    return Math.round(majorUnits * MINOR_UNITS_PER_MAJOR);
}

export function formatCurrency(
    valueInCents: number,
    currencyCode = 'USD',
    locale = 'en-US',
    minimumFractionDigits = 2,
    maximumFractionDigits = 2,
): string {
    const amount = valueInCents / 100;
    return new Intl.NumberFormat(locale, {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits,
        maximumFractionDigits,
        useGrouping: 'always',
    })
        .format(amount)
        .replace(/\s/g, '\u202F');
}

export function getCurrencySymbol(currencyCode: string): string {
    const symbols: Record<string, string> = {
        USD: '$',
        EUR: '€',
        GBP: '£',
        JPY: '¥',
        NZD: 'NZ$',
        DOP: 'RD$',
        NGN: '₦',
        SEK: 'kr',
        THB: '฿',
        SGD: 'S$',
    };
    return symbols[currencyCode] || currencyCode;
}
