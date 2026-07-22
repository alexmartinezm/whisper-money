import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    transactionSyncService,
    transformTransactionFromServer,
} from './transaction-sync';

const dbMock = vi.hoisted(() => ({
    transactions: {
        delete: vi.fn(async () => undefined),
        put: vi.fn(async () => undefined),
    },
    sync_metadata: { delete: vi.fn(), get: vi.fn(), put: vi.fn() },
}));

const axiosMock = vi.hoisted(() => ({
    delete: vi.fn(async () => ({ data: {} })),
    patch: vi.fn(
        async (): Promise<{ data: Record<string, unknown> }> => ({
            data: { updated_count: 2, skipped_split_count: 1 },
        }),
    ),
}));

// Keep the real withDb (reads globalThis live); swap only the Dexie-backed db.
vi.mock('@/lib/dexie-db', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@/lib/dexie-db')>();
    return { ...actual, db: dbMock };
});

vi.mock('axios', () => ({ default: axiosMock }));

describe('transactionSyncService.update', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('persists the authoritative server response in IndexedDB before returning it', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);
        axiosMock.patch.mockResolvedValueOnce({
            data: {
                data: {
                    id: 'txn-1',
                    transaction_date: '2026-07-21T00:00:00.000Z',
                    updated_at: '2026-07-21T18:00:00.000Z',
                    labels: [{ id: 'label-1' }],
                    splits: [{ id: 'split-1', amount: -1000 }],
                },
                learned_rule: null,
            },
        });

        const updated = await transactionSyncService.update('txn-1', {
            amount: -1000,
        });

        expect(updated).toMatchObject({
            id: 'txn-1',
            transaction_date: '2026-07-21',
            updated_at: '2026-07-21T18:00:00.000Z',
            label_ids: ['label-1'],
            splits: [{ id: 'split-1', amount: -1000 }],
        });
        expect(dbMock.transactions.put).toHaveBeenCalledWith(
            expect.objectContaining({
                id: 'txn-1',
                updated_at: '2026-07-21T18:00:00.000Z',
                label_ids: ['label-1'],
            }),
        );
    });
});

describe('transactionSyncService.delete', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('deletes via the API but skips the cache eviction when IndexedDB is missing', async () => {
        vi.stubGlobal('indexedDB', undefined);

        await expect(
            transactionSyncService.delete('txn-1'),
        ).resolves.toBeUndefined();

        expect(axiosMock.delete).toHaveBeenCalledWith('/transactions/txn-1', {
            data: undefined,
        });
        expect(dbMock.transactions.delete).not.toHaveBeenCalled();
    });

    it('deletes via the API and evicts the cache when IndexedDB is available', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);

        await transactionSyncService.delete('txn-1');

        expect(axiosMock.delete).toHaveBeenCalledWith('/transactions/txn-1', {
            data: undefined,
        });
        expect(dbMock.transactions.delete).toHaveBeenCalledWith('txn-1');
    });
});

describe('transactionSyncService bulk updates', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('persists authoritative bulk records before returning them', async () => {
        vi.stubGlobal('indexedDB', {} as IDBFactory);
        axiosMock.patch.mockResolvedValueOnce({
            data: {
                updated_count: 1,
                skipped_split_count: 1,
                updated_ids: ['tx-1'],
                skipped_split_ids: ['tx-2'],
                transactions: [
                    {
                        id: 'tx-1',
                        transaction_date: '2026-07-21T00:00:00.000Z',
                        updated_at: '2026-07-21T18:00:00.123456Z',
                        labels: [{ id: 'label-1' }],
                        splits: [],
                    },
                ],
            },
        });

        const result = await transactionSyncService.updateMany(
            ['tx-1', 'tx-2'],
            { category_id: 'category-1' },
        );

        expect(result.transactions[0]).toMatchObject({
            id: 'tx-1',
            transaction_date: '2026-07-21',
            label_ids: ['label-1'],
        });
        expect(dbMock.transactions.put).toHaveBeenCalledWith(
            expect.objectContaining({ id: 'tx-1', label_ids: ['label-1'] }),
        );
    });

    it('returns updated and skipped split counts for explicit ids', async () => {
        await expect(
            transactionSyncService.updateMany(['tx-1', 'tx-2', 'tx-3'], {
                category_id: 'category-1',
            }),
        ).resolves.toMatchObject({
            updated_count: 2,
            skipped_split_count: 1,
        });
    });

    it('serializes filter dates as local calendar dates', async () => {
        const previousTimezone = process.env.TZ;
        process.env.TZ = 'Pacific/Kiritimati';

        try {
            const dateFrom = new Date(2026, 6, 1);
            const dateTo = new Date(2026, 6, 31);

            await transactionSyncService.updateByFilters(
                { dateFrom, dateTo },
                { category_id: 'category-1' },
            );

            expect(axiosMock.patch).toHaveBeenLastCalledWith(
                '/transactions/bulk',
                expect.objectContaining({
                    filters: expect.objectContaining({
                        date_from: '2026-07-01',
                        date_to: '2026-07-31',
                    }),
                }),
            );
        } finally {
            if (previousTimezone === undefined) {
                delete process.env.TZ;
            } else {
                process.env.TZ = previousTimezone;
            }
        }
    });

    it('returns updated and skipped split counts for filters', async () => {
        await expect(
            transactionSyncService.updateByFilters(
                { dateFrom: new Date('2026-07-01T00:00:00Z') },
                { category_id: 'category-1' },
            ),
        ).resolves.toMatchObject({
            updated_count: 2,
            skipped_split_count: 1,
        });
    });
});

describe('transaction sync transformation', () => {
    it('keeps embedded split lines while deriving label ids', () => {
        const splits = [
            {
                id: 'split-1',
                category_id: 'food',
                amount: -6000,
                position: 0,
                category: { id: 'food', name: 'Food' },
            },
            {
                id: 'split-2',
                category_id: 'home',
                amount: -4000,
                position: 1,
                category: { id: 'home', name: 'Home' },
            },
        ];

        expect(
            transformTransactionFromServer({
                id: 'transaction-1',
                transaction_date: '2026-07-18T00:00:00.000Z',
                labels: [{ id: 'label-1' }],
                splits,
                is_split: true,
                split_count: 2,
            }),
        ).toMatchObject({
            transaction_date: '2026-07-18',
            label_ids: ['label-1'],
            splits,
            is_split: true,
            split_count: 2,
        });
    });
});
