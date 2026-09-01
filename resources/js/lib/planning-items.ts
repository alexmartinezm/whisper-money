import { Budget, budgetSeverity } from '@/types/budget';
import { SavingsGoal } from '@/types/savings-goal';

export type PlanningItem =
    | { type: 'budget'; id: string; name: string; budget: Budget }
    | { type: 'goal'; id: string; name: string; goal: SavingsGoal };

/**
 * How badly an item wants to be looked at. Lower sorts first.
 *
 * The tiers come straight from the status each card already shows, so the
 * ordering can never disagree with the colour the user is reading: a budget
 * past its limit, then anything close to its limit or behind schedule, then
 * everything that is fine. A savings goal cannot breach the way a budget can,
 * so it never reaches tier 0.
 */
export function planningAttentionTier(item: PlanningItem): number {
    if (item.type === 'budget') {
        const severity = budgetSeverity(item.budget);

        if (severity === 'over') {
            return 0;
        }

        return severity === 'near' ? 1 : 2;
    }

    return item.goal.stats?.status === 'behind' ? 1 : 2;
}

/**
 * Merges budgets and savings goals into the single Planning list, ordered by
 * attention and then by the chosen tiebreak.
 *
 * With both types on the list the tiebreak is the name: sorting by name rather
 * than by type is what keeps the two kinds interleaved — grouping the leftovers
 * by type would just rebuild the two sections this list replaced.
 *
 * With budgets alone there is nothing to interleave, so the list keeps the
 * allocation ordering it had before savings goals existed: the biggest budget
 * leads, and a budget with no active period sinks to the bottom.
 */
export function mergePlanningItems(
    budgets: Budget[],
    savingsGoals: SavingsGoal[],
    locale?: string,
    tiebreak: 'name' | 'allocation' = 'name',
): PlanningItem[] {
    const items: PlanningItem[] = [
        ...budgets.map(
            (budget): PlanningItem => ({
                type: 'budget',
                id: budget.id,
                name: budget.name,
                budget,
            }),
        ),
        ...savingsGoals.map(
            (goal): PlanningItem => ({
                type: 'goal',
                id: goal.id,
                name: goal.name,
                goal,
            }),
        ),
    ];

    return items.sort((a, b) => {
        const byTier = planningAttentionTier(a) - planningAttentionTier(b);

        if (byTier !== 0) {
            return byTier;
        }

        if (tiebreak === 'allocation') {
            const byAllocation = allocationOf(b) - allocationOf(a);

            if (byAllocation !== 0) {
                return byAllocation;
            }
        }

        return a.name.localeCompare(b.name, locale);
    });
}

/**
 * A budget's active allocation, or -1 when it has no active period so those
 * sink below every budget that has one. Goals report 0 and fall through to the
 * name, which only matters if a goal ever reaches this branch.
 */
function allocationOf(item: PlanningItem): number {
    if (item.type !== 'budget') {
        return 0;
    }

    const period = item.budget.periods?.[0];

    return period ? period.allocated_amount : -1;
}
