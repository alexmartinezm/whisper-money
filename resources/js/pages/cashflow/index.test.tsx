import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CashflowPage from './index';

const pageProps = vi.hoisted(() => ({
    current: { features: { cashflowAvailableFree: false } },
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
    usePage: () => ({
        props: {
            auth: { user: { currency_code: 'EUR' } },
            period: '2026-10',
            periodType: 'month',
            ...pageProps.current,
        },
    }),
}));

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/layouts/app/app-sidebar-layout', () => ({
    default: ({ children }: { children: ReactNode }) => <div>{children}</div>,
}));

vi.mock('@/components/charts', () => ({
    CashflowTrendChart: () => null,
    SankeyChart: () => null,
}));

// The card fetches its own figures; the page only decides where it goes.
vi.mock('@/components/cashflow/monthly-savings-cashflow-card', () => ({
    MonthlySavingsCashflowCard: () => <div>Monthly savings card</div>,
}));

vi.mock('@/components/cashflow/breakdown-card', () => ({
    BreakdownCard: () => null,
}));

// Stands in for the period type switcher the page passes its setter to.
vi.mock('@/components/cashflow/period-navigation', () => ({
    PeriodNavigation: ({
        onPeriodTypeChange,
    }: {
        onPeriodTypeChange: (periodType: 'quarter' | 'year') => void;
    }) => (
        <>
            <button onClick={() => onPeriodTypeChange('quarter')}>
                Quarter view
            </button>
            <button onClick={() => onPeriodTypeChange('year')}>
                Year view
            </button>
        </>
    ),
    formatPeriodLabel: () => 'October 2026',
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

vi.mock('@/hooks/use-period-url-sync', () => ({
    usePeriodUrlSync: () => undefined,
}));

vi.mock('@/hooks/use-cashflow-data', () => {
    const current = {
        income: 200000,
        expense: 132000,
        net: 68000,
        savings_rate: 0,
        savings: 25000,
        investments: 25000,
        fixed_expense: 94000,
        variable_expense: 38000,
        reserved: 0,
        budgets: [],
    };

    return {
        useCashflowData: () => ({
            summary: { current, previous: current },
            sankey: {},
            trend: [],
            incomeBreakdown: {},
            expenseBreakdown: {},
            isLoading: false,
            hasError: false,
            refetch: () => undefined,
        }),
    };
});

describe('CashflowPage', () => {
    beforeEach(() => {
        pageProps.current = { features: { cashflowAvailableFree: false } };
    });

    it('leaves the free highlight out while the flag is off', () => {
        render(<CashflowPage />);

        expect(screen.queryByText('Income distribution')).toBeNull();
        expect(screen.queryByText('Free (cashflow)')).toBeNull();
        expect(screen.queryByText('Expenses (cashflow)')).toBeNull();
        expect(
            screen.queryByRole('button', { name: 'View details' }),
        ).toBeNull();
    });

    it('shows the free highlight but no income distribution with the flag on', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);

        expect(screen.getAllByText('Free (cashflow)').length).toBeGreaterThan(
            0,
        );
        expect(screen.queryByText('Income distribution')).toBeNull();
    });

    it.each([false, true])(
        'keeps the month in review link and the monthly savings card with the flag %s',
        (cashflowAvailableFree) => {
            pageProps.current = { features: { cashflowAvailableFree } };

            render(<CashflowPage />);

            expect(screen.getByText('Month in review')).toBeTruthy();
            expect(screen.getByText('Monthly savings card')).toBeTruthy();
        },
    );

    it.each(['Quarter view', 'Year view'])(
        'leaves the monthly savings card out after switching to the %s',
        (view) => {
            render(<CashflowPage />);

            expect(screen.getByText('Monthly savings card')).toBeTruthy();

            fireEvent.click(screen.getByRole('button', { name: view }));

            expect(screen.queryByText('Monthly savings card')).toBeNull();
            expect(screen.getByText('Month in review')).toBeTruthy();
        },
    );

    it('shows the expenses card and opens its details with the flag on', () => {
        pageProps.current = { features: { cashflowAvailableFree: true } };

        render(<CashflowPage />);

        expect(screen.getByText('Expenses (cashflow)')).toBeTruthy();
        expect(
            screen.queryByText('Where your money goes · October 2026'),
        ).toBeNull();

        fireEvent.click(screen.getByRole('button', { name: 'View details' }));

        expect(
            screen.getByText('Where your money goes · October 2026'),
        ).toBeTruthy();
    });
});
