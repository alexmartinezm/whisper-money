import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { PlanningPeriodAllocation } from './planning-period-allocation';

const { patch } = vi.hoisted(() => ({ patch: vi.fn() }));

vi.mock('@inertiajs/react', () => ({ router: { patch } }));
vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    updatePeriod: () => ({ url: '/budgets/budget-1/periods/period-1' }),
}));
vi.mock('@/components/ui/amount-input', () => ({
    AmountInput: ({ onChange }: { onChange: (value: number) => void }) => (
        <input
            aria-label="Allocated Amount"
            onChange={(event) => onChange(Number(event.target.value))}
        />
    ),
}));

describe('PlanningPeriodAllocation', () => {
    it('submits only the viewed planning period allocation', () => {
        render(
            <PlanningPeriodAllocation
                budgetId="budget-1"
                periodId="period-1"
                allocatedAmount={45000}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('Allocated Amount')).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Allocated Amount'), {
            target: { value: '25000' },
        });
        fireEvent.submit(screen.getByRole('form'));

        expect(patch).toHaveBeenCalledWith(
            '/budgets/budget-1/periods/period-1',
            { allocated_amount: 25000 },
            expect.objectContaining({ onFinish: expect.any(Function) }),
        );
    });
});
