import { AmountDisplay } from '@/components/ui/amount-display';
import { Progress } from '@/components/ui/progress';
import type { BudgetStatus, BudgetSummary } from '@/types/budget';
import { __ } from '@/utils/i18n';
import { CheckCircle2 } from 'lucide-react';

interface Props {
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

export function BudgetOverviewCard({ budgetSummary, currencyCode }: Props) {
    const monthlySummary = budgetSummary.groups.find(
        (group) => group.period_type === 'monthly',
    );
    const yearlySummary = budgetSummary.groups.find(
        (group) => group.period_type === 'yearly',
    );
    const attentionSummary = monthlySummary
        ? [
              monthlySummary.over_limit_count > 0 &&
                  `${monthlySummary.over_limit_count} ${__('over limit')}`,
              monthlySummary.close_to_limit_count > 0 &&
                  `${monthlySummary.close_to_limit_count} ${__('close to limit')}`,
          ]
              .filter(Boolean)
              .join(' · ')
        : '';
    const yearlyIsOverLimit = (yearlySummary?.total_remaining ?? 0) < 0;

    return (
        <section className="space-y-5" aria-labelledby="budget-overview-title">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div>
                    <h2
                        id="budget-overview-title"
                        className="text-base font-semibold"
                    >
                        {__('Monthly budget overview')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Active monthly budget totals for the current period',
                        )}
                    </p>
                </div>
                {monthlySummary && (
                    <div
                        className="flex items-center gap-2 text-sm"
                        aria-label={__('Budget status')}
                        aria-live="polite"
                    >
                        {attentionSummary ? (
                            <span
                                className={statusClass(monthlySummary.status)}
                            >
                                {attentionSummary}
                            </span>
                        ) : (
                            <span className="flex items-center gap-1 text-green-600 dark:text-green-400">
                                <CheckCircle2 className="h-4 w-4" />
                                {__('On track')}
                            </span>
                        )}
                    </div>
                )}
            </div>

            {monthlySummary ? (
                <div
                    data-testid="budget-overview-hero"
                    data-status={monthlySummary.status}
                    className="rounded-xl bg-muted/50 p-4 sm:p-5"
                >
                    <div className="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                        <div>
                            <p
                                className={`text-sm font-medium ${statusClass(monthlySummary.status)}`}
                            >
                                {monthlySummary.status === 'over_limit'
                                    ? __('Monthly over budget')
                                    : __('Monthly remaining')}
                            </p>
                            <p
                                className={`mt-1 text-2xl font-semibold tabular-nums ${statusClass(monthlySummary.status)}`}
                            >
                                <AmountDisplay
                                    amountInCents={
                                        monthlySummary.total_remaining
                                    }
                                    currencyCode={currencyCode}
                                    weight="semibold"
                                />
                            </p>
                        </div>
                        <div className="text-sm text-muted-foreground sm:text-right">
                            <p>{__('Consumed in monthly budgets')}</p>
                            <p className="font-medium text-foreground">
                                <AmountDisplay
                                    amountInCents={monthlySummary.total_spent}
                                    currencyCode={currencyCode}
                                    weight="semibold"
                                />{' '}
                                {__('of')}{' '}
                                <AmountDisplay
                                    amountInCents={
                                        monthlySummary.total_available
                                    }
                                    currencyCode={currencyCode}
                                />
                            </p>
                        </div>
                    </div>
                    {monthlySummary.total_carried_over !== 0 && (
                        <p className="mt-2 text-xs text-muted-foreground">
                            +{' '}
                            <AmountDisplay
                                amountInCents={
                                    monthlySummary.total_carried_over
                                }
                                currencyCode={currencyCode}
                            />{' '}
                            {__('carried over')}
                        </p>
                    )}
                    <Progress
                        value={Math.min(
                            Math.max(monthlySummary.percentage_used, 0),
                            100,
                        )}
                        className="mt-4 h-2"
                        indicatorClassName={progressIndicatorClass(
                            monthlySummary.status,
                        )}
                        aria-label={__('Overall monthly budget progress')}
                        aria-valuetext={`${monthlySummary.percentage_used.toFixed(1)}%`}
                        data-status={monthlySummary.status}
                    />
                </div>
            ) : (
                <div
                    data-testid="budget-overview-monthly-empty"
                    className="rounded-xl bg-muted/50 p-4 text-sm text-muted-foreground sm:p-5"
                >
                    {__('No active monthly budgets')}
                </div>
            )}

            {yearlySummary && (
                <p
                    data-testid="budget-overview-yearly"
                    className={`text-sm ${
                        yearlyIsOverLimit
                            ? statusClass(yearlySummary.status)
                            : 'text-muted-foreground'
                    }`}
                >
                    <span className="font-medium">
                        {yearlyIsOverLimit
                            ? __('Yearly budget exceeded by')
                            : __('Yearly remaining')}
                    </span>{' '}
                    <AmountDisplay
                        amountInCents={Math.abs(yearlySummary.total_remaining)}
                        currencyCode={currencyCode}
                        weight="semibold"
                    />
                </p>
            )}
        </section>
    );
}
