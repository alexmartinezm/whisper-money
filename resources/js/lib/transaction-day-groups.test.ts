import { type Category } from '@/types/category';
import { type Transaction, type TransactionSplit } from '@/types/transaction';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    createRowTotalAmount,
    formatDayBand,
    formatDayHeading,
    formatTransactionCount,
    isSortedByDate,
    mergeNextPage,
    reconcileDaySummary,
    sumDayTotal,
    summarizeDay,
    sumPerCurrency,
    type DatedAmount,
} from './transaction-day-groups';

function transaction(amount: number, currency_code = 'EUR'): Transaction {
    return { amount, currency_code } as Transaction;
}

describe('isSortedByDate', () => {
    it('groups while the table is sorted by date, either way', () => {
        expect(isSortedByDate([{ id: 'transaction_date', desc: true }])).toBe(
            true,
        );
        expect(isSortedByDate([{ id: 'transaction_date', desc: false }])).toBe(
            true,
        );
    });

    it('does not group under any other sort', () => {
        expect(isSortedByDate([{ id: 'amount', desc: true }])).toBe(false);
        expect(isSortedByDate([])).toBe(false);
    });
});

describe('formatDayBand', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-26T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('puts the day and a short date on one line', () => {
        expect(formatDayBand('2026-10-26', 'es-ES')).toBe('Today · 26 oct');
        expect(formatDayBand('2026-10-24', 'es-ES')).toBe('sábado · 24 oct');
        expect(formatDayBand('2025-12-31', 'en-US')).toBe(
            'Wednesday · Dec 31, 2025',
        );
    });
});

describe('formatDayHeading', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-26T12:00:00'));
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('names today and yesterday, with the full date beside them', () => {
        expect(formatDayHeading('2026-10-26', 'en-US')).toEqual({
            name: 'Today',
            date: 'Monday, October 26',
        });
        expect(formatDayHeading('2026-10-25', 'es-ES')).toEqual({
            name: 'Yesterday',
            date: 'domingo, 25 de octubre',
        });
    });

    it('names any other day by its weekday', () => {
        expect(formatDayHeading('2026-10-21', 'es-ES')).toEqual({
            name: 'Miércoles',
            date: '21 de octubre',
        });
    });

    it('adds the year outside the current one', () => {
        expect(formatDayHeading('2025-12-31', 'en-US')).toEqual({
            name: 'Wednesday',
            date: 'December 31, 2025',
        });
    });
});

describe('formatTransactionCount', () => {
    it('says one transaction in the singular', () => {
        expect(formatTransactionCount(1)).toBe('1 transaction');
        expect(formatTransactionCount(3)).toBe('3 transactions');
    });
});

describe('sumDayTotal', () => {
    const asIs = (t: DatedAmount) => t.amount;

    it('adds the day up, income and expenses alike', () => {
        expect(
            sumDayTotal(
                [transaction(-1250), transaction(-750), transaction(5000)],
                asIs,
            ),
        ).toEqual({ status: 'ready', amount: 3000 });
    });

    it('adds converted amounts for rows in another currency', () => {
        const converted = (t: DatedAmount) =>
            t.currency_code === 'USD' ? t.amount / 2 : t.amount;

        expect(
            sumDayTotal(
                [transaction(-1000), transaction(-4000, 'USD')],
                converted,
            ),
        ).toEqual({ status: 'ready', amount: -3000 });
    });

    it('waits while a conversion is still on its way', () => {
        expect(
            sumDayTotal([transaction(-1000), transaction(-4000, 'USD')], (t) =>
                t.currency_code === 'USD' ? undefined : t.amount,
            ),
        ).toEqual({ status: 'pending' });
    });

    it('leaves the total out when one amount has no rate', () => {
        expect(
            sumDayTotal(
                [transaction(-1000), transaction(-4000, 'USD'), transaction(1)],
                (t) => (t.currency_code === 'USD' ? null : t.amount),
            ),
        ).toEqual({ status: 'missing' });
    });
});

describe('summarizeDay', () => {
    function dated(
        amount: number,
        currency_code: string,
        transaction_date = '2026-05-18',
    ): Transaction {
        return { amount, currency_code, transaction_date } as Transaction;
    }

    it('adds each currency up before converting it once, as the server does', () => {
        // At 3 dollars a euro, each -10 USD rounds to -333 cents on its own
        // (-999 in all) while their -30 USD sum is exactly -1000 cents.
        const converted = vi.fn((amount: DatedAmount) =>
            amount.currency_code === 'USD'
                ? Math.round(amount.amount / 3)
                : amount.amount,
        );

        const summary = summarizeDay(
            [
                dated(-1000, 'USD'),
                dated(-1000, 'usd'),
                dated(-1000, 'USD'),
                dated(-500, 'EUR'),
            ],
            converted,
        );

        expect(summary).toEqual({
            count: 4,
            total: { status: 'ready', amount: -1500 },
        });
        expect(converted).toHaveBeenCalledWith({
            amount: -3000,
            currency_code: 'USD',
            transaction_date: '2026-05-18',
        });
        expect(converted).toHaveBeenCalledTimes(2);
    });
});

