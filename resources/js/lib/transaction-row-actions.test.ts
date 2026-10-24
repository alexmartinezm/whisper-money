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
    onDuplicate: vi.fn(),
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
            'duplicate',
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

    it('hands the row to the duplicate handler', () => {
        getTransactionRowActions({
            transaction,
            ...handlers,
        })
            .find((action) => action.id === 'duplicate')
            ?.onSelect();

        expect(handlers.onDuplicate).toHaveBeenCalledWith(transaction);
    });

    it.each([
        ['description', { description_iv: 'iv-1', notes_iv: null }],
        ['notes', { description_iv: null, notes_iv: 'iv-2' }],
    ])(
        'does not offer duplicating a row whose %s is still encrypted on the client',
        (_field, ivs) => {
            const ids = getTransactionRowActions({
                transaction: { ...transaction, ...ivs } as DecryptedTransaction,
                ...handlers,
            }).map((action) => action.id);

            expect(ids).toEqual([
                'edit',
                're-evaluate-rules',
                'automate',
                'delete',
            ]);
        },
    );

    it('keeps delete as the only destructive action', () => {
        const destructive = getTransactionRowActions({
            transaction,
            ...handlers,
        }).filter((action) => action.variant === 'destructive');

        expect(destructive.map((action) => action.id)).toEqual(['delete']);
    });
});
