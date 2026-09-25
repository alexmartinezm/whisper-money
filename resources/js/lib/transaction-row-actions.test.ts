import { describe, expect, it, vi } from 'vitest';

import { getTransactionRowActions } from '@/lib/transaction-row-actions';
import type { DecryptedTransaction } from '@/types/transaction';

const transaction = {
    id: 'txn-1',
    amount: -5340,
    decryptedDescription: 'MERCADONA S.A.',
} as DecryptedTransaction;

const handlers = {
    onEdit: vi.fn(),
    onReEvaluateRules: vi.fn(),
    onAutomate: vi.fn(),
    onDelete: vi.fn(),
};

describe('getTransactionRowActions', () => {
    it('offers automating the categorization next to re-evaluating the rules', () => {
        const ids = getTransactionRowActions({
            transaction,
            ...handlers,
        }).map((action) => action.id);

        expect(ids).toEqual([
            'edit',
            're-evaluate-rules',
            'automate',
            'delete',
        ]);
    });

    it('hands the row to the automate handler', () => {
        const automate = getTransactionRowActions({
            transaction,
            ...handlers,
        }).find((action) => action.id === 'automate');

        automate?.onSelect();

        expect(handlers.onAutomate).toHaveBeenCalledWith(transaction);
    });

    it('keeps delete as the only destructive action', () => {
        const destructive = getTransactionRowActions({
            transaction,
            ...handlers,
        }).filter((action) => action.variant === 'destructive');

        expect(destructive.map((action) => action.id)).toEqual(['delete']);
    });
});
