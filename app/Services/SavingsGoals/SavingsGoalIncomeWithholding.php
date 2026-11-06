<?php

namespace App\Services\SavingsGoals;

use App\Enums\MonthlyTargetType;
use App\Models\SavingsGoal;
use App\Models\SavingsGoalPeriod;
use App\Models\Space;
use Illuminate\Database\Eloquent\Collection;

/**
 * What `/api/v1` keeps out of a savings goal's row when the goal is measured
 * against a space's income and the space has someone else in it. That income
 * is every member's (MonthlyTargetResolver), and the API only ever hands a user
 * their own rows, so it must not read another member's income off a goal.
 *
 * The income itself is not the only thing to hide: the target over the rate
 * gives it back, and each of the other figures below is worked out from the
 * target. What was saved, and the goal's own rate, stay.
 *
 * A goal moved to a fixed amount keeps the months measured against income in
 * its history, so it stays covered: otherwise a switch would reveal them.
 *
 * API only: SavingsGoalPresenter's row stays whole for the MCP tools, which
 * read a whole space, and the rate is refused on API writes in such a space.
 */
class SavingsGoalIncomeWithholding
{
    /**
     * @var list<string>
     */
    private const MONTH_FIGURES = ['target', 'difference', 'status', 'income_base'];

    /**
     * @var list<string>
     */
    private const SUMMARY_FIGURES = ['months_met', 'months_closed', 'cumulative_difference', 'streak', 'best_streak'];

    /**
     * Whether someone other than the user is in the space: the owner is
     * implicit, members() lists the rest.
     */
    public function hasOtherMembers(Space $space, string $userId): bool
    {
        return $space->owner_id !== $userId
            || $space->loadMissing('members:users.id')->members->contains(fn (mixed $member): bool => $member->getKey() !== $userId);
    }

    /**
     * SavingsGoalPresenter's rows with the figures held back on the goals that
     * need it, in a fixed number of queries however many goals there are.
     *
     * @param  Collection<int, SavingsGoal>  $goals
     * @param  array<string, array<string, mixed>>  $rows  keyed by goal id
     * @return array<string, array<string, mixed>>
     */
    public function apply(Collection $goals, array $rows): array
    {
        foreach ($this->goalsToWithhold($goals) as $goalId) {
            $rows[$goalId]['monthly'] = self::withheld($rows[$goalId]['monthly']);
        }

        return $rows;
    }

    /**
     * @param  Collection<int, SavingsGoal>  $goals
     * @return list<string>
     */
    private function goalsToWithhold(Collection $goals): array
    {
        $spaces = Space::query()
            ->whereKey($goals->pluck('space_id')->filter()->unique()->values())
            ->with('members:users.id')
            ->get(['id', 'owner_id'])
            ->keyBy('id');

        [$onIncome, $onAmount] = $goals
            ->filter(fn (SavingsGoal $goal): bool => $goal->isMonthly()
                && $spaces->has($goal->space_id)
                && $this->hasOtherMembers($spaces->get($goal->space_id), $goal->user_id))
            ->partition(fn (SavingsGoal $goal): bool => $goal->monthly_target_type === MonthlyTargetType::IncomeRate);

        $movedOffIncome = SavingsGoalPeriod::query()
            ->whereIn('savings_goal_id', $onAmount->modelKeys())
            ->where('target_type', MonthlyTargetType::IncomeRate)
            ->distinct()
            ->pluck('savings_goal_id');

        return [...$onIncome->modelKeys(), ...$movedOffIncome];
    }

    /**
     * @param  array<string, mixed>  $monthly
     * @return array<string, mixed>
     */
    private static function withheld(array $monthly): array
    {
        $figures = array_fill_keys(self::MONTH_FIGURES, null);

        return [
            ...$monthly,
            ...array_fill_keys(self::SUMMARY_FIGURES, null),
            'current' => $monthly['current'] === null ? null : [...$monthly['current'], ...$figures, 'remaining' => null],
            'history' => array_map(fn (array $month): array => [...$month, ...$figures], $monthly['history']),
        ];
    }
}
