import { BreakdownCard } from '@/components/cashflow/breakdown-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { SavedInvestedCard } from '@/components/cashflow/saved-invested-card';
import { CashflowTrendChart, SankeyChart } from '@/components/charts';
import HeadingSmall from '@/components/heading-small';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CashflowPeriodType, useCashflowData } from '@/hooks/use-cashflow-data';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { getUserPeriodRange, userMonthStartDay } from '@/lib/user-periods';
import { cashflow } from '@/routes';
import { BreadcrumbItem, SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { format, getQuarter, parse } from 'date-fns';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Cashflow',
        href: cashflow().url,
    },
];

function parsePeriodParam(
    period: string | null,
    periodType: CashflowPeriodType,
    monthStartDay: number,
    fallback: Date,
): Date {
    if (!period) {
        return fallback;
    }

    try {
        if (periodType === 'quarter') {
            const match = /^(\d{4})-Q([1-4])$/.exec(period);

            if (match) {
                return new Date(
                    Number(match[1]),
                    (Number(match[2]) - 1) * 3,
                    monthStartDay,
                );
            }
        }

        if (periodType === 'year') {
            const match = /^(\d{4})$/.exec(period);

            if (match) {
                return new Date(Number(match[1]), 0, monthStartDay);
            }
        }

        const parsedDate = parse(period, 'yyyy-MM', fallback);

        if (!isNaN(parsedDate.getTime())) {
            return new Date(
                parsedDate.getFullYear(),
                parsedDate.getMonth(),
                monthStartDay,
            );
        }
    } catch {
        return fallback;
    }

    return fallback;
}

function formatPeriodParam(
    currentDate: Date,
    periodType: CashflowPeriodType,
): string {
    if (periodType === 'quarter') {
        return `${format(currentDate, 'yyyy')}-Q${getQuarter(currentDate)}`;
    }

    if (periodType === 'year') {
        return format(currentDate, 'yyyy');
    }

    return format(currentDate, 'yyyy-MM');
}

export default function CashflowPage() {
    const {
        auth,
        features,
        period: initialPeriod,
        periodType: initialPeriodType,
        today,
    } = usePage<
        SharedData & {
            period: string | null;
            periodType: CashflowPeriodType;
            today: string;
        }
    >().props;

    const [periodType, setPeriodType] =
        useState<CashflowPeriodType>(initialPeriodType);
    // Only honour the custom start day while the feature is active so the
    // client matches the server, which reverts to calendar months otherwise.
    const monthStartDay = features.customMonthStartDay
        ? userMonthStartDay(auth.user.month_start_day)
        : 1;
    const referenceDate = today
        ? parse(today, 'yyyy-MM-dd', new Date())
        : new Date();

    const [currentDate, setCurrentDate] = useState<Date>(() =>
        parsePeriodParam(
            initialPeriod,
            initialPeriodType,
            monthStartDay,
            referenceDate,
        ),
    );
    const userPeriod = getUserPeriodRange(
        currentDate,
        periodType,
        monthStartDay,
    );
    const period = {
        from: userPeriod.from,
        to: userPeriod.endInclusive,
    };
    const periodParam = formatPeriodParam(userPeriod.from, periodType);

    const {
        summary,
        sankey,
        trend,
        incomeBreakdown,
        expenseBreakdown,
        isLoading,
    } = useCashflowData({
        ...period,
        periodType,
    });

    useEffect(() => {
        if (initialPeriod !== periodParam || initialPeriodType !== periodType) {
            router.visit(
                cashflow({
                    query: { period: periodParam, period_type: periodType },
                }).url,
                {
                    preserveScroll: true,
                    preserveState: true,
                    replace: true,
                },
            );
        }
    }, [initialPeriod, initialPeriodType, periodParam, periodType]);

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Cashflow')} />

            <div className="space-y-6 p-6">
                <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <HeadingSmall
                        title={__('Cashflow')}
                        description={__(
                            'Track your income, expenses, and savings',
                        )}
                    />

                    <PeriodNavigation
                        currentDate={currentDate}
                        periodType={periodType}
                        monthStartDay={monthStartDay}
                        referenceDate={referenceDate}
                        onDateChange={setCurrentDate}
                        onPeriodTypeChange={setPeriodType}
                    />
                </div>

                {/* Summary Cards */}
                <div className="grid gap-6 md:grid-cols-2">
                    <NetCashflowCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                    />

                    <SavedInvestedCard
                        current={summary.current}
                        previous={summary.previous}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                    />
                </div>

                {/* Trend Chart */}
                <CashflowTrendChart
                    data={trend}
                    loading={isLoading}
                    currency={auth.user.currency_code}
                    periodType={periodType}
                />

                {/* Sankey Diagram */}
                <Card>
                    <CardHeader className="pb-4">
                        <CardTitle className="text-base">
                            {__('Money Flow')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {isLoading ? (
                            <div className="h-[400px] animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                        ) : (
                            <SankeyChart
                                data={sankey}
                                height={400}
                                currency={auth.user.currency_code}
                                period={period}
                            />
                        )}
                    </CardContent>
                </Card>

                {/* Breakdown Cards */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <BreakdownCard
                        type="income"
                        data={incomeBreakdown}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                        period={period}
                    />

                    <BreakdownCard
                        type="expense"
                        data={expenseBreakdown}
                        loading={isLoading}
                        currency={auth.user.currency_code}
                        period={period}
                    />
                </div>
            </div>
        </AppSidebarLayout>
    );
}
