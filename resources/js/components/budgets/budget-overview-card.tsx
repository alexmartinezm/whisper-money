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
                        {__('Active budget totals for the current period')}
                    </p>
                </div>
                <div
                    className="flex items-center gap-2 text-sm"
                    aria-label={__('Budget status')}
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

            <div
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
            </div>
        </section>
    );
}
