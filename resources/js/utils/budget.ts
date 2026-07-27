type BudgetWithPeriods = {
    periods?: Array<{ allocated_amount: number }>;
};

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
