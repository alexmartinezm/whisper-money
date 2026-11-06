import { type Transaction } from '@/types/transaction';
import { renderHook, waitFor } from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useAmountsInUserCurrency } from './use-amounts-in-user-currency';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { currency_code: 'EUR' } } } }),
}));

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

const post = vi.mocked(axios.post);

function transaction(
    id: string,
    amount: number,
    currency_code: string,
    transaction_date = '2026-10-25',
): Transaction {
    return { id, amount, currency_code, transaction_date } as Transaction;
}

beforeEach(() => {
    post.mockReset();
});

describe('useAmountsInUserCurrency', () => {
    it('asks once more for an amount left without a rate, on the next list change', async () => {
        post.mockResolvedValueOnce({ data: { amounts: [null] } });
        post.mockResolvedValueOnce({ data: { amounts: [-500] } });
        const row = transaction('a', -1000, 'USD');

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: [row] } },
        );

        await waitFor(() => expect(result.current(row)).toBeNull());
        expect(post).toHaveBeenCalledTimes(1);

        rerender({ list: [row] });

        await waitFor(() => expect(result.current(row)).toBe(-500));
        expect(post).toHaveBeenCalledTimes(2);

        rerender({ list: [row] });
        expect(post).toHaveBeenCalledTimes(2);
    });

    it('gives up on an amount still without a rate after the second try', async () => {
        post.mockResolvedValue({ data: { amounts: [null] } });
        const row = transaction('a', -1000, 'USD');

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: [row] } },
        );

        await waitFor(() => expect(result.current(row)).toBeNull());
        rerender({ list: [row] });
        await waitFor(() => expect(post).toHaveBeenCalledTimes(2));
        await new Promise((resolve) => setTimeout(resolve));

        rerender({ list: [row] });
        await new Promise((resolve) => setTimeout(resolve));

        expect(post).toHaveBeenCalledTimes(2);
    });

    it('asks nothing more on its own after an empty answer, while the list stands', async () => {
        post.mockResolvedValue({ data: { amounts: [null] } });
        const list = [transaction('a', -1000, 'USD')];

        const { result, rerender } = renderHook(() =>
            useAmountsInUserCurrency(list),
        );

        await waitFor(() => expect(result.current(list[0])).toBeNull());
        rerender();
        await new Promise((resolve) => setTimeout(resolve));

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('keeps rows in the user currency in the browser', () => {
        const rows = [
            transaction('a', -1000, 'EUR'),
            transaction('b', 5, 'eur'),
        ];
        const { result } = renderHook(() => useAmountsInUserCurrency(rows));

        expect(result.current(rows[0])).toBe(-1000);
        expect(result.current(rows[1])).toBe(5);
        expect(post).not.toHaveBeenCalled();
    });

    it('asks for every foreign row in one request and keeps the answer', async () => {
        post.mockResolvedValue({ data: { amounts: [-500, null] } });
        const rows = [
            transaction('a', -1000, 'EUR'),
            transaction('b', -1000, 'USD'),
            transaction('c', -2000, 'GBP', '2026-10-24'),
        ];

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: rows } },
        );

        expect(result.current(rows[1])).toBeUndefined();
        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][1]).toEqual({
            items: [
                { from: 'USD', amount: -1000, date: '2026-10-25' },
                { from: 'GBP', amount: -2000, date: '2026-10-24' },
            ],
        });

        await waitFor(() => expect(result.current(rows[1])).toBe(-500));
        expect(result.current(rows[2])).toBeNull();

        // A new list holding the same rows leaves the converted one alone and
        // gives the one without a rate its second try.
        rerender({ list: [...rows] });
        expect(post).toHaveBeenCalledTimes(2);
        expect(post.mock.calls[1][1]).toEqual({
            items: [{ from: 'GBP', amount: -2000, date: '2026-10-24' }],
        });
    });

    it('treats a failed request as amounts it could not convert', async () => {
        post.mockRejectedValue(new Error('offline'));
        const rows = [transaction('b', -1000, 'USD')];

        const { result } = renderHook(() => useAmountsInUserCurrency(rows));

        await waitFor(() => expect(result.current(rows[0])).toBeNull());
    });

    it('asks again for rows whose request failed once the list changes', async () => {
        post.mockRejectedValueOnce(new Error('offline'));
        post.mockResolvedValueOnce({ data: { amounts: [-500] } });
        const rows = [transaction('b', -1000, 'USD')];

        const { result, rerender } = renderHook(
            ({ list }) => useAmountsInUserCurrency(list),
            { initialProps: { list: rows } },
        );

        await waitFor(() => expect(result.current(rows[0])).toBeNull());

        rerender({ list: [...rows] });

        await waitFor(() => expect(result.current(rows[0])).toBe(-500));
        expect(post).toHaveBeenCalledTimes(2);
    });
});