describe('sumPerCurrency', () => {
    it('gives one amount per currency, whatever its case', () => {
        expect(
            sumPerCurrency([
                { amount: -100, currency_code: 'usd', transaction_date: 'd' },
                { amount: -200, currency_code: 'EUR', transaction_date: 'd' },
                { amount: -50, currency_code: 'USD', transaction_date: 'd' },
            ] as Transaction[]),
        ).toEqual([
            { amount: -150, currency_code: 'USD', transaction_date: 'd' },
            { amount: -200, currency_code: 'EUR', transaction_date: 'd' },
        ]);
    });
});

describe('reconcileDaySummary', () => {
    function row(
        id: string,
        amount: number,
        currency_code = 'EUR',
    ): Transaction {
        return { id, amount, currency_code } as Transaction;
    }

    const served = [row('a', -1000), row('b', -500, 'USD')];

    it('keeps the server figure while the page changed nothing', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [...served],
                'EUR',
            ),
        ).toEqual({ count: 9, total: { status: 'ready', amount: -12000 } });
    });

    it('moves the figure by a deleted or edited row in the user currency', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('b', -500, 'USD')],
                'EUR',
            ),
        ).toEqual({ count: 8, total: { status: 'ready', amount: -11000 } });

        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('a', -2500), row('b', -500, 'USD')],
                'EUR',
            ),
        ).toEqual({ count: 9, total: { status: 'ready', amount: -13500 } });
    });

    it('counts a day the server never saw from the rows moved into it', () => {
        expect(
            reconcileDaySummary(undefined, [], [row('c', 700)], 'EUR'),
        ).toEqual({ count: 1, total: { status: 'ready', amount: 700 } });
    });

    it('waits for the figure of a day served without one', () => {
        expect(reconcileDaySummary(undefined, served, served, 'EUR')).toEqual({
            count: 2,
            total: { status: 'pending' },
        });
    });

    it('leaves the total out when a changed row needs a rate', () => {
        expect(
            reconcileDaySummary(
                { count: 9, total: -12000 },
                served,
                [row('a', -1000)],
                'EUR',
            ).total,
        ).toEqual({ status: 'missing' });
    });

    it('leaves the total out when the server had no rate for the day', () => {
        expect(
            reconcileDaySummary(
                { count: 2, total: null },
                served,
                served,
                'EUR',
            ),
        ).toEqual({ count: 2, total: { status: 'missing' } });
    });
});

describe('createRowTotalAmount', () => {
    const food = { id: 'food', parent_id: null } as unknown as Category;
    const groceries = {
        id: 'groceries',
        parent_id: 'food',
    } as unknown as Category;
    const home = { id: 'home', parent_id: null } as unknown as Category;
    const categories = [food, groceries, home];
    const lines = [
        { category_id: 'groceries', amount: -3000 },
        { category_id: 'home', amount: -7000 },
    ] as TransactionSplit[];
    const split = {
        id: 'split',
        amount: -10000,
        currency_code: 'EUR',
        splits: lines,
        label_ids: ['trip'],
    } as unknown as Transaction;
    const unsplit = {
        id: 'unsplit',
        amount: -500,
        currency_code: 'EUR',
    } as unknown as Transaction;

    it('leaves every row whole without a filter that narrows lines', () => {
        const rowTotal = createRowTotalAmount(
            { categoryIds: [], labelIds: [] },
            categories,
        );

        expect(rowTotal(split)).toBe(-10000);
        expect(rowTotal(unsplit)).toBe(-500);
    });

    it('adds a split transaction lines in the wanted categories and their subtrees', () => {
        const rowTotal = createRowTotalAmount(
            { categoryIds: ['food'], labelIds: [] },
            categories,
        );

        expect(rowTotal(split)).toBe(-3000);
        expect(rowTotal(unsplit)).toBe(-500);
    });

    it('keeps every line of a split transaction carrying a wanted label', () => {
        const rowTotal = createRowTotalAmount(
            { categoryIds: ['food'], labelIds: ['trip'] },
            categories,
        );

        expect(rowTotal(split)).toBe(-10000);
    });

    it('adds none of a split transaction listed for another reason', () => {
        const rowTotal = createRowTotalAmount(
            { categoryIds: [], labelIds: ['other'] },
            categories,
        );

        expect(rowTotal(split)).toBe(0);
    });

    it('cannot value a split transaction under a cashflow block', () => {
        const rowTotal = createRowTotalAmount(
            { categoryIds: [], labelIds: [], cashflowBlock: 'fixed' },
            categories,
        );

        expect(rowTotal(split)).toBeNull();
        expect(rowTotal(unsplit)).toBe(-500);
    });
});

