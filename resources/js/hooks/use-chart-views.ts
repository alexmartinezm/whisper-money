import {
    AccountInfo,
    ChangeDataPoint,
    computeDeltaSeries,
    computeMoMPercent,
    computeNetWorthSeries,
    MonthDataPoint,
    NetWorthSeriesOptions,
    PercentDataPoint,
} from '@/lib/chart-calculations';
import { useCallback, useMemo, useState } from 'react';

export type ChartViewType = 'stacked' | 'mom' | 'mom_percent';

export interface UseChartViewsOptions {
    /** Raw monthly data with account balances */
    data: Array<Record<string, string | number>>;
    /** Account information keyed by account ID */
    accounts: Record<string, AccountInfo>;
    /** Initial view type */
    initialView?: ChartViewType;
    /** Whether to show stacked view option (false for single account charts) */
    hasStackedView?: boolean;
    /** Options for computing net worth series */
    netWorthOptions?: NetWorthSeriesOptions;
}

export interface UseChartViewsReturn {
    // View state
    currentView: ChartViewType;
    setCurrentView: (view: ChartViewType) => void;
    availableViews: ChartViewType[];

    // Computed series
    netWorthSeries: MonthDataPoint[];
    deltaSeries: ChangeDataPoint[];
    momPercentSeries: PercentDataPoint[];
}

/**
 * Hook for managing chart view state and computed series
 * Shared between net-worth-chart and account-balance-chart
 */
export function useChartViews({
    data,
    accounts,
    initialView = 'stacked',
    hasStackedView = true,
    netWorthOptions,
}: UseChartViewsOptions): UseChartViewsReturn {
    // View state
    const [currentView, setCurrentViewState] = useState<ChartViewType>(
        hasStackedView
            ? initialView
            : initialView === 'stacked'
              ? 'mom'
              : initialView,
    );

    // Available views based on chart type
    const availableViews = useMemo<ChartViewType[]>(() => {
        if (hasStackedView) {
            return ['stacked', 'mom', 'mom_percent'];
        }
        return ['mom', 'mom_percent'];
    }, [hasStackedView]);

    // Set current view with validation
    const setCurrentView = useCallback(
        (view: ChartViewType) => {
            if (availableViews.includes(view)) {
                setCurrentViewState(view);
            }
        },
        [availableViews],
    );

    // Compute all chart series in a single memoized block
    // The first month is needed for MoM calculations but shouldn't be displayed,
    // so we slice(1) to show only 12 bars
    const { netWorthSeries, deltaSeries, momPercentSeries } = useMemo(() => {
        const fullNetWorth = computeNetWorthSeries(
            data,
            accounts,
            netWorthOptions,
        );
        const fullDelta = computeDeltaSeries(fullNetWorth);
        const fullMomPercent = computeMoMPercent(fullNetWorth);

        return {
            netWorthSeries: fullNetWorth.slice(1),
            deltaSeries: fullDelta.slice(1),
            momPercentSeries: fullMomPercent.slice(1),
        };
    }, [data, accounts, netWorthOptions]);

    return {
        // View state
        currentView,
        setCurrentView,
        availableViews,

        // Computed series (with first month removed)
        netWorthSeries,
        deltaSeries,
        momPercentSeries,
    };
}

/**
 * A single account's own chart always counts that account. The loan and real
 * estate switches belong to the net worth chart and start off for a new user,
 * so without this a loan's or a flat's month-over-month views would be flat.
 */
const SINGLE_ACCOUNT_SERIES_OPTIONS: NetWorthSeriesOptions = {
    includeLoanAccounts: true,
    includeRealEstateAccounts: true,
};

/**
 * Convert single-account balance data to the format expected by useChartViews
 * For use in account-balance-chart
 */
export function convertSingleAccountData(
    balanceData: Array<{ month: string; timestamp?: number; value: number }>,
    accountId: string,
    accountType: string,
    currencyCode: string,
): {
    data: Array<Record<string, string | number>>;
    accounts: Record<string, AccountInfo>;
    netWorthOptions: NetWorthSeriesOptions;
} {
    const data = balanceData.map((point) => ({
        month: point.month,
        timestamp: point.timestamp ?? 0,
        [accountId]: point.value,
    }));

    const accounts: Record<string, AccountInfo> = {
        [accountId]: {
            id: accountId,
            type: accountType as AccountInfo['type'],
            currency_code: currencyCode,
        },
    };

    return { data, accounts, netWorthOptions: SINGLE_ACCOUNT_SERIES_OPTIONS };
}
