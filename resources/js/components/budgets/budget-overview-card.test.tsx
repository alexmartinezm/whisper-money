import type { BudgetSummary } from '@/types/budget';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { BudgetOverviewCard } from './budget-overview-card';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{`amount:${amountInCents}`}</span>
    ),
}));

vi.mock('@/utils/i18n', () => ({
    __: (key: string) => key,
}));

const summary: BudgetSummary = {
    budgets_count: 3,
    total_allocated: 100_000,
    total_carried_over: 20_000,
    total_available: 120_000,
    total_spent: 129_147,
    total_remaining: -9_147,
    percentage_used: 107.6,
    status: 'over_limit',
    over_limit_count: 1,
    close_to_limit_count: 1,
    groups: [],
    catch_all: null,
};

describe('BudgetOverviewCard', () => {
    it('renders only the global budget summary', () => {
        render(
            <BudgetOverviewCard budgetSummary={summary} currencyCode="EUR" />,
        );

        expect(
            screen.getByRole('heading', { name: 'Budget overview' }),
        ).not.toBeNull();
        expect(screen.getByText('Over budget')).not.toBeNull();
        expect(screen.getByText('Consumed in budgets')).not.toBeNull();
        expect(screen.getByText('amount:129147')).not.toBeNull();
        expect(screen.getByText('amount:120000')).not.toBeNull();
        expect(screen.getByText('amount:-9147')).not.toBeNull();
        expect(
            screen.getByText('1 over limit · 1 close to limit'),
        ).not.toBeNull();

        expect(screen.queryByText('Specific budgets')).toBeNull();
        expect(screen.queryByText('Catch-all budget')).toBeNull();
        expect(
            screen.queryByText('Excluded from specific budget totals'),
        ).toBeNull();
    });

    it('communicates the aggregated status through the hero and progress bar', () => {
        render(
            <BudgetOverviewCard budgetSummary={summary} currencyCode="EUR" />,
        );

        expect(
            screen
                .getByTestId('budget-overview-hero')
                .getAttribute('data-status'),
        ).toBe('over_limit');
        expect(
            screen
                .getByRole('progressbar', { name: 'Overall budget progress' })
                .getAttribute('data-status'),
        ).toBe('over_limit');
        expect(
            screen
                .getByRole('progressbar', { name: 'Overall budget progress' })
                .getAttribute('aria-valuetext'),
        ).toBe('107.6%');
    });

    it('shows an on-track status when no budget is near its limit', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{
                    ...summary,
                    total_spent: 30_000,
                    total_remaining: 90_000,
                    percentage_used: 25,
                    status: 'on_track',
                    over_limit_count: 0,
                    close_to_limit_count: 0,
                }}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('On track')).not.toBeNull();
        expect(screen.queryByText('Over budget')).toBeNull();
    });
});
