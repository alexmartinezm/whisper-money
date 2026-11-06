import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import { act, renderHook, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useInMemoryTransactionSource } from './use-in-memory-transaction-source';

const drawnRows = vi.hoisted(() => ({ current: [] as Transaction[] }));

vi.mock('@/hooks/use-day-summaries', () => ({
    useInMemoryDaySummaries:
        (
            rows: Transaction[],
            {
                drawn,
                rowTotalAmount,
            }: {
                drawn: Transaction[];
                rowTotalAmount: (row: Transaction) => number;
            },
        ) =>
        (date: string) => {
            drawnRows.current = drawn;

            const day = rows.filter((row) => row.transaction_date === date);

            return {
                count: day.length,
                total: {
                    status: 'ready',
                    amount: day.reduce(
                        (sum, row) => sum + rowTotalAmount(row),
                        0,
                    ),
                },
            };
        },
}));

const NO_CATEGORIES: Category[] = [];

function transaction(
    id: string,
    overrides: Partial<Transaction> = {},
): Transaction {
    return {
        id,
        description: id,
        notes: null,
        amount: -1000,
        currency_code: 'EUR',
        transaction_date: '2026-10-01',
        account_id: 'checking',
        category_id: null,
        creditor_name: null,
        debtor_name: null,
        ...overrides,
    } as unknown as Transaction;
}

const ROWS = [
    transaction('a', { transaction_date: '2026-10-03', creditor_name: 'Zed' }),
    transaction('b', {
        transaction_date: '2026-10-01',
        description: 'Coffee',
        creditor_name: 'Amy',
    }),
    transaction('c', {
        transaction_date: '2026-10-02',
        account_id: 'savings',
    }),
];

describe('useInMemoryTransactionSource', () => {
    beforeEach(() => {
        vi.stubGlobal('requestAnimationFrame', (callback: () => void) => {
            callback();
            return 0;
        });
    });

    afterEach(() => vi.unstubAllGlobals());

    it('draws the newest rows first, a page at a time', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                pageSize: 2,
                categories: NO_CATEGORIES,
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.rows.map((row) => row.id)).toEqual(['a', 'c']);
        expect(result.current.heldRows).toHaveLength(3);
        expect(result.current.pagination).toMatchObject({
            displayedCount: 2,
            total: 3,
            hasMore: true,
            loadsOnScroll: false,
        });

        act(() => result.current.pagination.loadMore());

        expect(result.current.rows.map((row) => row.id)).toEqual([
            'a',
            'c',
            'b',
        ]);
        expect(result.current.pagination.hasMore).toBe(false);
    });

    it('holds the rows from the first render, so it never draws empty first', () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                categories: NO_CATEGORIES,
            }),
        );

        expect(result.current.isLoading).toBe(false);
        expect(result.current.heldRows).toHaveLength(3);
    });

    it('takes the rows the page hands over next', () => {
        const { result, rerender } = renderHook(
            ({ transactions }) =>
                useInMemoryTransactionSource({
                    transactions,
                    categories: NO_CATEGORIES,
                }),
            { initialProps: { transactions: ROWS } },
        );

        rerender({ transactions: [transaction('z')] });

        expect(result.current.heldRows.map((row) => row.id)).toEqual(['z']);
    });

    it('keeps an account page to its account', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                categories: NO_CATEGORIES,
                accountId: 'checking',
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.filters.accountIds).toEqual(['checking']);
        expect(result.current.rows.map((row) => row.id)).toEqual(['a', 'b']);
    });

    it('sorts and searches in the browser, back to the first page', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                pageSize: 1,
                categories: NO_CATEGORIES,
            }),
        );
        await waitFor(() => expect(result.current.isLoading).toBe(false));
        act(() => result.current.pagination.loadMore());

        act(() =>
            result.current.onSortingChange([
                { id: 'creditor_name', desc: false },
            ]),
        );

        expect(result.current.manualSorting).toBe(false);
        expect(result.current.pagination.displayedCount).toBe(1);
        expect(result.current.rows.map((row) => row.id)).toEqual(['c']);

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                searchText: 'coffee',
            }),
        );

        await waitFor(() =>
            expect(result.current.rows.map((row) => row.id)).toEqual(['b']),
        );
    });

    it('counts each day over every row the filters keep, drawn or not', async () => {
        const sameDay = [transaction('a'), transaction('b'), transaction('c')];
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: sameDay,
                categories: NO_CATEGORIES,
                pageSize: 1,
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        expect(result.current.rows).toHaveLength(1);
        expect(result.current.daySummaryOf('2026-10-01').count).toBe(3);
    });

    it('hands the day summaries only the rows on screen, none without day headers', () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                categories: NO_CATEGORIES,
                pageSize: 1,
            }),
        );

        result.current.daySummaryOf('2026-10-03');
        expect(drawnRows.current.map((row) => row.id)).toEqual(
            result.current.rows.map((row) => row.id),
        );

        act(() =>
            result.current.onSortingChange([{ id: 'amount', desc: true }]),
        );

        result.current.daySummaryOf('2026-10-03');
        expect(drawnRows.current).toEqual([]);
    });

    describe('a split transaction', () => {
        const food = { id: 'food', parent_id: null } as unknown as Category;
        const groceries = {
            id: 'groceries',
            parent_id: 'food',
        } as unknown as Category;
        const home = { id: 'home', parent_id: null } as unknown as Category;
        const categories = [food, groceries, home];
        const splitRow = transaction('split', {
            amount: -10000,
            splits: [
                { category_id: 'groceries', amount: -3000 },
                { category_id: 'home', amount: -7000 },
            ],
        } as unknown as Partial<Transaction>);
        const rows = [splitRow, transaction('other')];

        function renderSplitSource() {
            return renderHook(() =>
                useInMemoryTransactionSource({
                    transactions: rows,
                    categories,
                }),
            );
        }

        it('stands at its own amount without a category filter', () => {
            const { result } = renderSplitSource();

            expect(
                result.current.daySummaryOf('2026-10-01').total,
            ).toMatchObject({ amount: -11000 });
        });

        it('is listed for a line in a subcategory and adds only that line', () => {
            const { result } = renderSplitSource();

            act(() =>
                result.current.onFiltersChange({
                    ...result.current.filters,
                    categoryIds: ['food'],
                }),
            );

            expect(result.current.rows.map((row) => row.id)).toEqual(['split']);
            expect(result.current.daySummaryOf('2026-10-01')).toMatchObject({
                count: 1,
                total: { amount: -3000 },
            });
        });
    });

    it('reads a row as the table edits it, its text ready and its labels by id', () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: [
                    transaction('a', {
                        description: 'Coffee',
                        notes: 'Beans',
                        labels: [{ id: 'label' }],
                    } as unknown as Partial<Transaction>),
                ],
                categories: NO_CATEGORIES,
            }),
        );

        expect(result.current.rows[0]).toMatchObject({
            decryptedDescription: 'Coffee',
            decryptedNotes: 'Beans',
            label_ids: ['label'],
        });
    });
});
