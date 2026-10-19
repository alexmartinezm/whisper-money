import { render, screen, within } from '@testing-library/react';
import type React from 'react';
import { describe, expect, it, vi } from 'vitest';
import MonthlySummaryPage from './index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: { post: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/MonthlySummaryController', () => ({
    index: () => ({ url: '/monthly-summary' }),
    store: () => ({ url: '/monthly-summary' }),
    analyse: (id: string) => ({ url: `/monthly-summary/${id}/analysis` }),
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div>{children}</div>
    ),
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({
        amountInCents,
        showSign,
    }: {
        amountInCents: number;
        showSign?: boolean;
    }) => (
        <span>
            {showSign && amountInCents > 0 ? '+' : ''}
            {amountInCents}
        </span>
    ),
}));

function goal(name: string, difference: number) {
    return {
        name,
        saved: 0,
        target: 0,
        difference,
        met: difference >= 0,
        streak: 0,
    };
}

function summary(monthlyGoals?: unknown) {
    return {
        id: 's1',
        period: '2026-09',
        complete: true,
        analysis: null,
        payload: {
            currency: 'EUR',
            has_history: false,
            net_worth: { current: 0, diff: 0, diff_percent: 0 },
            cashflow: {
                income: 0,
                expense: 0,
                net: 0,
                savings_rate: 0,
                expense_change_percent: 0,
            },
            categories: { total: 0, top: [] },
            budgets: { total: 0, met: 0 },
            todos: { uncategorised: { count: 0, amount: 0 } },
            ...(monthlyGoals === undefined
                ? {}
                : { monthly_goals: monthlyGoals }),
        },
    } as never;
}

describe('MonthlySummaryPage monthly goals', () => {
    it('lists each goal by its signed difference, the furthest below first', () => {
        render(
            <MonthlySummaryPage
                period="2026-09"
                canAnalyse={false}
                summary={summary({
                    total: 3,
                    met: 2,
                    saved: 0,
                    target: 0,
                    goals: [
                        goal('Emergency fund', 2000),
                        goal('Japan trip', -5000),
                        goal('Car', 0),
                    ],
                })}
            />,
        );

        const card = screen
            .getByText('Monthly savings goals')
            .closest('div.flex-col') as HTMLElement;

        expect(within(card).getByText('2 of 3 goals met')).toBeInTheDocument();

        const names = within(card)
            .getAllByText(/Emergency fund|Japan trip|Car/)
            .map((node) => node.textContent);

        expect(names).toEqual(['Japan trip', 'Car', 'Emergency fund']);
        expect(within(card).getByText('-5000')).toBeInTheDocument();
        expect(within(card).getByText('+2000')).toBeInTheDocument();
    });

    it('leaves monthly goals out of a summary frozen before them', () => {
        render(
            <MonthlySummaryPage
                period="2026-09"
                canAnalyse={false}
                summary={summary()}
            />,
        );

        expect(
            screen.queryByText('Monthly savings goals'),
        ).not.toBeInTheDocument();
    });

    it('leaves monthly goals out of a month without any', () => {
        render(
            <MonthlySummaryPage
                period="2026-09"
                canAnalyse={false}
                summary={summary(null)}
            />,
        );

        expect(
            screen.queryByText('Monthly savings goals'),
        ).not.toBeInTheDocument();
    });
});
