import { type CursorPaginatedResponse } from '@/lib/cursor-pagination';
import { FILTER_FACETS } from '@/lib/transaction-filter-facets';
import {
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    clientFiltersToQueryParams,
    useServerTransactionSource,
    type AppliedFilters,
} from './use-server-transaction-source';

const { visit, reload, get } = vi.hoisted(() => ({
    visit: vi.fn(),
    reload: vi.fn(),
    get: vi.fn(),
}));

vi.mock('axios', () => ({ default: { get } }));

vi.mock('@/actions/App/Http/Controllers/Api/TransactionController', () => ({
    listedCount: {
        url: ({ query }: { query: Record<string, string> }) =>
            `/api/transactions/listed-count?${new URLSearchParams(query)}`,
    },
}));

vi.mock('@inertiajs/react', () => ({
    router: { visit, reload },
    usePage: () => ({
        component: 'transactions/index',
        version: 'v1',
        props: { auth: { user: { currency_code: 'EUR' } } },
    }),
}));

vi.mock('@/actions/App/Http/Controllers/TransactionController', () => ({
    index: ({ query }: { query: Record<string, string> }) => ({
        url: `/transactions?${new URLSearchParams(query)}`,
    }),
}));

const NO_FILTERS: AppliedFilters = {
    date_from: null,
    date_to: null,
    amount_min: null,
    amount_max: null,
    category_ids: [],
    category_types: [],
    account_ids: [],
    label_ids: [],
    creditor_name: '',
    debtor_name: '',
    category_source: null,
    search: '',
    cashflow_block: null,
};

function row(id: string, transaction_date = '2026-10-01'): ServerTransaction {
    return {
        id,
        transaction_date: `${transaction_date}T00:00:00.000000Z`,
        amount: -100,
        currency_code: 'EUR',
        labels: [{ id: 'label' }],
        account: { bank: { id: 'bank' } },
    } as unknown as ServerTransaction;
}

function page(
    rows: ServerTransaction[],
    next_cursor: string | null,
): CursorPaginatedResponse<ServerTransaction> {
    return {
        data: rows,
        next_cursor,
        next_page_url: null,
        prev_cursor: null,
        prev_page_url: null,
        per_page: 50,
    };
}

function renderSource(
    nextCursor: string | null = 'cursor-2',
    applied: Partial<AppliedFilters> = {},
) {
    const appliedFilters = { ...NO_FILTERS, ...applied };
    const transactions = page([row('a')], nextCursor);
    const dayTotals = { '2026-10-01': { count: 4, total: -400 } };

    return renderHook(() =>
        useServerTransactionSource({
            transactions,
            dayTotals,
            appliedFilters,
            categories: [],
        }),
    );
}

describe('clientFiltersToQueryParams', () => {
    it('writes only the filters in use', () => {
        const filters = {
            dateFrom: new Date(2026, 9, 1),
            dateTo: null,
            amountMin: 0,
            amountMax: null,
            categoryIds: ['food', 'fuel'],
            categoryTypes: ['expense', 'savings'],
            accountIds: [],
            labelIds: [],
            creditorName: '',
            debtorName: '',
            searchText: 'coffee',
            aiCategorizedOnly: true,
        } as TransactionFilters;

        expect(clientFiltersToQueryParams(filters)).toEqual({
            date_from: '2026-10-01',
            amount_min: '0',
            category_ids: 'food,fuel',
            category_types: 'expense,savings',
            search: 'coffee',
            category_source: 'ai',
        });
    });
});

