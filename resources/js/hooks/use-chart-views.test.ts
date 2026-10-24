import { renderHook } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { convertSingleAccountData, useChartViews } from './use-chart-views';

describe('useChartViews for a single account', () => {
    const balances = [
        { month: '2025-01', value: 0 },
        { month: '2025-02', value: 200000 },
        { month: '2025-03', value: 210000 },
    ];

    it.each(['loan', 'real_estate'])(
        'counts a %s account in its own chart even though the net worth chart leaves it out by default',
        (type) => {
            const converted = convertSingleAccountData(
                balances,
                'account-1',
                type,
                'EUR',
            );

            const { result } = renderHook(() =>
                useChartViews({
                    data: converted.data,
                    accounts: converted.accounts,
                    netWorthOptions: converted.netWorthOptions,
                }),
            );

            expect(
                result.current.netWorthSeries.map((point) =>
                    Math.abs(point.value),
                ),
            ).toEqual([200000, 210000]);
        },
    );
});
