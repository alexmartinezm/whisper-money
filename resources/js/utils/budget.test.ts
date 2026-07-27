import { describe, expect, it } from 'vitest';
import { getBudgetPeriodStats, sortBudgetsByAllocatedAmount } from './budget';

describe('sortBudgetsByAllocatedAmount', () => {
    it('orders budgets by their active period allocation from highest to lowest', () => {
        const budgets = [
            { name: 'Café', periods: [{ allocated_amount: 2500 }] },
            { name: 'Alimentación', periods: [{ allocated_amount: 80000 }] },
            { name: 'Sin período activo', periods: [] },
            { name: 'Empresa', periods: [{ allocated_amount: 36500 }] },
        ];

        expect(
            sortBudgetsByAllocatedAmount(budgets).map(({ name }) => name),
        ).toEqual(['Alimentación', 'Empresa', 'Café', 'Sin período activo']);
    });

    it('places budgets without an active period after budgets allocated zero', () => {
        const budgets = [
            { name: 'Sin período activo', periods: [] },
            { name: 'Presupuesto a cero', periods: [{ allocated_amount: 0 }] },
        ];

        expect(
            sortBudgetsByAllocatedAmount(budgets).map(({ name }) => name),
        ).toEqual(['Presupuesto a cero', 'Sin período activo']);
    });
});

describe('getBudgetPeriodStats', () => {
    it('includes carried-over money and the server-side spent total', () => {
        expect(
            getBudgetPeriodStats({
                allocated_amount: 100000,
                carried_over_amount: 20000,
                spent_amount: 30000,
            }),
        ).toEqual({
            totalAllocated: 100000,
            totalCarriedOver: 20000,
            totalAvailable: 120000,
            totalSpent: 30000,
            remaining: 90000,
            percentageUsed: 25,
            status: 'on_track',
        });
    });

    it('uses exact values at warning and limit thresholds', () => {
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 0,
                spent_amount: 8999,
            }).status,
        ).toBe('on_track');
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 0,
                spent_amount: 9000,
            }).status,
        ).toBe('close_to_limit');
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 5000,
                spent_amount: 13499,
            }).status,
        ).toBe('on_track');
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 5000,
                spent_amount: 13500,
            }).status,
        ).toBe('close_to_limit');
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 0,
                spent_amount: 9996,
            }).status,
        ).toBe('close_to_limit');
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 0,
                spent_amount: 10000,
            }).status,
        ).toBe('over_limit');
    });

    it('falls back to transaction rows when no aggregate is provided', () => {
        expect(
            getBudgetPeriodStats({
                allocated_amount: 10000,
                carried_over_amount: 0,
                budget_transactions: [{ amount: 2500 }, { amount: -500 }],
            }),
        ).toMatchObject({
            totalSpent: 2000,
            remaining: 8000,
            percentageUsed: 20,
        });
    });
});
