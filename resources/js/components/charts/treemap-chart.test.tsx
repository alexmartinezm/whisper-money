import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TreemapChart, truncate } from './treemap-chart';

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
}));

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en',
}));

vi.mock('@/hooks/use-chart-color-scheme', () => ({
    useChartColors: () => ({
        categoryBarColor: () => '#123456',
    }),
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit: vi.fn() },
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: () => ({ url: '/transactions' }),
}));

describe('truncate', () => {
    it('leaves short names untouched', () => {
        expect(truncate('Rent', 200)).toBe('Rent');
    });

    it('shortens long names to fit the box and appends an ellipsis', () => {
        const result = truncate('A very long category name', 60);

        expect(result.endsWith('…')).toBe(true);
        expect(result.length).toBeLessThan('A very long category name'.length);
    });
});

describe('TreemapChart', () => {
    it('shows an empty state when there is nothing to plot', () => {
        render(
            <TreemapChart
                categories={[]}
                total={0}
                mode="expense"
                currency="EUR"
            />,
        );

        expect(
            screen.getByText('No cashflow data for this period'),
        ).toBeInTheDocument();
    });
});
