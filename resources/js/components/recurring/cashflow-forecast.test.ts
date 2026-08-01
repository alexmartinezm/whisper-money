import { type CashflowForecast } from '@/types/recurring';
import { describe, expect, it } from 'vitest';
import { accountsNote, dipWorthWarningAbout } from './cashflow-forecast';

function forecast(overrides: Partial<CashflowForecast>): CashflowForecast {
    return {
        currency_code: 'EUR',
        days: 30,
        starting_balance: 0,
        ending_balance: 0,
        expected_in: 0,
        expected_out: 0,
        lowest: { date: '2026-08-01', balance: 0 },
        accounts: [],
        excluded_accounts: 0,
        spending: null,
        other_currencies: [],
        occurrences: [],
        later: [],
        ...overrides,
    };
}

describe('accountsNote', () => {
    it('names the accounts behind the figure', () => {
        expect(
            accountsNote(
                forecast({
                    accounts: [
                        { id: 'a', name: 'BBVA' },
                        { id: 'b', name: 'Ahorro' },
                    ],
                }),
            ),
        ).toBe('Counting BBVA, Ahorro.');
    });

    // Both leave today at zero, but only one is a reason to hunt for a bug.
    it('blames the setting when every spendable account is excluded', () => {
        expect(accountsNote(forecast({ excluded_accounts: 2 }))).toBe(
            'Your spendable accounts are all excluded from net worth, so today reads as zero.',
        );
    });

    it('says there is nothing to count when there really is nothing', () => {
        expect(accountsNote(forecast({}))).toBe(
            'No account holds spendable cash, so today reads as zero.',
        );
    });
});

describe('dipWorthWarningAbout', () => {
    const account = [{ id: 'a', name: 'BBVA' }];

    it('prefers the estimate over the exact walk', () => {
        expect(
            dipWorthWarningAbout(
                forecast({
                    accounts: account,
                    lowest: { date: '2026-08-27', balance: 20562 },
                    spending: {
                        monthly: -57160,
                        months_observed: 3,
                        ending_balance: 228400,
                        lowest: { date: '2026-08-29', balance: -32787 },
                    },
                }),
            ),
        ).toEqual({ date: '2026-08-29', balance: -32787 });
    });

    it('falls back to the exact walk when there is no estimate', () => {
        expect(
            dipWorthWarningAbout(
                forecast({
                    accounts: account,
                    lowest: { date: '2026-08-27', balance: -8000 },
                }),
            ),
        ).toEqual({ date: '2026-08-27', balance: -8000 });
    });

    it('stays quiet when the projection never dips', () => {
        expect(
            dipWorthWarningAbout(
                forecast({
                    accounts: account,
                    lowest: { date: '2026-08-27', balance: 20562 },
                }),
            ),
        ).toBeNull();
    });

    // Zero is where the walk starts when nothing is counted, so the first
    // charge would otherwise raise an alarm about a balance we do not have.
    it('stays quiet when no account is counted at all', () => {
        expect(
            dipWorthWarningAbout(
                forecast({
                    excluded_accounts: 1,
                    lowest: { date: '2026-08-27', balance: -31438 },
                }),
            ),
        ).toBeNull();
    });
});
