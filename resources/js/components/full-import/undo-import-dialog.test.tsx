import { type ImportHistoryEntry } from '@/types/full-import';
import { act, fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { UndoImportDialog } from './undo-import-dialog';

const routerDelete = vi.hoisted(() => vi.fn());
const captureEvent = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({
    router: { delete: routerDelete },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent }));

const entry: ImportHistoryEntry = {
    id: 'import-1',
    source: 'banktrack',
    mode: 'add',
    status: 'completed',
    file_name: 'banktrack-export.csv',
    error: null,
    created_at: '2026-10-03T10:00:00+00:00',
    finished_at: null,
    undone_at: null,
    stats: {},
    undoable: true,
    undo_failed: false,
    summary: {
        accounts: [{ name: 'Wise', transactions: 12 }],
        categories: 2,
        categories_kept: 0,
        transactions: 15,
        balances: 4,
        later_transactions: 3,
        into_own_accounts: [{ name: 'BBVA Conjunta', transactions: 1 }],
        connected_accounts: [{ name: 'Revolut (Banktrack)' }],
    },
};

function renderDialog(onOpenChange = vi.fn(), shown = entry) {
    render(
        <UndoImportDialog
            entry={shown}
            open
            onOpenChange={onOpenChange}
            locale="en-US"
        />,
    );

    return onOpenChange;
}

describe('UndoImportDialog', () => {
    beforeEach(() => {
        routerDelete.mockReset();
        captureEvent.mockReset();
    });

    it('lists what goes, including what was added afterwards', () => {
        renderDialog();

        expect(screen.getByText('1 account: Wise')).toBeInTheDocument();
        expect(screen.getByText('2 new categories')).toBeInTheDocument();
        expect(
            screen.getByText('15 transactions and 4 daily balances'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'BBVA Conjunta stays, without the imported transaction.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '3 transactions added to these accounts afterwards (by hand or by another import) are deleted too.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Subcategories you created under the imported categories are removed too.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Revolut (Banktrack) is now connected to your bank, so it stays; only the imported transactions are removed.',
            ),
        ).toBeInTheDocument();
    });

    // Undo keeps the imported categories split lines still use, so the
    // dialog must not promise to remove them.
    it('counts apart the imported categories undo keeps for split lines', () => {
        renderDialog(vi.fn(), {
            ...entry,
            summary: { ...entry.summary!, categories: 3, categories_kept: 2 },
        });

        expect(screen.getByText('1 new category')).toBeInTheDocument();
        expect(
            screen.getByText(
                '2 imported categories stay, because split transactions use them.',
            ),
        ).toBeInTheDocument();
    });

    it('promises no category removal when undo keeps them all', () => {
        renderDialog(vi.fn(), {
            ...entry,
            summary: { ...entry.summary!, categories: 1, categories_kept: 1 },
        });

        expect(screen.queryByText(/new categor/)).not.toBeInTheDocument();
        expect(
            screen.queryByText(
                'Subcategories you created under the imported categories are removed too.',
            ),
        ).not.toBeInTheDocument();
        expect(
            screen.getByText(
                '1 imported category stays, because split transactions use it.',
            ),
        ).toBeInTheDocument();
    });

    it('stays open while undoing and closes once it succeeds', () => {
        const onOpenChange = renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Undo import' }));

        expect(onOpenChange).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: /Undoing/ })).toBeDisabled();
        expect(captureEvent).not.toHaveBeenCalled();

        const [, options] = routerDelete.mock.calls[0];
        act(() => {
            options.onSuccess();
            options.onFinish();
        });

        expect(onOpenChange).toHaveBeenCalledWith(false);
        expect(captureEvent).toHaveBeenCalledOnce();
        expect(captureEvent).toHaveBeenCalledWith(
            'full_import_undo_requested',
            { transactions: 0 },
        );
    });

    it('shows a failure inside the dialog', () => {
        const onOpenChange = renderDialog();

        fireEvent.click(screen.getByRole('button', { name: 'Undo import' }));

        const [, options] = routerDelete.mock.calls[0];
        act(() => {
            options.onError({ import: 'This import cannot be undone.' });
            options.onFinish();
        });

        expect(
            screen.getByText('This import cannot be undone.'),
        ).toBeInTheDocument();
        expect(onOpenChange).not.toHaveBeenCalled();
        // A refused undo is not counted as requested.
        expect(captureEvent).not.toHaveBeenCalled();
    });
});
