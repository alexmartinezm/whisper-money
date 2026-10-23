import {
    PrivacyModeProvider,
    PrivacyRevealScope,
} from '@/contexts/privacy-mode-context';
import { type NetWorthProjection } from '@/types/net-worth';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { NetWorthProjectionCard } from './net-worth-projection';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

const projection: NetWorthProjection = {
    currency_code: 'EUR',
    months: 12,
    today: 1_000_000,
    committed: 1_250_000,
    estimated: 1_180_000,
    points: [
        {
            date: '2026-10-01',
            actual: 1_000_000,
            committed: 1_000_000,
            estimated: 1_000_000,
        },
        {
            date: '2027-10-01',
            actual: null,
            committed: 1_250_000,
            estimated: 1_180_000,
        },
    ],
    drivers: {
        debt_repaid: 120_000,
        property_growth: 0,
        recurring_in: 300_000,
        recurring_out: -170_000,
        contributions: 0,
        everyday_spending: -70_000,
    },
    assumptions: {
        investments_flat: true,
        revalued_accounts: [],
        months_observed: 6,
        recurring_income: true,
    },
};

function renderCard() {
    return render(
        <PrivacyModeProvider>
            <PrivacyRevealScope>
                <NetWorthProjectionCard projection={projection} />
            </PrivacyRevealScope>
        </PrivacyModeProvider>,
    );
}

function changeOverThePeriod() {
    return screen.getByText(/over the period/).textContent ?? '';
}

describe('NetWorthProjectionCard', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('shows the figures it formats itself while privacy mode is off', () => {
        renderCard();

        expect(changeOverThePeriod()).toMatch(/\d/);
    });

    it('masks the figures it formats itself while privacy mode is on', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');
        const { container } = renderCard();

        expect(changeOverThePeriod()).not.toMatch(/\d/);
        expect(container.textContent).not.toMatch(/€\s?\d/);
    });

    it('shows them again once the block is revealed', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');
        renderCard();

        fireEvent.click(
            screen.getByRole('button', { name: 'Show amounts in this block' }),
        );

        expect(changeOverThePeriod()).toMatch(/\d/);
    });
});
