import {
    type DecryptedTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { TransactionActionsMenu } from './transaction-actions-menu';

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    categorize: { url: () => '/transactions/categorize' },
}));

let isMobile = false;

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => isMobile,
}));

const reEvaluateTransactionRules = vi.fn();

vi.mock('@/lib/transaction-re-evaluation', () => ({
    reEvaluateTransactionRules: () => reEvaluateTransactionRules(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, ...props }: React.ComponentProps<'a'>) => (
        <a {...props}>{children}</a>
    ),
}));

vi.mock('./add-transaction-button', () => ({
    AddTransactionButton: ({
        hasTransactionalAccounts,
        onSaved,
    }: {
        hasTransactionalAccounts: boolean;
        onSaved?: () => void;
    }) => (
        <button
            aria-label="Add transaction"
            aria-disabled={!hasTransactionalAccounts}
            onClick={onSaved}
        />
    ),
}));

vi.mock('./transaction-analysis-drawer', () => ({
    TransactionAnalysisDrawer: ({ open }: { open: boolean }) =>
        open ? <div data-testid="analysis-drawer" /> : null,
}));

const emptyFilters: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
};

function renderMenu(
    filters: TransactionFilters,
    transactions: DecryptedTransaction[] = [],
    onReEvaluateComplete?: () => void,
    hasTransactionalAccounts = true,
    onTransactionSaved?: () => void,
) {
    return render(
        <TransactionActionsMenu
            transactions={transactions}
            hasTransactionalAccounts={hasTransactionalAccounts}
            onTransactionSaved={onTransactionSaved}
            filters={filters}
            onReEvaluateComplete={onReEvaluateComplete}
        />,
    );
}

function uncategorized(id: string): DecryptedTransaction {
    return { id, category_id: null } as DecryptedTransaction;
}

function openMoreActionsMenu() {
    fireEvent.pointerDown(screen.getByLabelText('More actions'), {
        button: 0,
        ctrlKey: false,
    });

    return screen.findByRole('menu');
}

function actionBarLabels(container: HTMLElement) {
    return [...container.querySelectorAll('button, a')].map(
        (element) => element.getAttribute('aria-label') ?? element.textContent,
    );
}

beforeEach(() => {
    isMobile = false;
    reEvaluateTransactionRules.mockReset();
});

describe('TransactionActionsMenu analysis button', () => {
    it('is disabled when no filter is applied', () => {
        renderMenu(emptyFilters);

        expect(screen.getByText('Analysis').closest('button')).toHaveAttribute(
            'aria-disabled',
            'true',
        );
    });

    it('opens the analysis drawer when a filter is applied and clicked', () => {
        renderMenu({ ...emptyFilters, labelIds: ['label-1'] });

        const button = screen.getByText('Analysis').closest('button')!;
        expect(button).toHaveAttribute('aria-disabled', 'false');

        fireEvent.click(button);
        expect(screen.getByTestId('analysis-drawer')).toBeInTheDocument();
    });
});

describe('TransactionActionsMenu action bar layout', () => {
    it('shows analysis, add transaction and categorize in that order on desktop', () => {
        const { container } = renderMenu(emptyFilters, [uncategorized('t-1')]);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'Add transaction',
            'Categorize1',
            'More actions',
        ]);
    });

    it('keeps the same order when every transaction is categorized', () => {
        const { container } = renderMenu(emptyFilters);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'Add transaction',
            'Categorize',
            'More actions',
        ]);
    });

    it('leaves importing to the app header', async () => {
        renderMenu(emptyFilters);

        const menu = await openMoreActionsMenu();
        expect(
            within(menu).queryByText('Import Transactions'),
        ).not.toBeInTheDocument();
    });

    it('keeps adding off while the user owns no account to file one in', () => {
        renderMenu(emptyFilters, [], undefined, false);

        expect(
            screen.getByRole('button', { name: 'Add transaction' }),
        ).toHaveAttribute('aria-disabled', 'true');
    });

    it('hands the list refresh to the add button', () => {
        const onTransactionSaved = vi.fn();
        renderMenu(emptyFilters, [], undefined, true, onTransactionSaved);

        fireEvent.click(
            screen.getByRole('button', { name: 'Add transaction' }),
        );

        expect(onTransactionSaved).toHaveBeenCalledTimes(1);
    });

    it('keeps add transaction in the bar and moves categorize to the dropdown on mobile', async () => {
        isMobile = true;
        const { container } = renderMenu(emptyFilters, [
            uncategorized('t-1'),
            uncategorized('t-2'),
        ]);

        expect(actionBarLabels(container)).toEqual([
            'Analysis',
            'Add transaction',
            'More actions',
        ]);

        const menu = await openMoreActionsMenu();
        expect(
            [...menu.querySelectorAll('[role="menuitem"]')].map(
                (item) => item.textContent,
            ),
        ).toEqual(['Categorize2', 'Update categories automatically']);
        expect(
            within(menu).getByText('Categorize').closest('a'),
        ).toHaveAttribute('href', '/transactions/categorize');
    });

    it('disables the categorize dropdown item when nothing is uncategorized', async () => {
        isMobile = true;
        renderMenu(emptyFilters);

        const menu = await openMoreActionsMenu();
        const categorizeItem = within(menu)
            .getByText('Categorize')
            .closest('[role="menuitem"]')!;

        expect(categorizeItem).toHaveAttribute('data-disabled');
        expect(categorizeItem.closest('a')).toBeNull();
    });
});

describe('TransactionActionsMenu update categories automatically', () => {
    async function clickUpdateCategories(onReEvaluateComplete: () => void) {
        renderMenu(emptyFilters, [], onReEvaluateComplete);

        const menu = await openMoreActionsMenu();
        fireEvent.click(
            within(menu).getByText('Update categories automatically'),
        );

        await waitFor(() =>
            expect(reEvaluateTransactionRules).toHaveBeenCalledTimes(1),
        );
    }

    it('refreshes the list once the re-evaluation is done', async () => {
        reEvaluateTransactionRules.mockResolvedValue('done');
        const onReEvaluateComplete = vi.fn();

        await clickUpdateCategories(onReEvaluateComplete);

        await waitFor(() => expect(onReEvaluateComplete).toHaveBeenCalled());
    });

    it.each(['queued', 'failed'])(
        'does not refresh the list when the re-evaluation is %s',
        async (outcome) => {
            reEvaluateTransactionRules.mockResolvedValue(outcome);
            const onReEvaluateComplete = vi.fn();

            await clickUpdateCategories(onReEvaluateComplete);
            await act(() => reEvaluateTransactionRules.mock.results[0].value);

            expect(onReEvaluateComplete).not.toHaveBeenCalled();
        },
    );
});
