import { type RecurringCadence } from '@/types/recurring';

/**
 * How many times a cadence bills in an average month. Mirrors
 * `App\Enums\RecurringCadence::monthlyFactor()` so the client and the server
 * put the same number on the screen.
 */
const MONTHLY_FACTORS: Record<RecurringCadence, number> = {
    weekly: 52 / 12,
    biweekly: 26 / 12,
    monthly: 1,
    quarterly: 1 / 3,
    yearly: 1 / 12,
};

/**
 * Rescale an amount to a month so cadences can be compared and summed.
 */
export function monthlyEquivalent(
    amountInCents: number,
    cadence: RecurringCadence,
): number {
    return Math.round(amountInCents * MONTHLY_FACTORS[cadence]);
}

/**
 * Whole days from today until a date, negative once it is in the past. Both
 * sides are floored to midnight so "tomorrow" never reads as 0 because of the
 * time of day the page happened to be rendered.
 */
export function daysUntil(isoDate: string, today: Date = new Date()): number {
    const target = new Date(isoDate);
    const targetMidnight = Date.UTC(
        target.getUTCFullYear(),
        target.getUTCMonth(),
        target.getUTCDate(),
    );
    const todayMidnight = Date.UTC(
        today.getFullYear(),
        today.getMonth(),
        today.getDate(),
    );

    return Math.round((targetMidnight - todayMidnight) / 86_400_000);
}

/**
 * What the series charges now. `expected_amount` is the median of its whole
 * history, which still reads as the old price for as long as the old charges
 * outnumber the new ones.
 */
export function chargeAmount(series: {
    expected_amount: number;
    recent_amount: number | null;
}): number {
    return series.recent_amount ?? series.expected_amount;
}

/**
 * Whether the history is far enough from the current charge to be worth
 * showing. Mirrors the server's price-change thresholds: a tenth of the old
 * figure, and at least a unit of currency, so a few cents on a small charge
 * stays quiet.
 */
export function hasMovedPrice(series: {
    expected_amount: number;
    recent_amount: number | null;
}): boolean {
    if (series.recent_amount === null) return false;

    const previous = Math.abs(series.expected_amount);
    const change = Math.abs(Math.abs(series.recent_amount) - previous);

    return previous > 0 && change >= 100 && change / previous >= 0.1;
}
