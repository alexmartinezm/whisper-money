import type { Budget, BudgetSummary } from '@/types/budget';
import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { BudgetOverviewCard } from './budget-overview-card';

vi.mock('@/actions/App/Http/Controllers/BudgetController', () => ({
    show: ({ budget }: { budget: string }) => ({ url: `/budgets/${budget}` }),
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{`amount:${amountInCents}`}</span>
    ),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/utils/i18n', () => ({
    __: (key: string) => key,
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: ReactNode }) => (
        <a href={href}>{children}</a>
    ),
}));

function makeBudget({
    id,
    name,
    isCatchAll = false,
    allocatedAmount,
    carriedOverAmount,
    spentAmount,
    periodType = 'monthly',
}: {
    id: string;
    name: string;
    isCatchAll?: boolean;
    allocatedAmount: number;
    carriedOverAmount: number;
    spentAmount: number;
    periodType?: Budget['period_type'];
}): Budget {
    return {
        id,
        user_id: 'user-id',
        name,
        period_type: periodType,
        period_start_day: 1,
        categories: [],
        labels: [],
        rollover_type: 'carry_over',
        is_catch_all: isCatchAll,
        notify_on_new_transaction: false,
        notify_on_close_to_limit: false,
        notify_on_over_limit: false,
        created_at: '2026-07-01T00:00:00Z',
        updated_at: '2026-07-01T00:00:00Z',
        deleted_at: null,
        periods: [
            {
                id: `${id}-period`,
                budget_id: id,
                start_date: '2026-07-01',
                end_date: '2026-07-31',
                allocated_amount: allocatedAmount,
                carried_over_amount: carriedOverAmount,
                spent_amount: spentAmount,
                processing_historical: false,
                created_at: '2026-07-01T00:00:00Z',
                updated_at: '2026-07-01T00:00:00Z',
            },
        ],
    };
}

const summary: BudgetSummary = {
    budgets_count: 1,
    total_allocated: 100_000,
    total_carried_over: 20_000,
    total_available: 120_000,
    total_spent: 30_000,
    total_remaining: 90_000,
    percentage_used: 25,
    status: 'on_track',
    over_limit_count: 0,
    close_to_limit_count: 0,
    groups: [
        {
            period_type: 'monthly',
            budgets_count: 1,
            total_allocated: 100_000,
            total_carried_over: 20_000,
            total_available: 120_000,
            total_spent: 30_000,
            total_remaining: 90_000,
            percentage_used: 25,
            status: 'on_track',
            over_limit_count: 0,
            close_to_limit_count: 0,
        },
    ],
    catch_all: {
        budgets_count: 1,
        total_allocated: 50_000,
        total_carried_over: 0,
        total_available: 50_000,
        total_spent: 10_000,
        total_remaining: 40_000,
        percentage_used: 20,
        status: 'on_track',
        over_limit_count: 0,
        close_to_limit_count: 0,
    },
};

describe('BudgetOverviewCard', () => {
    it('renders specific and catch-all budgets separately with detail links', () => {
        render(
            <BudgetOverviewCard
                budgets={[
                    makeBudget({
                        id: 'specific-budget',
                        name: 'Groceries',
                        allocatedAmount: 100_000,
                        carriedOverAmount: 20_000,
                        spentAmount: 30_000,
                    }),
                    makeBudget({
                        id: 'catch-all-budget',
                        name: 'Other expenses',
                        isCatchAll: true,
                        allocatedAmount: 50_000,
                        carriedOverAmount: 0,
                        spentAmount: 10_000,
                    }),
                ]}
                budgetSummary={summary}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('Consumed in budgets')).not.toBeNull();
        expect(screen.getAllByText('Specific budgets').length).toBeGreaterThan(
            0,
        );
        expect(screen.getByText('Catch-all budget')).not.toBeNull();
        expect(
            screen.getByText('Excluded from specific budget totals'),
        ).not.toBeNull();
        expect(
            screen
                .getByRole('link', { name: 'Groceries' })
                .getAttribute('href'),
        ).toBe('/budgets/specific-budget');
        expect(
            screen
                .getByRole('link', { name: 'Other expenses' })
                .getAttribute('href'),
        ).toBe('/budgets/catch-all-budget');
        expect(screen.getAllByText('amount:120000').length).toBeGreaterThan(0);
        expect(screen.getAllByText('amount:50000').length).toBeGreaterThan(0);
        expect(
            screen
                .getByRole('progressbar', {
                    name: 'Budget progress: Groceries',
                })
                .getAttribute('aria-valuetext'),
        ).toBe('25.0%');
    });

    it('scopes the header status to specific budgets when catch-all is over limit', () => {
        const catchAllOverLimit: BudgetSummary = {
            ...summary,
            catch_all: {
                ...summary.catch_all!,
                total_spent: 60_000,
                total_remaining: -10_000,
                percentage_used: 120,
                status: 'over_limit',
                over_limit_count: 1,
            },
        };

        render(
            <BudgetOverviewCard
                budgets={[
                    makeBudget({
                        id: 'specific-budget',
                        name: 'Groceries',
                        allocatedAmount: 100_000,
                        carriedOverAmount: 20_000,
                        spentAmount: 30_000,
                    }),
                    makeBudget({
                        id: 'catch-all-budget',
                        name: 'Other expenses',
                        isCatchAll: true,
                        allocatedAmount: 50_000,
                        carriedOverAmount: 0,
                        spentAmount: 60_000,
                    }),
                ]}
                budgetSummary={catchAllOverLimit}
                currencyCode="EUR"
            />,
        );

        const headerStatus =
            screen.getAllByText('Specific budgets')[0]?.parentElement;
        expect(headerStatus?.textContent).toContain('On track');
    });

    it('groups mixed-cadence budgets under their matching summaries', () => {
        const monthlyGroup = summary.groups[0]!;
        const mixedSummary: BudgetSummary = {
            ...summary,
            budgets_count: 2,
            total_allocated: 140_000,
            total_available: 160_000,
            total_spent: 40_000,
            total_remaining: 120_000,
            groups: [
                monthlyGroup,
                {
                    period_type: 'weekly',
                    budgets_count: 1,
                    total_allocated: 40_000,
                    total_carried_over: 0,
                    total_available: 40_000,
                    total_spent: 10_000,
                    total_remaining: 30_000,
                    percentage_used: 25,
                    status: 'on_track',
                    over_limit_count: 0,
                    close_to_limit_count: 0,
                },
            ],
            catch_all: null,
        };

        render(
            <BudgetOverviewCard
                budgets={[
                    makeBudget({
                        id: 'monthly-budget',
                        name: 'Groceries',
                        allocatedAmount: 100_000,
                        carriedOverAmount: 20_000,
                        spentAmount: 30_000,
                    }),
                    makeBudget({
                        id: 'weekly-budget',
                        name: 'Transport',
                        allocatedAmount: 40_000,
                        carriedOverAmount: 0,
                        spentAmount: 10_000,
                        periodType: 'weekly',
                    }),
                ]}
                budgetSummary={mixedSummary}
                currencyCode="EUR"
            />,
        );

        const monthlySection = screen
            .getByRole('heading', { name: 'Monthly' })
            .closest('section');
        const weeklySection = screen
            .getByRole('heading', { name: 'Weekly' })
            .closest('section');

        expect(monthlySection?.textContent).toContain('Groceries');
        expect(monthlySection?.textContent).not.toContain('Transport');
        expect(weeklySection?.textContent).toContain('Transport');
        expect(weeklySection?.textContent).not.toContain('Groceries');
    });
});
