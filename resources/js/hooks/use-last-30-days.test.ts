import { renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { useLast30Days } from './use-last-30-days';

const usePage = vi.hoisted(() => vi.fn());

vi.mock('@inertiajs/react', () => ({ usePage }));

describe('useLast30Days', () => {
    beforeEach(() => {
        // The browser's clock says November; the server picked the user's days.
        vi.setSystemTime(new Date('2026-11-01T00:30:00Z'));
        usePage.mockReturnValue({
            props: { spendingWindow: { from: '2026-10-02', to: '2026-10-31' } },
        });
    });

    it('reads the window the server summed the cards over', () => {
        const { result } = renderHook(() => useLast30Days());

        expect(result.current).toEqual({
            dateFrom: '2026-10-02',
            dateTo: '2026-10-31',
        });
    });
});
