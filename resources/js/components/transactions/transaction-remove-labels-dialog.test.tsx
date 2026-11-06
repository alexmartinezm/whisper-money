import { type TransactionRemoveLabelsDialogState } from '@/hooks/use-transaction-bulk-actions';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TransactionRemoveLabelsDialog } from './transaction-remove-labels-dialog';

function dialogState(
    overrides: Partial<TransactionRemoveLabelsDialogState> = {},
): TransactionRemoveLabelsDialogState {
    return {
        isOpen: true,
        isAllMatching: false,
        hasFilters: false,
        selectedCount: 3,
        matchingCount: null,
        countFailed: false,
        canConfirm: true,
        onConfirm: vi.fn(),
        onClose: vi.fn(),
        ...overrides,
    };
}

describe('TransactionRemoveLabelsDialog', () => {
    it('stays out of the way until asked', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({ isOpen: false })}
            />,
        );

        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('says how many rows lose their labels and removes them on confirm', () => {
        const dialog = dialogState();
        render(<TransactionRemoveLabelsDialog dialog={dialog} />);

        expect(
            screen.getByText(
                'Are you sure you want to remove every label from 3 transactions? This action cannot be undone.',
            ),
        ).toBeTruthy();

        fireEvent.click(
            screen.getByRole('button', { name: 'Remove all labels' }),
        );

        expect(dialog.onConfirm).toHaveBeenCalledOnce();
    });

    function confirmButton(): HTMLButtonElement {
        return screen.getByTestId(
            'confirm-remove-all-labels',
        ) as HTMLButtonElement;
    }

    it('gives the real number of rows matching the filters', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    hasFilters: true,
                    matchingCount: 1204,
                })}
            />,
        );

        expect(
            screen.getByText(
                'Are you sure you want to remove every label from the 1204 transactions matching the filters? This action cannot be undone.',
            ),
        ).toBeTruthy();
        expect(confirmButton().disabled).toBe(false);
    });

    it('says all transactions, and how many, with no filter set', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    matchingCount: 8350,
                })}
            />,
        );

        expect(
            screen.getByText(
                'No filter is set: this removes every label from all your 8350 transactions. This action cannot be undone.',
            ),
        ).toBeTruthy();
    });

    it('keeps the confirmation off while the rows matching the filters are counted', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    hasFilters: true,
                    canConfirm: false,
                })}
            />,
        );

        expect(
            screen.getByText(
                'Counting the transactions matching the filters...',
            ),
        ).toBeTruthy();
        expect(confirmButton().disabled).toBe(true);
    });

    it('says all transactions even before the count arrives', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    canConfirm: false,
                })}
            />,
        );

        expect(
            screen.getByText(
                'No filter is set: this removes every label from all your transactions. This action cannot be undone.',
            ),
        ).toBeTruthy();
        expect(confirmButton().disabled).toBe(true);
    });

    it('falls back to all transactions when the count fails with no filter set', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    countFailed: true,
                })}
            />,
        );

        expect(
            screen.getByText(
                'No filter is set: this removes every label from all your transactions. This action cannot be undone.',
            ),
        ).toBeTruthy();
        expect(confirmButton().disabled).toBe(false);
    });

    it('stops when the rows matching the filters cannot be counted', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({
                    isAllMatching: true,
                    hasFilters: true,
                    countFailed: true,
                    canConfirm: false,
                })}
            />,
        );

        expect(
            screen.getByText(
                'The transactions matching the filters could not be counted, so their labels cannot be removed. Cancel and try again.',
            ),
        ).toBeTruthy();
        expect(confirmButton().disabled).toBe(true);
    });

    it('closes without removing anything on cancel', () => {
        const dialog = dialogState();
        render(<TransactionRemoveLabelsDialog dialog={dialog} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(dialog.onClose).toHaveBeenCalledOnce();
        expect(dialog.onConfirm).not.toHaveBeenCalled();
    });
});
