import { describe, expect, it } from 'vitest';
import { budgetPercentageUsed, budgetSeverity, type Budget } from './budget';

/**
 * The Planning index serialises each period with `spent_amount` and `status`
 * already computed server-side, and does NOT ship `budget_transactions`. A
 * severity that only knew how to sum those rows would read 0% for every budget
 * on the list, so the cards would all look fine and the attention ordering
 * would be flat. These pin the shape the server actually sends.
 */
function budgetWith(period: Record<string, unknown>): Budget {
    return { id: 'b1', name: 'Food', periods: [period] } as unknown as Budget;
}

describe('budgetSeverity against the props the Planning index sends', () => {
    it('reads the server-computed spend rather than a transaction list', () => {
        const budget = budgetWith({
            allocated_amount: 10000,
            carried_over_amount: 0,
            spent_amount: 9500,
            status: 'close_to_limit',
        });

        expect(budgetPercentageUsed(budget)).toBe(95);
        expect(budgetSeverity(budget)).toBe('near');
    });

    it('reports a breached budget as over', () => {
        expect(
            budgetSeverity(
                budgetWith({
                    allocated_amount: 10000,
                    carried_over_amount: 0,
                    spent_amount: 12000,
                    status: 'over_limit',
                }),
            ),
        ).toBe('over');
    });

    it('counts carried-over balance as part of what is available', () => {
        // 90 spent of 100 allocated is close to the limit, but with 100 carried
        // over there is 200 available and the budget is only 45% through it.
        const budget = budgetWith({
            allocated_amount: 10000,
            carried_over_amount: 10000,
            spent_amount: 9000,
        });

        expect(budgetPercentageUsed(budget)).toBe(45);
        expect(budgetSeverity(budget)).toBe('ok');
    });

    it('falls back to the transaction rows when no spend was precomputed', () => {
        const budget = budgetWith({
            allocated_amount: 10000,
            carried_over_amount: 0,
            budget_transactions: [{ amount: 6000 }, { amount: 3500 }],
        });

        expect(budgetPercentageUsed(budget)).toBe(95);
        expect(budgetSeverity(budget)).toBe('near');
    });
});