describe('reconcileDaySummary with split transactions', () => {
    const lines = [
        { category_id: 'food', amount: -3000 },
        { category_id: 'home', amount: -7000 },
    ] as TransactionSplit[];
    const split = {
        id: 'split',
        amount: -10000,
        currency_code: 'EUR',
        splits: lines,
    } as unknown as Transaction;
    const filtered = createRowTotalAmount(
        { categoryIds: ['food'], labelIds: [] },
        [],
    );

    it('takes only the matching lines off the day when the row goes', () => {
        expect(
            reconcileDaySummary(
                { count: 1, total: -3000 },
                [split],
                [],
                'EUR',
                filtered,
            ),
        ).toEqual({ count: 0, total: { status: 'ready', amount: 0 } });
    });

    it('moves the day by a changed line', () => {
        const edited = {
            ...split,
            amount: -9000,
            splits: [{ category_id: 'food', amount: -2000 }, lines[1]],
        } as Transaction;

        expect(
            reconcileDaySummary(
                { count: 1, total: -3000 },
                [split],
                [edited],
                'EUR',
                filtered,
            ),
        ).toEqual({ count: 1, total: { status: 'ready', amount: -2000 } });
    });

    it('leaves the total out when the page cannot value a changed row', () => {
        const underBlock = createRowTotalAmount(
            { categoryIds: [], labelIds: [], cashflowBlock: 'fixed' },
            [],
        );

        expect(
            reconcileDaySummary(
                { count: 1, total: -3000 },
                [split],
                [],
                'EUR',
                underBlock,
            ),
        ).toEqual({ count: 0, total: { status: 'missing' } });
    });

    it('leaves the server figure alone while nothing changed, under a block too', () => {
        const underBlock = createRowTotalAmount(
            { categoryIds: [], labelIds: [], cashflowBlock: 'fixed' },
            [],
        );

        expect(
            reconcileDaySummary(
                { count: 1, total: -3000 },
                [split],
                [split],
                'EUR',
                underBlock,
            ),
        ).toEqual({ count: 1, total: { status: 'ready', amount: -3000 } });
    });
});

describe('mergeNextPage', () => {
    function row(id: string, date: string, amount = -100): Transaction {
        return {
            id,
            amount,
            currency_code: 'EUR',
            transaction_date: date,
        } as Transaction;
    }

    it('keeps the figure a day came with, the page edits being measured against it', () => {
        const served = {
            transactions: [row('a', '2026-10-26')],
            dayTotals: { '2026-10-26': { count: 3, total: -300 } },
        };

        const merged = mergeNextPage(
            served,
            {
                transactions: [row('b', '2026-10-26'), row('c', '2026-10-25')],
                dayTotals: {
                    '2026-10-26': { count: 3, total: -999 },
                    '2026-10-25': { count: 1, total: -100 },
                },
            },
            served.transactions,
        );

        expect(merged.dayTotals).toEqual({
            '2026-10-26': { count: 3, total: -300 },
            '2026-10-25': { count: 1, total: -100 },
        });
        expect(merged.transactions.map(({ id }) => id)).toEqual([
            'a',
            'b',
            'c',
        ]);
    });

    it('takes a later total for a day that came without one', () => {
        const merged = mergeNextPage(
            {
                transactions: [row('a', '2026-10-26')],
                dayTotals: { '2026-10-26': { count: 2, total: null } },
            },
            {
                transactions: [row('b', '2026-10-26')],
                dayTotals: { '2026-10-26': { count: 2, total: -200 } },
            },
            [row('a', '2026-10-26')],
        );

        expect(merged.dayTotals['2026-10-26'].total).toBe(-200);
    });

    it('does not count again an edit the later total already holds', () => {
        const edited = row('a', '2026-10-26', -150);
        const next = row('b', '2026-10-26');

        const merged = mergeNextPage(
            {
                transactions: [row('a', '2026-10-26')],
                dayTotals: { '2026-10-26': { count: 2, total: null } },
            },
            {
                transactions: [next],
                dayTotals: { '2026-10-26': { count: 2, total: -250 } },
            },
            [edited],
        );

        expect(
            reconcileDaySummary(
                merged.dayTotals['2026-10-26'],
                merged.transactions,
                [edited, next],
                'EUR',
            ),
        ).toEqual({ count: 2, total: { status: 'ready', amount: -250 } });
    });

    it('never lets a later empty total replace a figure', () => {
        const merged = mergeNextPage(
            {
                transactions: [],
                dayTotals: { '2026-10-26': { count: 2, total: -200 } },
            },
            {
                transactions: [],
                dayTotals: { '2026-10-26': { count: 2, total: null } },
            },
            [],
        );

        expect(merged.dayTotals['2026-10-26'].total).toBe(-200);
    });
});
