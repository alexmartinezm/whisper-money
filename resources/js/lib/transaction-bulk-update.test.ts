import { describe, expect, it } from 'vitest';

import type {
    DecryptedTransaction,
    ServerTransaction,
} from '@/types/transaction';
import { mergeAuthoritativeTransactions } from './transaction-bulk-update';

function transaction(
    id: string,
    overrides: Partial<DecryptedTransaction> = {},
): DecryptedTransaction {
    return {
        id,
        user_id: 'user-1',
        account_id: 'account-1',
        category_id: null,
        description: 'encrypted-description',
        description_iv: 'iv',
        transaction_date: '2026-07-21',
        amount: -1000,
        currency_code: 'EUR',
        notes: null,
        notes_iv: null,
        source: 'imported',
        created_at: '2026-07-21T00:00:00Z',
        updated_at: '2026-07-21T00:00:00Z',
        decryptedDescription: 'Coffee',
        decryptedNotes: null,
        ...overrides,
    };
}

describe('mergeAuthoritativeTransactions', () => {
    it('replaces server-owned fields while preserving decrypted display fields', () => {
        const previous = [
            transaction('tx-1', { category_id: null }),
            transaction('tx-2'),
        ];
        const authoritative = [
            {
                ...previous[0],
                category_id: 'category-1',
                updated_at: '2026-07-21T01:00:00Z',
                labels: [{ id: 'label-1', name: 'Work' }],
            } as unknown as ServerTransaction,
        ];

        const merged = mergeAuthoritativeTransactions(previous, authoritative);

        expect(merged[0]).toMatchObject({
            category_id: 'category-1',
            updated_at: '2026-07-21T01:00:00Z',
            decryptedDescription: 'Coffee',
            labels: [{ id: 'label-1', name: 'Work' }],
        });
        expect(merged[1]).toBe(previous[1]);
    });
});
