import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import type {
    Budget,
    BudgetStatus,
    BudgetSummary,
    BudgetSummaryGroup,
} from '@/types/budget';
import { getBudgetPeriodTypeLabel } from '@/types/budget';
import { getBudgetPeriodStats } from '@/utils/budget';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';

interface Props {
    budgets: Budget[];
    budgetSummary: BudgetSummary;
    currencyCode: string;
}

function statusClass(status: BudgetStatus): string {
    if (status === 'over_limit') return 'text-red-600 dark:text-red-400';
    if (status === 'close_to_limit') {
        return 'text-amber-700 dark:text-amber-300';
    }
    return 'text-green-600 dark:text-green-400';
}

function getGroupLabel(group: BudgetSummaryGroup): string {
    return group.period_type
        ? __(getBudgetPeriodTypeLabel(group.period_type))
        : __('All untracked expenses');
}

export function BudgetOverviewCard({
    budgets,
    budgetSummary,
    currencyCode,
}: Props) {
    const locale = useLocale();
    const activeBudgets = budgets.filter(
        (budget) => budget.periods?.[0] !== undefined,
    );
    const standardBudgets = activeBudgets.filter(
        (budget) => !budget.is_catch_all,
    );
    const catchAllBudgets = activeBudgets.filter(
        (budget) => budget.is_catch_all,
    );
    const hasMixedPeriods = budgetSummary.groups.length > 1;

    const renderBudgetRow = (budget: Budget) => {
        const currentPeriod = budget.periods?.[0];
        const stats = getBudgetPeriodStats(currentPeriod);
        const percentageUsed = stats.percentageUsed;
        const periodLabel = currentPeriod
            ? `${formatDate(currentPeriod.start_date, 'MMM d', locale)} - ${formatDate(currentPeriod.end_date, 'MMM d', locale)}`
            : __('No active period');

        return (
            <div key={budget.id} className="space-y-2 rounded-lg border p-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="min-w-0">
                        <Link
                            href={show({ budget: budget.id }).url}
                            className="font-medium underline-offset-4 hover:underline"
                        >
                            {budget.name}
                        </Link>
                        <p className="text-xs text-muted-foreground">
                            {periodLabel}
                        </p>
                    </div>
                    <div className="text-right text-sm">
                        <span className={statusClass(stats.status)}>
                            <AmountDisplay
                                amountInCents={stats.totalSpent}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />
                        </span>{' '}
                        <span className="text-muted-foreground">
                            {__('of')}{' '}
                            <AmountDisplay
                                amountInCents={stats.totalAvailable}
                                currencyCode={currencyCode}
                            />
                        </span>
                    </div>
                </div>
                <Progress
                    value={Math.min(Math.max(percentageUsed, 0), 100)}
                    className="h-2"
                    aria-label={`${__('Budget progress')}: ${budget.name}`}
                    aria-valuetext={`${percentageUsed.toFixed(1)}%`}
                />
                <div className="flex items-center justify-between gap-2 text-xs">
                    <span className="text-muted-foreground">
                        {__(getBudgetPeriodTypeLabel(budget.period_type))}
                    </span>
                    <span className={statusClass(stats.status)}>
                        {__('Remaining')}:{' '}
                        <AmountDisplay
                            amountInCents={stats.remaining}
                            currencyCode={currencyCode}
                            weight="medium"
                        />
                    </span>
                </div>
            </div>
        );
    };

    const renderGroupSummary = (group: BudgetSummaryGroup) => (
        <div
            key={group.period_type ?? 'catch-all'}
            className="rounded-lg border p-3"
        >
            <h3
                id={`budget-overview-group-${group.period_type ?? 'catch-all'}`}
                className="text-sm font-medium"
            >
                {getGroupLabel(group)}
            </h3>
            <div className="mt-2 flex items-baseline justify-between gap-2">
                <span className="text-muted-foreground">
                    <AmountDisplay
                        amountInCents={group.total_spent}
                        currencyCode={currencyCode}
                        weight="semibold"
                    />{' '}
                    {__('of')}{' '}
                    <AmountDisplay
                        amountInCents={group.total_available}
                        currencyCode={currencyCode}
                    />
                </span>
                <span className={statusClass(group.status)}>
                    {group.percentage_used.toFixed(1)}%
                </span>
            </div>
        </div>
    );

    return (
        <Card>
            <CardHeader className="gap-4">
                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle>
                            <h2>{__('Budget overview')}</h2>
                        </CardTitle>
                        <CardDescription>
                            {__('Active budget totals for the current period')}
                        </CardDescription>
                    </div>
                    <div className="flex flex-col items-start gap-1 sm:items-end">
                        <span className="text-xs font-medium text-muted-foreground">
                            {__('Specific budgets')}
                        </span>
                        <div
                            className="flex flex-wrap gap-2"
                            aria-live="polite"
                        >
                            {budgetSummary.over_limit_count > 0 && (
                                <Badge variant="destructive">
                                    <AlertTriangle className="mr-1 h-3 w-3" />
                                    {budgetSummary.over_limit_count}{' '}
                                    {__('over limit')}
                                </Badge>
                            )}
                            {budgetSummary.close_to_limit_count > 0 && (
                                <Badge
                                    variant="outline"
                                    className="text-amber-700 dark:text-amber-300"
                                >
                                    {budgetSummary.close_to_limit_count}{' '}
                                    {__('close to limit')}
                                </Badge>
                            )}
                            {budgetSummary.over_limit_count === 0 &&
                                budgetSummary.close_to_limit_count === 0 && (
                                    <Badge variant="secondary">
                                        <CheckCircle2 className="mr-1 h-3 w-3" />
                                        {__('On track')}
                                    </Badge>
                                )}
                        </div>
                    </div>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg bg-muted/50 p-3">
                        <p className="text-xs text-muted-foreground">
                            {__('Consumed in budgets')}
                        </p>
                        <p className="mt-1 text-lg font-semibold tabular-nums">
                            <AmountDisplay
                                amountInCents={budgetSummary.total_spent}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />{' '}
                            <span className="text-sm font-normal text-muted-foreground">
                                {__('of')}{' '}
                                <AmountDisplay
                                    amountInCents={
                                        budgetSummary.total_available
                                    }
                                    currencyCode={currencyCode}
                                />
                            </span>
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3">
                        <p className="text-xs text-muted-foreground">
                            {__('Remaining')}
                        </p>
                        <p
                            className={`mt-1 text-lg font-semibold tabular-nums ${statusClass(budgetSummary.status)}`}
                        >
                            <AmountDisplay
                                amountInCents={budgetSummary.total_remaining}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/50 p-3">
                        <p className="text-xs text-muted-foreground">
                            {__('Budgeted')}
                        </p>
                        <p className="mt-1 text-lg font-semibold tabular-nums">
                            <AmountDisplay
                                amountInCents={budgetSummary.total_allocated}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />
                        </p>
                        {budgetSummary.total_carried_over !== 0 && (
                            <p className="text-xs text-muted-foreground">
                                +{' '}
                                <AmountDisplay
                                    amountInCents={
                                        budgetSummary.total_carried_over
                                    }
                                    currencyCode={currencyCode}
                                />{' '}
                                {__('carried over')}
                            </p>
                        )}
                    </div>
                </div>

                <Progress
                    value={Math.min(
                        Math.max(budgetSummary.percentage_used, 0),
                        100,
                    )}
                    className="h-2"
                    aria-label={__('Overall budget progress')}
                    aria-valuetext={`${budgetSummary.percentage_used.toFixed(1)}%`}
                />
            </CardHeader>

            <CardContent className="space-y-4">
                {standardBudgets.length > 0 &&
                    (hasMixedPeriods ? (
                        <div className="space-y-4">
                            {budgetSummary.groups.map((group) => {
                                const groupBudgets = standardBudgets.filter(
                                    (budget) =>
                                        budget.period_type ===
                                        group.period_type,
                                );

                                return (
                                    <section
                                        key={group.period_type}
                                        aria-labelledby={`budget-overview-group-${group.period_type}`}
                                    >
                                        {renderGroupSummary(group)}
                                        <div className="mt-3 grid gap-3 lg:grid-cols-2">
                                            {groupBudgets.map(renderBudgetRow)}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    ) : (
                        <section aria-labelledby="budget-overview-specific">
                            <h3
                                id="budget-overview-specific"
                                className="mb-2 text-sm font-medium"
                            >
                                {__('Specific budgets')}
                            </h3>
                            <div className="grid gap-3 lg:grid-cols-2">
                                {standardBudgets.map(renderBudgetRow)}
                            </div>
                        </section>
                    ))}

                {catchAllBudgets.length > 0 && (
                    <section
                        aria-labelledby="budget-overview-catch-all"
                        className="border-t pt-4"
                    >
                        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                            <h3
                                id="budget-overview-catch-all"
                                className="text-sm font-medium"
                            >
                                {__('Catch-all budget')}
                            </h3>
                            {budgetSummary.catch_all && (
                                <span className="text-xs text-muted-foreground">
                                    {__('Excluded from specific budget totals')}
                                </span>
                            )}
                        </div>
                        <div className="grid gap-3 lg:grid-cols-2">
                            {catchAllBudgets.map(renderBudgetRow)}
                        </div>
                    </section>
                )}
            </CardContent>
        </Card>
    );
}
