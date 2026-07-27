import { describe, expect, it } from 'vitest';
import { sortBudgetsByAllocatedAmount } from './budget';

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
