import type { BudgetSummary, BudgetSummaryGroup } from '@/types/budget';
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

const monthlyGroup: BudgetSummaryGroup = {
    period_type: 'monthly',
    budgets_count: 2,
    total_allocated: 100_000,
    total_carried_over: 20_000,
    total_available: 120_000,
    total_spent: 30_000,
    total_remaining: 90_000,
    percentage_used: 25,
    status: 'on_track',
    over_limit_count: 0,
    close_to_limit_count: 1,
};

const yearlyGroup: BudgetSummaryGroup = {
    period_type: 'yearly',
    budgets_count: 1,
    total_allocated: 1_200_000,
    total_carried_over: 0,
    total_available: 1_200_000,
    total_spent: 1_350_000,
    total_remaining: -150_000,
    percentage_used: 112.5,
    status: 'over_limit',
    over_limit_count: 1,
    close_to_limit_count: 0,
};

const summary: BudgetSummary = {
    budgets_count: 3,
    total_allocated: 1_300_000,
    total_carried_over: 20_000,
    total_available: 1_320_000,
    total_spent: 1_380_000,
    total_remaining: -60_000,
    percentage_used: 104.5,
    status: 'over_limit',
    over_limit_count: 1,
    close_to_limit_count: 1,
    groups: [monthlyGroup, yearlyGroup],
    catch_all: null,
};

describe('BudgetOverviewCard', () => {
    it('uses monthly metrics and shows a secondary yearly remaining line', () => {
        render(
            <BudgetOverviewCard budgetSummary={summary} currencyCode="EUR" />,
        );

        expect(
            screen.getByRole('heading', { name: 'Monthly budget overview' }),
        ).not.toBeNull();
        expect(screen.getByText('Monthly remaining')).not.toBeNull();
        expect(screen.getByText('Consumed in monthly budgets')).not.toBeNull();
        expect(screen.getByText('amount:30000')).not.toBeNull();
        expect(screen.getByText('amount:120000')).not.toBeNull();
        expect(screen.getByText('amount:90000')).not.toBeNull();
        expect(screen.getByText('1 close to limit')).not.toBeNull();
        expect(screen.getByText('Yearly budget exceeded by')).not.toBeNull();
        expect(screen.getByText('amount:150000')).not.toBeNull();

        expect(screen.queryByText('Specific budgets')).toBeNull();
        expect(screen.queryByText('Catch-all budget')).toBeNull();
        expect(
            screen.queryByText('Excluded from specific budget totals'),
        ).toBeNull();
    });

    it('shows positive yearly remaining without an over-limit sign', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{
                    ...summary,
                    groups: [
                        monthlyGroup,
                        {
                            ...yearlyGroup,
                            total_spent: 900_000,
                            total_remaining: 300_000,
                            percentage_used: 75,
                            status: 'on_track',
                            over_limit_count: 0,
                        },
                    ],
                }}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('Yearly remaining')).not.toBeNull();
        expect(screen.getByText('amount:300000')).not.toBeNull();
        expect(screen.queryByText('Yearly budget exceeded by')).toBeNull();
    });

    it('keeps yearly spending from changing monthly hero status metrics', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{
                    ...summary,
                    groups: [
                        monthlyGroup,
                        {
                            ...yearlyGroup,
                            total_spent: 2_000_000,
                            total_remaining: -800_000,
                            percentage_used: 166.7,
                        },
                    ],
                }}
                currencyCode="EUR"
            />,
        );

        expect(
            screen
                .getByTestId('budget-overview-hero')
                .getAttribute('data-status'),
        ).toBe('on_track');
        expect(
            screen
                .getByRole('progressbar', {
                    name: 'Overall monthly budget progress',
                })
                .getAttribute('data-status'),
        ).toBe('on_track');
        expect(
            screen
                .getByRole('progressbar', {
                    name: 'Overall monthly budget progress',
                })
                .getAttribute('aria-valuetext'),
        ).toBe('25.0%');
        expect(screen.queryByText('Monthly over budget')).toBeNull();
        expect(screen.getByText('Yearly budget exceeded by')).not.toBeNull();
        expect(screen.getByText('amount:800000')).not.toBeNull();
    });

    it('hides the yearly line when there is no active yearly group', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{ ...summary, groups: [monthlyGroup] }}
                currencyCode="EUR"
            />,
        );

        expect(screen.queryByTestId('budget-overview-yearly')).toBeNull();
    });

    it('shows an explicit monthly empty state while keeping yearly remaining visible', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{
                    ...summary,
                    groups: [yearlyGroup],
                }}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('No active monthly budgets')).not.toBeNull();
        expect(screen.queryByTestId('budget-overview-hero')).toBeNull();
        expect(screen.queryByRole('progressbar')).toBeNull();
        expect(screen.getByText('Yearly budget exceeded by')).not.toBeNull();
        expect(screen.getByText('amount:150000')).not.toBeNull();
    });

    it('communicates the monthly status through the hero and progress bar', () => {
        render(
            <BudgetOverviewCard budgetSummary={summary} currencyCode="EUR" />,
        );

        expect(
            screen
                .getByTestId('budget-overview-hero')
                .getAttribute('data-status'),
        ).toBe('on_track');
        expect(
            screen
                .getByRole('progressbar', {
                    name: 'Overall monthly budget progress',
                })
                .getAttribute('data-status'),
        ).toBe('on_track');
        expect(
            screen
                .getByRole('progressbar', {
                    name: 'Overall monthly budget progress',
                })
                .getAttribute('aria-valuetext'),
        ).toBe('25.0%');
    });

    it('shows an on-track status when no monthly budget is near its limit', () => {
        render(
            <BudgetOverviewCard
                budgetSummary={{
                    ...summary,
                    groups: [
                        {
                            ...monthlyGroup,
                            close_to_limit_count: 0,
                        },
                        yearlyGroup,
                    ],
                }}
                currencyCode="EUR"
            />,
        );

        expect(screen.getByText('On track')).not.toBeNull();
        expect(screen.queryByText('Monthly over budget')).toBeNull();
    });
});
