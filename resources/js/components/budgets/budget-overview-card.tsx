import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { AmountDisplay } from '@/components/ui/amount-display';
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
import { CheckCircle2 } from 'lucide-react';

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

function progressIndicatorClass(status: BudgetStatus): string {
    if (status === 'over_limit') return 'bg-destructive';
    if (status === 'close_to_limit') {
        return 'bg-amber-500 dark:bg-amber-400';
    }
    return 'bg-green-600 dark:bg-green-400';
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
                    indicatorClassName={progressIndicatorClass(stats.status)}
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

    const attentionSummary = [
        budgetSummary.over_limit_count > 0 &&
            `${budgetSummary.over_limit_count} ${__('over limit')}`,
        budgetSummary.close_to_limit_count > 0 &&
            `${budgetSummary.close_to_limit_count} ${__('close to limit')}`,
    ]
        .filter(Boolean)
        .join(' · ');
    const summaryLabel =
        budgetSummary.status === 'over_limit'
            ? __('Over budget')
            : __('Remaining');

    return (
        <section className="space-y-5" aria-labelledby="budget-overview-title">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2
                        id="budget-overview-title"
                        className="text-base font-semibold"
                    >
                        {__('Budget overview')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {__('Specific budget totals for the current period')}
                    </p>
                </div>
                <div
                    className="flex items-center gap-2 text-sm"
                    aria-label={__('Specific budget status')}
                    aria-live="polite"
                >
                    {attentionSummary ? (
                        <span className={statusClass(budgetSummary.status)}>
                            {attentionSummary}
                        </span>
                    ) : (
                        <span className="flex items-center gap-1 text-green-600 dark:text-green-400">
                            <CheckCircle2 className="h-4 w-4" />
                            {__('On track')}
                        </span>
                    )}
                </div>
            </div>

            <section
                data-testid="budget-overview-hero"
                data-status={budgetSummary.status}
                className="rounded-xl bg-muted/50 p-4 sm:p-5"
            >
                <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                    <div>
                        <p
                            className={`text-sm font-medium ${statusClass(budgetSummary.status)}`}
                        >
                            {summaryLabel}
                        </p>
                        <p
                            className={`mt-1 text-2xl font-semibold tabular-nums ${statusClass(budgetSummary.status)}`}
                        >
                            <AmountDisplay
                                amountInCents={budgetSummary.total_remaining}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />
                        </p>
                    </div>
                    <div className="text-sm text-muted-foreground sm:text-right">
                        <p>{__('Consumed in budgets')}</p>
                        <p className="font-medium text-foreground">
                            <AmountDisplay
                                amountInCents={budgetSummary.total_spent}
                                currencyCode={currencyCode}
                                weight="semibold"
                            />{' '}
                            {__('of')}{' '}
                            <AmountDisplay
                                amountInCents={budgetSummary.total_available}
                                currencyCode={currencyCode}
                            />
                        </p>
                    </div>
                </div>
                {budgetSummary.total_carried_over !== 0 && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        +{' '}
                        <AmountDisplay
                            amountInCents={budgetSummary.total_carried_over}
                            currencyCode={currencyCode}
                        />{' '}
                        {__('carried over')}
                    </p>
                )}
                <Progress
                    value={Math.min(
                        Math.max(budgetSummary.percentage_used, 0),
                        100,
                    )}
                    className="mt-4 h-2"
                    indicatorClassName={progressIndicatorClass(
                        budgetSummary.status,
                    )}
                    aria-label={__('Overall budget progress')}
                    aria-valuetext={`${budgetSummary.percentage_used.toFixed(1)}%`}
                    data-status={budgetSummary.status}
                />
            </section>

            <div className="space-y-4">
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
            </div>
        </section>
    );
}
