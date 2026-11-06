import { FILTER_FACETS } from '@/lib/transaction-filter-facets';
import { type Category } from '@/types/category';
import { type Transaction, type TransactionFilters } from '@/types/transaction';
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

    it('orders the rows of one day by id, newest first, as the server does', () => {
        const sameDay = ['0b', '0c', '0a'].map((id) => transaction(id));
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                categories: NO_CATEGORIES,
                transactions: sameDay,
            }),
        );

        expect(result.current.rows.map((row) => row.id)).toEqual([
            '0c',
            '0b',
            '0a',
        ]);
    });

    it('searches in the browser, back to the first page', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                pageSize: 1,
                categories: NO_CATEGORIES,
            }),
        );
        await waitFor(() => expect(result.current.isLoading).toBe(false));
        act(() => result.current.pagination.loadMore());
        expect(result.current.pagination.displayedCount).toBe(2);

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                searchText: 'coffee',
            }),
        );

        await waitFor(() =>
            expect(result.current.rows.map((row) => row.id)).toEqual(['b']),
        );
        expect(result.current.pagination.displayedCount).toBe(1);
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

    it('hands the day summaries only the rows on screen', () => {
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

    it('counts what a filter set would keep without applying it', async () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                categories: NO_CATEGORIES,
                accountId: 'checking',
            }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        const count = (changes: Partial<TransactionFilters>) =>
            result.current.countMatching(
                { ...result.current.filters, ...changes },
                new AbortController().signal,
            );

        // Scoped to its account: "c" is in savings.
        await expect(count({})).resolves.toBe(2);
        await expect(count({ searchText: 'coffee' })).resolves.toBe(1);
        expect(result.current.rows).toHaveLength(2);
    });

    it('counts what the list shows for a category, through subcategories and split lines', async () => {
        const food = { id: 'food', parent_id: null } as unknown as Category;
        const groceries = {
            id: 'groceries',
            parent_id: 'food',
        } as unknown as Category;
        const home = { id: 'home', parent_id: null } as unknown as Category;
        const rows = [
            transaction('split', {
                amount: -10000,
                splits: [
                    { category_id: 'groceries', amount: -3000 },
                    { category_id: 'groceries', amount: -1000 },
                    { category_id: 'home', amount: -6000 },
                ],
            } as unknown as Partial<Transaction>),
            transaction('unsplit', { category_id: 'groceries' }),
            transaction('other', { category_id: 'home' }),
        ];
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: rows,
                categories: [food, groceries, home],
            }),
        );

        const next = { ...result.current.filters, categoryIds: ['food'] };
        const counted = await result.current.countMatching(
            next,
            new AbortController().signal,
        );

        expect(result.current.rows).toHaveLength(3);

        act(() => result.current.onFiltersChange(next));

        expect(result.current.rows.map((row) => row.id).sort()).toEqual([
            'split',
            'unsplit',
        ]);
        expect(counted).toBe(result.current.rows.length);
    });

    it('keeps only the rows whose category has a picked type', async () => {
        const categories = [
            { id: 'groceries', type: 'expense' },
            { id: 'piggy-bank', type: 'savings' },
            { id: 'salary', type: 'income' },
        ] as Category[];
        const rows = [
            transaction('food', { category_id: 'groceries' }),
            transaction('saved', { category_id: 'piggy-bank' }),
            transaction('paid', { category_id: 'salary' }),
            transaction('unsorted'),
        ];
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({ categories, transactions: rows }),
        );

        await waitFor(() => expect(result.current.isLoading).toBe(false));

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                categoryTypes: ['expense', 'savings'],
            }),
        );

        await waitFor(() =>
            expect(result.current.rows.map((row) => row.id).sort()).toEqual([
                'food',
                'saved',
            ]),
        );
    });

    describe('the category type of a split transaction', () => {
        const categories = [
            { id: 'groceries', type: 'expense' },
            { id: 'piggy-bank', type: 'savings' },
        ] as Category[];
        const rows = [
            transaction('split', {
                amount: -10000,
                splits: [
                    { category_id: 'groceries', amount: -7000 },
                    { category_id: 'piggy-bank', amount: -3000 },
                ],
            } as unknown as Partial<Transaction>),
            transaction('other', { category_id: 'groceries' }),
        ];

        it('lists it for any line of the type, adds only those lines and counts it once', async () => {
            const { result } = renderHook(() =>
                useInMemoryTransactionSource({
                    transactions: rows,
                    categories,
                }),
            );
            const next: TransactionFilters = {
                ...result.current.filters,
                categoryTypes: ['savings'],
            };

            const counted = await result.current.countMatching(
                next,
                new AbortController().signal,
            );

            act(() => result.current.onFiltersChange(next));

            expect(result.current.rows.map((row) => row.id)).toEqual(['split']);
            expect(counted).toBe(1);
            expect(result.current.daySummaryOf('2026-10-01')).toMatchObject({
                count: 1,
                total: { amount: -3000 },
            });
        });
    });

    describe('the label and AI filters', () => {
        const food = { id: 'food', parent_id: null } as unknown as Category;
        const rows = [
            transaction('labelled', {
                amount: -100,
                labels: [{ id: 'trip' }],
            } as unknown as Partial<Transaction>),
            transaction('in-food', { category_id: 'food' }),
            transaction('guessed', { category_source: 'ai' }),
            transaction('split', {
                amount: -10000,
                labels: [{ id: 'trip' }],
                splits: [
                    { category_id: 'food', amount: -3000 },
                    { category_id: 'home', amount: -7000 },
                ],
            } as unknown as Partial<Transaction>),
        ];

        function renderFiltered(changes: Partial<TransactionFilters>) {
            const { result } = renderHook(() =>
                useInMemoryTransactionSource({
                    transactions: rows,
                    categories: [food],
                }),
            );
            const next = { ...result.current.filters, ...changes };
            const counted = result.current.countMatching(
                next,
                new AbortController().signal,
            );

            act(() => result.current.onFiltersChange(next));

            return { result, counted };
        }

        const ids = (rendered: { rows: { id: string }[] }) =>
            rendered.rows.map((row) => row.id).sort();

        it('keeps the rows carrying a label, in the list, the count and the day', async () => {
            const { result, counted } = renderFiltered({ labelIds: ['trip'] });

            expect(ids(result.current)).toEqual(['labelled', 'split']);
            await expect(counted).resolves.toBe(2);
            expect(result.current.daySummaryOf('2026-10-01')).toMatchObject({
                count: 2,
                // A label keeps every line of a split transaction.
                total: { amount: -10100 },
            });
        });

        it('reads a label and a category as one filter, as the server does', async () => {
            const { result, counted } = renderFiltered({
                categoryIds: ['food'],
                labelIds: ['trip'],
            });

            expect(ids(result.current)).toEqual([
                'in-food',
                'labelled',
                'split',
            ]);
            await expect(counted).resolves.toBe(3);
        });

        it('keeps what AI categorized, in the list, the count and the day', async () => {
            const { result, counted } = renderFiltered({
                aiCategorizedOnly: true,
            });

            expect(ids(result.current)).toEqual(['guessed']);
            await expect(counted).resolves.toBe(1);
            expect(result.current.daySummaryOf('2026-10-01').count).toBe(1);
        });
    });

    it('offers every facet but the cashflow block, and drops a block with its dates', () => {
        const { result } = renderHook(() =>
            useInMemoryTransactionSource({
                transactions: ROWS,
                categories: NO_CATEGORIES,
            }),
        );

        expect(result.current.facets).toEqual(
            FILTER_FACETS.filter((facet) => facet !== 'cashflowBlock'),
        );

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                cashflowBlock: 'fixed',
            }),
        );

        expect(result.current.filters.cashflowBlock).toBeNull();
    });
});
