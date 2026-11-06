import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import type { Category } from '@/types/category';
import type { DecryptedTransaction } from '@/types/transaction';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { CategoryCell } from './category-cell';

vi.mock('@/hooks/use-locale', () => ({
    useLocale: () => 'en-US',
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => false,
}));

vi.mock('@inertiajs/react', async (importOriginal) => ({
    ...(await importOriginal<typeof import('@inertiajs/react')>()),
    usePage: () => ({
        props: {
            auth: { user: { id: 'user-1' } },
            subscriptionsEnabled: false,
            aiCategorizationUpsellRate: 0,
        },
    }),
}));

describe('CategoryCell with a stored currency Intl refuses', () => {
    it('still draws the split row and names its amounts', () => {
        const category = {
            id: 'category-1',
            name: 'Food',
        } as Category;

        const transaction = {
            id: 'transaction-1',
            amount: -1000,
            currency_code: 'N/A',
            category_id: null,
            splits: [
                { id: 'a', category_id: 'category-1', category, amount: -600 },
                { id: 'b', category_id: 'category-1', category, amount: -400 },
            ],
        } as unknown as DecryptedTransaction;

        render(
            <PrivacyModeProvider>
                <CategoryCell
                    transaction={transaction}
                    categories={[category]}
                    accounts={[]}
                    banks={[]}
                    onUpdate={() => {}}
                    onEdit={() => {}}
                />
            </PrivacyModeProvider>,
        );

        expect(
            screen.getByRole('button', { name: /6\.00.*N\/A/ }),
        ).toBeInTheDocument();
    });
});
