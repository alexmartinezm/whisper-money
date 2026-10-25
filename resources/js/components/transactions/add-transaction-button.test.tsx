import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AddTransactionButton } from './add-transaction-button';

// Hoisted: the mock factory reads it while the module graph is still loading.
const { refreshPageAfterWrite } = vi.hoisted(() => ({
    refreshPageAfterWrite: vi.fn(),
}));

vi.mock('@/lib/refresh-page', () => ({ refreshPageAfterWrite }));

const load = vi.fn(async () => true);

vi.mock('@/hooks/use-transaction-dialog-data', () => ({
    useTransactionDialogData: () => ({
        data: {
            accounts: [],
            categories: [],
            banks: [],
            labels: [],
            automationRules: [],
        },
        loading: false,
        load,
    }),
}));

vi.mock('./edit-transaction-dialog', () => ({
    EditTransactionDialog: ({
        open,
        mode,
        origin,
        transaction,
        onSuccess,
        onOpenChange,
        onRequestEdit,
        onLabelCreated,
    }: {
        open: boolean;
        mode: 'create' | 'edit';
        origin?: string;
        transaction: { id: string } | null;
        onSuccess: (transaction: unknown) => void;
        onOpenChange: (open: boolean) => void;
        onRequestEdit?: (transaction: unknown) => void;
        onLabelCreated?: (label: unknown) => void;
    }) =>
        open ? (
            <div
                data-testid="create-dialog"
                data-mode={mode}
                data-origin={origin}
                data-transaction={transaction?.id ?? ''}
            >
                <button
                    data-testid="save"
                    onClick={() => {
                        // What the real dialog does on a save: report it, then
                        // close, both in the same batch.
                        onSuccess({ id: 'tx-1' });
                        onOpenChange(false);
                    }}
                />
                <button
                    data-testid="save-ruled"
                    onClick={() => {
                        // A save whose category a rule picked: the toast's
                        // "Change category" fires once the dialog has closed.
                        onSuccess({ id: 'tx-1' });
                        onOpenChange(false);
                        onRequestEdit?.({ id: 'tx-1' });
                    }}
                />
                <button
                    data-testid="create-label"
                    onClick={() =>
                        onLabelCreated?.({ id: 'label-1', name: 'Holidays' })
                    }
                />
            </div>
        ) : null,
}));

describe('AddTransactionButton', () => {
    beforeEach(() => {
        load.mockClear();
        refreshPageAfterWrite.mockClear();
    });

    /**
     * Below `sm` the bar above the list holds Analysis, Export and Columns
     * too, so the label hides there to keep the row on one line.
     */
    it('labels the button "Transaction", collapsing to its icon below sm', () => {
        render(<AddTransactionButton hasTransactionalAccounts />);

        const button = screen.getByRole('button', { name: 'Add transaction' });

        expect(within(button).getByText('Transaction')).toHaveClass(
            'hidden',
            'sm:inline',
        );
        expect(button).toHaveClass('w-9', 'sm:w-auto');
    });

    it('opens the quick-add dialog once the lists are loaded', async () => {
        render(<AddTransactionButton hasTransactionalAccounts />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));

        expect(load).toHaveBeenCalled();
        await waitFor(() => {
            expect(screen.getByTestId('create-dialog')).toBeInTheDocument();
        });
        expect(screen.getByTestId('create-dialog')).toHaveAttribute(
            'data-mode',
            'create',
        );
        expect(screen.getByTestId('create-dialog')).toHaveAttribute(
            'data-origin',
            'quick_add',
        );
    });

    it('refreshes the page underneath once a save has closed the dialog', async () => {
        render(<AddTransactionButton hasTransactionalAccounts />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        fireEvent.click(await screen.findByTestId('save'));

        expect(refreshPageAfterWrite).toHaveBeenCalled();
    });

    it('refreshes through the page when it brings its own refresh', async () => {
        const onSaved = vi.fn();
        render(
            <AddTransactionButton hasTransactionalAccounts onSaved={onSaved} />,
        );

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        fireEvent.click(await screen.findByTestId('save'));

        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    it('reopens on the new transaction to change the category a rule picked', async () => {
        const onSaved = vi.fn();
        render(
            <AddTransactionButton hasTransactionalAccounts onSaved={onSaved} />,
        );

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        fireEvent.click(await screen.findByTestId('save-ruled'));

        const dialog = await screen.findByTestId('create-dialog');
        expect(dialog).toHaveAttribute('data-mode', 'edit');
        expect(dialog).toHaveAttribute('data-transaction', 'tx-1');
        // The page refreshed in place, so this button was never remounted
        // by a full visit and the reopened dialog is still its own.
        expect(onSaved).toHaveBeenCalledTimes(1);
        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    /**
     * Without an in-place refresh the page is visited again and this button
     * remounts, so a "Change category" left on the toast would reopen nothing.
     */
    it('leaves "Change category" off the toast when the page is visited again', async () => {
        render(<AddTransactionButton hasTransactionalAccounts />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        fireEvent.click(await screen.findByTestId('save-ruled'));

        expect(refreshPageAfterWrite).toHaveBeenCalledTimes(1);
        expect(screen.queryByTestId('create-dialog')).not.toBeInTheDocument();
    });

    it('tags what it creates with the surface it sits on', async () => {
        render(
            <AddTransactionButton
                hasTransactionalAccounts
                origin="header"
                testId="header-add-transaction-button"
            />,
        );

        const button = screen.getByTestId('header-add-transaction-button');
        expect(within(button).getByText('Transaction')).toHaveClass(
            'hidden',
            'sm:inline',
        );

        fireEvent.click(button);

        expect(await screen.findByTestId('create-dialog')).toHaveAttribute(
            'data-origin',
            'header',
        );
    });

    it('passes a label created in the dialog up to the page', async () => {
        const onLabelCreated = vi.fn();
        render(
            <AddTransactionButton
                hasTransactionalAccounts
                onSaved={vi.fn()}
                onLabelCreated={onLabelCreated}
            />,
        );

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        fireEvent.click(await screen.findByTestId('create-label'));

        expect(onLabelCreated).toHaveBeenCalledWith({
            id: 'label-1',
            name: 'Holidays',
        });
    });

    it('leaves the page alone when the dialog closes with nothing saved', async () => {
        render(<AddTransactionButton hasTransactionalAccounts />);

        fireEvent.click(screen.getByTestId('add-transaction-button'));
        await screen.findByTestId('create-dialog');

        expect(refreshPageAfterWrite).not.toHaveBeenCalled();
    });

    it('stays disabled while the user owns no account to file one in', () => {
        render(<AddTransactionButton hasTransactionalAccounts={false} />);

        const button = screen.getByTestId('add-transaction-button');
        expect(button).toHaveAttribute('aria-disabled', 'true');

        fireEvent.click(button);

        expect(load).not.toHaveBeenCalled();
        expect(screen.queryByTestId('create-dialog')).not.toBeInTheDocument();
    });
});
