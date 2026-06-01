import {
    AnalysisBreakdown,
    type ResolvedRow,
} from '@/components/analysis/analysis-breakdown';
import {
    AnalysisChart,
    type AnalysisChartPoint,
} from '@/components/analysis/analysis-chart';
import { AnalysisSummaryCards } from '@/components/analysis/analysis-summary-cards';
import HeadingSmall from '@/components/heading-small';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import { Button } from '@/components/ui/button';
import {
    ANALYSIS_DIMENSIONS,
    type AnalysisDimension,
    useAnalysisData,
} from '@/hooks/use-analysis-data';
import { useLocale } from '@/hooks/use-locale';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { cn } from '@/lib/utils';
import { analysis } from '@/routes';
import { type BreadcrumbItem } from '@/types';
import { type Account } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { formatMonthName } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import { endOfMonth, parseISO, startOfMonth } from 'date-fns';
import { useMemo, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Analysis',
        href: analysis().url,
    },
];

const emptyFilters: TransactionFilters = {
    dateFrom: null,
    dateTo: null,
    amountMin: null,
    amountMax: null,
    categoryIds: [],
    accountIds: [],
    labelIds: [],
    creditorName: '',
    debtorName: '',
    searchText: '',
};

const dimensionLabels: Record<AnalysisDimension, string> = {
    category: __('Category'),
    month: __('Month'),
    account: __('Account'),
    label: __('Label'),
};

const isTimeDimension = (dimension: AnalysisDimension): boolean =>
    dimension === 'month';

interface Props {
    categories: Category[];
    accounts: Account[];
    labels: Label[];
}

export default function AnalysisPage({ categories, accounts, labels }: Props) {
    const locale = useLocale();
    const { auth } = usePage<{
        auth: { user: { currency_code: string } };
    }>().props;
    const currency = auth.user.currency_code;

    const [filters, setFilters] = useState<TransactionFilters>(emptyFilters);
    const [dimension, setDimension] = useState<AnalysisDimension>('category');

    const { summary, groups, isLoading } = useAnalysisData(filters, dimension);

    const categoriesById = useMemo(
        () => new Map(categories.map((category) => [category.id, category])),
        [categories],
    );
    const accountsById = useMemo(
        () => new Map(accounts.map((account) => [account.id, account])),
        [accounts],
    );
    const labelsById = useMemo(
        () => new Map(labels.map((label) => [label.id, label])),
        [labels],
    );

    const resolveLabel = (key: string | null): string => {
        switch (dimension) {
            case 'category':
                return key
                    ? (categoriesById.get(key)?.name ?? __('Uncategorized'))
                    : __('Uncategorized');
            case 'account':
                return key
                    ? (accountsById.get(key)?.name ?? __('Unknown account'))
                    : __('No account');
            case 'label':
                return key
                    ? (labelsById.get(key)?.name ?? __('Unknown label'))
                    : __('No label');
            case 'month':
                // Aggregated across years, so the key is a month number (01-12).
                // `groups` can briefly hold the previous dimension's keys while a
                // refetch is in flight, so only format well-formed month numbers.
                return key && /^(0[1-9]|1[0-2])$/.test(key)
                    ? formatMonthName(key, locale)
                    : '';
        }
    };

    const rows: ResolvedRow[] = useMemo(
        () =>
            groups.map((group) => ({
                key: group.key,
                label: resolveLabel(group.key),
                amount: group.amount,
                count: group.count,
                category: group.key ? categoriesById.get(group.key) : undefined,
                account:
                    dimension === 'account' && group.key
                        ? accountsById.get(group.key)
                        : undefined,
                labelColor:
                    dimension === 'label' && group.key
                        ? labelsById.get(group.key)?.color
                        : undefined,
            })),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [groups, dimension, categoriesById, accountsById, labelsById, locale],
    );

    // Months read most naturally in calendar order (Jan → Dec) in the list.
    const breakdownRows = useMemo(() => {
        if (!isTimeDimension(dimension)) {
            return rows;
        }
        return [...rows].sort((a, b) =>
            (a.key ?? '').localeCompare(b.key ?? ''),
        );
    }, [rows, dimension]);

    // The chart shows time ascending; categorical dimensions show the top buckets.
    const chartData: AnalysisChartPoint[] = useMemo(() => {
        const points = rows.map((row) => ({
            key: row.key ?? '__null__',
            label: row.label,
            amount: row.amount,
        }));

        if (isTimeDimension(dimension)) {
            return points.sort((a, b) => a.key.localeCompare(b.key));
        }

        return points.slice(0, 12);
    }, [rows, dimension]);

    function handleDrill(row: ResolvedRow) {
        switch (dimension) {
            case 'category':
                setFilters((previous) => ({
                    ...previous,
                    categoryIds: [row.key ?? 'uncategorized'],
                }));
                setDimension('month');
                break;
            case 'account':
                if (!row.key) {
                    return;
                }
                setFilters((previous) => ({
                    ...previous,
                    accountIds: previous.accountIds.includes(row.key!)
                        ? previous.accountIds
                        : [...previous.accountIds, row.key!],
                }));
                setDimension('category');
                break;
            case 'label':
                if (!row.key) {
                    return;
                }
                setFilters((previous) => ({
                    ...previous,
                    labelIds: previous.labelIds.includes(row.key!)
                        ? previous.labelIds
                        : [...previous.labelIds, row.key!],
                }));
                setDimension('category');
                break;
            case 'month':
                if (!row.key) {
                    return;
                }
                setFilters((previous) => ({
                    ...previous,
                    dateFrom: startOfMonth(parseISO(`${row.key}-01`)),
                    dateTo: endOfMonth(parseISO(`${row.key}-01`)),
                }));
                setDimension('category');
                break;
        }
    }

    const breakdownTitle = __('Breakdown by :dimension', {
        dimension: dimensionLabels[dimension].toLowerCase(),
    });

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Analysis')} />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title={__('Analysis')}
                    description={__(
                        'Drill into your income and expenses across any dimension',
                    )}
                />

                <TransactionFiltersComponent
                    filters={filters}
                    onFiltersChange={setFilters}
                    categories={categories}
                    labels={labels}
                    accounts={accounts}
                    isKeySet={true}
                    hideSearch
                    inlineCategoryLabel
                />

                <AnalysisSummaryCards
                    summary={summary}
                    currency={currency}
                    loading={isLoading}
                />

                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm text-muted-foreground">
                        {__('Group by')}
                    </span>
                    {ANALYSIS_DIMENSIONS.map((option) => (
                        <Button
                            key={option}
                            size="sm"
                            variant={
                                dimension === option ? 'default' : 'outline'
                            }
                            onClick={() => setDimension(option)}
                            className={cn(dimension === option && 'shadow-sm')}
                        >
                            {dimensionLabels[option]}
                        </Button>
                    ))}
                </div>

                <AnalysisChart
                    title={breakdownTitle}
                    data={chartData}
                    currency={currency}
                    loading={isLoading}
                    scrollable={dimension === 'month'}
                />

                <AnalysisBreakdown
                    title={breakdownTitle}
                    rows={breakdownRows}
                    currency={currency}
                    loading={isLoading}
                    onDrill={handleDrill}
                />
            </div>
        </AppSidebarLayout>
    );
}