describe('useServerTransactionSource', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.clearAllMocks();
    });

    afterEach(() => vi.useRealTimers());

    it('serves the rows trimmed to their day, with labels as ids too', () => {
        const { result } = renderSource();

        expect(result.current.rows[0]).toMatchObject({
            transaction_date: '2026-10-01',
            label_ids: ['label'],
            bank: { id: 'bank' },
        });
        expect(result.current.pagination).toMatchObject({
            displayedCount: 1,
            hasMore: true,
            loadsOnScroll: true,
        });
        expect(result.current.daySummaryOf('2026-10-01')).toEqual({
            count: 4,
            total: { status: 'ready', amount: -400 },
        });
    });

    it('asks the server again on a new filter and starts a new query', () => {
        const { result } = renderSource();

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                aiCategorizedOnly: true,
            }),
        );

        expect(visit).toHaveBeenCalledWith(
            '/transactions?category_source=ai',
            expect.objectContaining({ preserveState: true }),
        );
        expect(result.current.isLoading).toBe(true);
        expect(result.current.queryKey).toBe(1);
    });

    it('waits for a pause in typing before searching', () => {
        const { result } = renderSource();

        act(() =>
            result.current.onFiltersChange({
                ...result.current.filters,
                searchText: 'cof',
            }),
        );
        expect(visit).not.toHaveBeenCalled();

        act(() => vi.advanceTimersByTime(300));

        expect(visit).toHaveBeenCalledOnce();
        expect(visit.mock.calls[0][0]).toBe('/transactions?search=cof');
    });

    it('offers every facet there is', () => {
        expect(renderSource().result.current.facets).toEqual(FILTER_FACETS);
    });

    describe('a page that lands after the list moved on', () => {
        function slowPage() {
            let land: (response: unknown) => void = () => {};
            const fetchMock = vi.fn(
                () =>
                    new Promise((resolve) => {
                        land = resolve;
                    }),
            );
            vi.stubGlobal('fetch', fetchMock);

            return {
                fetchMock,
                landWith: (id: string) =>
                    land({
                        json: async () => ({
                            props: {
                                transactions: page([row(id)], 'stale-cursor'),
                                dayTotals: {},
                            },
                        }),
                    }),
            };
        }

        beforeEach(() => vi.useRealTimers());
        afterEach(() => vi.unstubAllGlobals());

        it('is discarded when the filters changed, rows and cursor alike', async () => {
            const { fetchMock, landWith } = slowPage();
            const { result } = renderSource();

            let pending: unknown;
            act(() => {
                pending = result.current.pagination.loadMore();
            });

            const signal = (
                fetchMock.mock.calls[0] as unknown as [
                    string,
                    { signal: AbortSignal },
                ]
            )[1].signal;

            act(() =>
                result.current.onFiltersChange({
                    ...result.current.filters,
                    categoryIds: ['food'],
                }),
            );

            expect(signal.aborted).toBe(true);
            expect(result.current.pagination.isLoadingMore).toBe(false);

            const { onSuccess } = visit.mock.calls[0][1];
            act(() =>
                onSuccess({
                    props: {
                        transactions: page([row('b')], 'b-cursor'),
                        dayTotals: {},
                    },
                }),
            );

            await act(async () => {
                landWith('a-next');
                await pending;
            });

            expect(result.current.rows.map((loaded) => loaded.id)).toEqual([
                'b',
            ]);

            fetchMock.mockClear();
            act(() => {
                void result.current.pagination.loadMore();
            });

            expect((fetchMock.mock.calls[0] as unknown[])[0]).toBe(
                '/transactions?category_ids=food&cursor=b-cursor',
            );
        });

        it('still lands when nothing changed', async () => {
            const { landWith } = slowPage();
            const { result } = renderSource();

            let pending: unknown;
            act(() => {
                pending = result.current.pagination.loadMore();
            });

            await act(async () => {
                landWith('next');
                await pending;
            });

            expect(result.current.rows.map((loaded) => loaded.id)).toEqual([
                'a',
                'next',
            ]);
        });
    });

    describe('refreshing the filter summary after a local edit', () => {
        const FILTERED = { category_ids: ['food'] };

        it('asks once for the summary alone, however many edits come', () => {
            const { result } = renderSource(null, FILTERED);

            act(() => {
                result.current.refreshSummary();
                result.current.refreshSummary();
                result.current.refreshSummary();
            });
            expect(reload).not.toHaveBeenCalled();

            act(() => vi.advanceTimersByTime(499));
            expect(reload).not.toHaveBeenCalled();

            act(() => vi.advanceTimersByTime(1));

            expect(reload).toHaveBeenCalledOnce();
            expect(reload).toHaveBeenCalledWith(
                expect.objectContaining({
                    only: ['filterSummary'],
                    async: true,
                }),
            );
        });

        it('asks nothing of an unfiltered list, which has no summary', () => {
            const { result } = renderSource();

            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(500));

            expect(reload).not.toHaveBeenCalled();
        });

        it('leaves it to a change of filters, cancelling one under way', () => {
            const { result } = renderSource(null, FILTERED);
            const cancel = vi.fn();

            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(500));
            act(() => reload.mock.calls[0][0].onCancelToken({ cancel }));
            act(() => result.current.refreshSummary());
            expect(cancel).toHaveBeenCalledOnce();

            act(() =>
                result.current.onFiltersChange({
                    ...result.current.filters,
                    categoryIds: ['home'],
                }),
            );
            act(() => vi.advanceTimersByTime(500));

            expect(reload).toHaveBeenCalledOnce();
        });

        it('asks nothing while a visit is on its way, which brings its own summary', () => {
            const { result } = renderSource(null, FILTERED);

            act(() =>
                result.current.onFiltersChange({
                    ...result.current.filters,
                    categoryIds: ['home'],
                }),
            );
            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(500));

            expect(reload).not.toHaveBeenCalled();

            act(() => visit.mock.calls[0][1].onFinish());
            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(500));

            expect(reload).toHaveBeenCalledOnce();
        });

        it('still counts as on its way when an earlier visit it replaced finishes', () => {
            const { result } = renderSource(null, FILTERED);
            const moveTo = (categoryIds: string[]) =>
                act(() =>
                    result.current.onFiltersChange({
                        ...result.current.filters,
                        categoryIds,
                    }),
                );

            moveTo(['home']);
            moveTo(['fuel']);
            act(() => visit.mock.calls[0][1].onFinish());
            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(500));

            expect(reload).not.toHaveBeenCalled();
        });

        it('drops a refresh armed before a debounced search visits', () => {
            const { result } = renderSource(null, FILTERED);

            act(() =>
                result.current.onFiltersChange({
                    ...result.current.filters,
                    searchText: 'cof',
                }),
            );
            act(() => vi.advanceTimersByTime(100));
            act(() => result.current.refreshSummary());
            act(() => vi.advanceTimersByTime(1000));

            expect(visit).toHaveBeenCalledOnce();
            expect(reload).not.toHaveBeenCalled();
        });

        it('forgets a pending refresh when the rows reload with their summary', () => {
            const { result } = renderSource(null, FILTERED);

            act(() => result.current.refreshSummary());
            act(() => result.current.reload());
            act(() => vi.advanceTimersByTime(500));

            expect(reload).toHaveBeenCalledOnce();
            expect(reload.mock.calls[0][0].only).toContain('transactions');
        });
    });

    it('appends the next page and keeps the figure of a day already shown', async () => {
        vi.useRealTimers();
        const fetchMock = vi.fn().mockResolvedValue({
            json: async () => ({
                props: {
                    transactions: page(
                        [row('b'), row('c', '2026-09-30')],
                        null,
                    ),
                    dayTotals: {
                        '2026-10-01': { count: 99, total: -9900 },
                        '2026-09-30': { count: 1, total: -100 },
                    },
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);
        const { result } = renderSource();

        await act(() => result.current.pagination.loadMore());

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/transactions?cursor=cursor-2',
        );
        expect(result.current.rows.map((loaded) => loaded.id)).toEqual([
            'a',
            'b',
            'c',
        ]);
        expect(result.current.pagination.hasMore).toBe(false);
        // The server's 4 already counted the row the next page brings; the
        // later page's 99 for the same day must not replace it.
        expect(result.current.daySummaryOf('2026-10-01').count).toBe(4);
        expect(result.current.daySummaryOf('2026-09-30').count).toBe(1);
        vi.unstubAllGlobals();
    });

    it('reloads only the rows, the day totals and the filter summary', () => {
        const { result } = renderSource();

        act(() => result.current.reload());

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({
                only: ['transactions', 'dayTotals', 'filterSummary'],
            }),
        );
    });

    it('counts the rows a filter set lists, without visiting', async () => {
        get.mockResolvedValue({ data: { count: 21 } });
        const { result } = renderSource();
        const signal = new AbortController().signal;

        await expect(
            result.current.countMatching(
                {
                    ...result.current.filters,
                    categoryIds: ['food', 'home'],
                    aiCategorizedOnly: true,
                },
                signal,
            ),
        ).resolves.toBe(21);

        expect(get).toHaveBeenCalledWith(
            '/api/transactions/listed-count?category_ids=food%2Chome&category_source=ai',
            { signal },
        );
        expect(visit).not.toHaveBeenCalled();
    });
});
