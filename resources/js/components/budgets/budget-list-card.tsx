import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { PlanningCard } from '@/components/shared/planning-card';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import type { Budget } from '@/types/budget';
import {
    budgetSeverity,
    getBudgetPeriodTypeLabel,
    getBudgetSeverityColor,
} from '@/types/budget';
import { getBudgetPeriodStats } from '@/utils/budget';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Calendar } from 'lucide-react';
import { useMemo } from 'react';

interface Props {
    budget: Budget;
    currencyCode: string;
}

export function BudgetListCard({ budget, currencyCode }: Props) {
    const locale = useLocale();
    const currentPeriod = budget.periods?.[0];

    // Carry-over aware, and prefers the period's own spent_amount when the
    // server sent one. budgetSeverity() reads the same helper, so the colour
    // here and the item's place in the Planning list cannot disagree.
    const stats = useMemo(
        () => getBudgetPeriodStats(currentPeriod),
        [currentPeriod],
    );

    const periodLabel = useMemo(() => {
        if (!currentPeriod) return __('No active period');

        const start = formatDate(currentPeriod.start_date, 'MMM d', locale);
        const end = formatDate(currentPeriod.end_date, 'MMM d', locale);

        return `${start} - ${end}`;
    }, [currentPeriod, locale]);

    const statusColor = useMemo(
        () => getBudgetSeverityColor(budgetSeverity(budget)),
        [budget],
    );

    const trackingNames = useMemo(() => {
        return [
            ...(budget.categories?.map((category) => category.name) ?? []),
            ...(budget.labels?.map((label) => label.name) ?? []),
        ];
    }, [budget]);

    return (
        <PlanningCard
            href={show({ budget: budget.id }).url}
            title={budget.name}
            badge={
                <Badge variant="outline">
                    {__(getBudgetPeriodTypeLabel(budget.period_type))}
                </Badge>
            }
            description={
                <>
                    <Calendar className="h-3 w-3" />
                    {periodLabel}
                </>
            }
            subtitle={
                budget.next_planning_period ? (
                    <Link
                        href={
                            show(
                                { budget: budget.id },
                                {
                                    query: {
                                        period: budget.next_planning_period.id,
                                    },
                                },
                            ).url
                        }
                        className="text-sm text-muted-foreground underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {__('Plan next period')}
                    </Link>
                ) : null
            }
            footerStart={
                <div className="flex flex-wrap items-center gap-1">
                    <span className="text-sm text-muted-foreground">
                        {__('Tracking:')}
                    </span>
                    {budget.is_catch_all ? (
                        <Badge variant="secondary">
                            {__('All untracked expenses')}
                        </Badge>
                    ) : trackingNames.length > 0 ? (
                        <>
                            {trackingNames.slice(0, 2).map((name) => (
                                <Badge key={name} variant="secondary">
                                    {name}
                                </Badge>
                            ))}
                            {trackingNames.length > 2 && (
                                <Badge variant="secondary">
                                    {__('+:count', {
                                        count: trackingNames.length - 2,
                                    })}
                                </Badge>
                            )}
                        </>
                    ) : (
                        <span className="text-sm text-muted-foreground">
                            {__('No tracking')}
                        </span>
                    )}
                </div>
            }
        >
            {/* A budget spends down, so it reads as a bar draining left to
                right. The savings goal card fills a ring instead. */}
            <div className="space-y-2">
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">{__('Spent')}</span>
                    <span className={statusColor}>
                        <AmountDisplay
                            amountInCents={stats.totalSpent}
                            currencyCode={currencyCode}
                        />{' '}
                        {__('of')}{' '}
                        <AmountDisplay
                            amountInCents={stats.totalAvailable}
                            currencyCode={currencyCode}
                        />
                    </span>
                </div>
                <Progress
                    value={Math.min(stats.percentageUsed, 100)}
                    className="h-2"
                />

                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">
                        {__('Remaining')}
                    </span>
                    <span className={statusColor}>
                        <AmountDisplay
                            amountInCents={stats.remaining}
                            currencyCode={currencyCode}
                        />
                    </span>
                </div>
            </div>
        </PlanningCard>
    );
}
