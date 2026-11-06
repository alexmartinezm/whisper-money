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
        selectedCount: 3,
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

    it('says when it reaches every row matching the filters', () => {
        render(
            <TransactionRemoveLabelsDialog
                dialog={dialogState({ isAllMatching: true })}
            />,
        );

        expect(
            screen.getByText(
                'Are you sure you want to remove every label from all the transactions matching the filters? This action cannot be undone.',
            ),
        ).toBeTruthy();
    });

    it('closes without removing anything on cancel', () => {
        const dialog = dialogState();
        render(<TransactionRemoveLabelsDialog dialog={dialog} />);

        fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(dialog.onClose).toHaveBeenCalledOnce();
        expect(dialog.onConfirm).not.toHaveBeenCalled();
    });
});
