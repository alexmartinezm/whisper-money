import type { BudgetPeriod, BudgetStatus } from '@/types/budget';

type BudgetWithPeriods = {
    periods?: Array<Pick<BudgetPeriod, 'allocated_amount'>>;
};

type BudgetPeriodForStats = Pick<
    BudgetPeriod,
    'allocated_amount' | 'carried_over_amount'
> & {
    spent_amount?: number | null;
    status?: BudgetStatus;
    budget_transactions?: Array<{ amount: number }>;
};

export interface BudgetPeriodStats {
    totalAllocated: number;
    totalCarriedOver: number;
    totalAvailable: number;
    totalSpent: number;
    remaining: number;
    percentageUsed: number;
    status: BudgetStatus;
}

function budgetStatus(
    totalSpent: number,
    totalAvailable: number,
): BudgetStatus {
    if (totalAvailable > 0 && totalSpent >= totalAvailable) {
        return 'over_limit';
    }

    if (totalAvailable > 0 && totalSpent * 100 >= totalAvailable * 90) {
        return 'close_to_limit';
    }

    return 'on_track';
}

export function getBudgetPeriodStats(
    period: BudgetPeriodForStats | null | undefined,
): BudgetPeriodStats {
    const totalAllocated = Number(period?.allocated_amount ?? 0);
    const totalCarriedOver = Number(period?.carried_over_amount ?? 0);
    const totalAvailable = totalAllocated + totalCarriedOver;
    const totalSpent = Number(
        period?.spent_amount ??
            period?.budget_transactions?.reduce(
                (total, transaction) => total + Number(transaction.amount),
                0,
            ) ??
            0,
    );

    return {
        totalAllocated,
        totalCarriedOver,
        totalAvailable,
        totalSpent,
        remaining: totalAvailable - totalSpent,
        percentageUsed:
            totalAvailable > 0 ? (totalSpent / totalAvailable) * 100 : 0,
        status: period?.status ?? budgetStatus(totalSpent, totalAvailable),
    };
}

export function sortBudgetsByAllocatedAmount<T extends BudgetWithPeriods>(
    budgets: T[],
): T[] {
    return [...budgets].sort((firstBudget, secondBudget) => {
        const firstActivePeriod = firstBudget.periods?.[0];
        const secondActivePeriod = secondBudget.periods?.[0];

        if (!firstActivePeriod && !secondActivePeriod) {
            return 0;
        }

        if (!firstActivePeriod) {
            return 1;
        }

        if (!secondActivePeriod) {
            return -1;
        }

        return (
            secondActivePeriod.allocated_amount -
            firstActivePeriod.allocated_amount
        );
    });
}
