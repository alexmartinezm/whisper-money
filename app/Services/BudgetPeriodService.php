<?php

namespace App\Services;

use App\Enums\BudgetPeriodType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use Carbon\CarbonInterface;

class BudgetPeriodService
{
    public function generatePeriod(Budget $budget, ?int $allocatedAmount = null, ?CarbonInterface $startDate = null, bool $processHistorical = false): BudgetPeriod
    {
        if ($startDate === null) {
            $startDate = $this->calculateNextPeriodStartDate($budget);
        }

        [$periodStart, $periodEnd] = $this->calculatePeriodDates($budget, $startDate);

        $periodStart = $periodStart->startOfDay();
        $periodEnd = $periodEnd->startOfDay();

        // If no allocated amount provided, use the last period's amount or 0
        if ($allocatedAmount === null) {
            $lastPeriod = $budget->periods()->orderBy('end_date', 'desc')->first();
            $allocatedAmount = $lastPeriod !== null ? $lastPeriod->allocated_amount : 0;
        }

        // Idempotent on the (budget_id, start_date) unique key: the scheduled
        // command can recompute the same next start date across overlapping or
        // repeated runs, so return the existing period instead of colliding.
        return BudgetPeriod::firstOrCreate(
            [
                'budget_id' => $budget->id,
                'start_date' => $periodStart,
            ],
            [
                'end_date' => $periodEnd,
                'allocated_amount' => $allocatedAmount,
                'carried_over_amount' => 0,
                'processing_historical' => $processHistorical,
            ],
        );
    }

    /**
     * The period immediately before a given one.
     *
     * "The day before this one started" reads like the obvious reference and is
     * what this used to do, but a biweekly period is rewound to a *weekday* -
     * a seven-day grid under a fourteen-day window - so that day lands in the
     * middle of the previous fortnight and the two periods overlap by a week.
     * 12 of the 13 live biweekly budgets in production are shaped that way, and
     * since both periods are handed to `AssignHistoricalTransactionsToBudget`,
     * 16 transactions across 8 budgets hold two rows for one spend.
     */
    public function generatePreviousPeriod(Budget $budget, BudgetPeriod $period, ?int $allocatedAmount = null, bool $processHistorical = false): BudgetPeriod
    {
        $referenceDate = $this->onePeriodEarlier($budget, $period);

        return $this->generatePeriod($budget, $allocatedAmount ?? $period->allocated_amount, $referenceDate, $processHistorical);
    }

    /**
     * One period back from where this one starts.
     *
     * Counting days is not it: 31 days back from 1 March is January, so February
     * disappears. Monthly steps back without overflow so that, for a budget
     * anchored past the 28th, the previous window cannot land inside the current
     * one — the overlap this method exists to stop, reappearing in another type.
     */
    private function onePeriodEarlier(Budget $budget, BudgetPeriod $period): CarbonInterface
    {
        $start = $period->start_date->copy();

        return match ($budget->period_type) {
            BudgetPeriodType::Weekly => $start->subWeek(),
            BudgetPeriodType::Biweekly => $start->subWeeks(2),
            BudgetPeriodType::Yearly => $start->subYear(),
            BudgetPeriodType::Monthly => $start->subMonthNoOverflow(),
        };
    }

    /**
     * Roll what is left of a finished period into the period that follows it.
     *
     * Looking the successor up is what makes this safe to run again: closing the
     * same period twice writes the same number to the same row. It used to ask
     * for a period instead, with no start date - which anchors to the end of the
     * chain, and the chain moves every time. So the nightly pass over every
     * period that had ever ended appended one row per closed period per run,
     * pushing one budget's periods out to the year 2143, and stamped the leftover
     * on that far-future row instead of the period the user is spending in.
     */
    public function closePeriod(BudgetPeriod $period): void
    {
        $budget = $period->budget;
        $carriedOverAmount = 0;

        if ($budget->rollover_type->value === 'carry_over') {
            $remaining = $period->remainingAmount();

            if ($remaining > 0) {
                $carriedOverAmount = $remaining;
            }
        }

        // Looked up before it is created: not every chain is a clean tiling, and
        // a successor that starts on this period's end date is still the
        // successor. Stepping over it appends yet another period alongside and
        // stamps the leftover on the wrong row — which is how a nightly pass
        // over every ended period pushed one budget's chain out to 2143.
        $nextPeriod = $budget->periodFollowing($period)
            ?? $this->ensureSuccessor($budget, $period, $period->allocated_amount);

        $nextPeriod->update(['carried_over_amount' => $carriedOverAmount]);
    }

    public function ensureSuccessor(Budget $budget, BudgetPeriod $precedingPeriod, int $seedAllocatedAmount): BudgetPeriod
    {
        $successorStart = $precedingPeriod->end_date->copy()->addDay();

        return $this->generatePeriod($budget, $seedAllocatedAmount, $successorStart);
    }

    public function calculatePeriodDates(Budget $budget, CarbonInterface $referenceDate): array
    {
        $startDate = $referenceDate->copy();

        switch ($budget->period_type) {
            case BudgetPeriodType::Monthly:
                $configuredDay = $budget->period_start_day ?? 1;
                $monthStart = $referenceDate->copy()->startOfMonth();
                $startDate = $this->monthlyBoundary($monthStart, $configuredDay);
                if ($startDate > $referenceDate) {
                    $startDate = $this->monthlyBoundary($monthStart->subMonth(), $configuredDay);
                }
                $nextStart = $this->monthlyBoundary($startDate->copy()->startOfMonth()->addMonthNoOverflow(), $configuredDay);
                $endDate = $nextStart->copy()->subDay();
                break;

            case BudgetPeriodType::Weekly:
                $startDate = $this->rewindToDayOfWeek($startDate, $budget->period_start_day);
                $endDate = $startDate->copy()->addWeek()->subDay();
                break;

            case BudgetPeriodType::Biweekly:
                $startDate = $this->rewindToDayOfWeek($startDate, $budget->period_start_day);
                $endDate = $startDate->copy()->addWeeks(2)->subDay();
                break;

            case BudgetPeriodType::Yearly:
                $startDate = $startDate->startOfYear();
                $endDate = $startDate->copy()->addYear()->subDay();
                break;
        }

        return [$startDate, $endDate];
    }

    /**
     * Step back to the most recent $dayOfWeek. Taken modulo 7 because
     * `period_start_day` holds a day of the month for monthly budgets, so a
     * budget switched to a weekly period can carry a value above 6 — which
     * `Carbon::dayOfWeek` would never match, spinning forever.
     */
    private function rewindToDayOfWeek(CarbonInterface $date, ?int $dayOfWeek): CarbonInterface
    {
        $target = ($dayOfWeek ?? 0) % 7;

        // Reassigned rather than mutated in place: these dates can be
        // CarbonImmutable, where subDay() returns a new instance and mutating
        // would spin here forever.
        while ($date->dayOfWeek !== $target) {
            $date = $date->subDay();
        }

        return $date;
    }

    protected function calculateNextPeriodStartDate(Budget $budget): CarbonInterface
    {
        $lastPeriod = $budget->periods()->orderBy('end_date', 'desc')->first();

        if ($lastPeriod) {
            return $lastPeriod->end_date->copy()->addDay();
        }

        return now();
    }

    private function monthlyBoundary(CarbonInterface $monthStart, int $configuredDay): CarbonInterface
    {
        return $monthStart->copy()
            ->day($this->clampStartDay($configuredDay, $monthStart->daysInMonth))
            ->startOfDay();
    }

    /**
     * A configured start day as a real day of the month.
     *
     * Floored at 1 because `period_start_day` is validated as 0-31 for every
     * period type — for weekly and biweekly budgets it is a day of the week and
     * 0 is Sunday. A monthly budget carrying that 0 reaches `Carbon::day(0)`,
     * which resolves to the last day of the *previous* month, and chained
     * generation never escapes it: the reference date is the previous period's
     * end plus one day, day(0) snaps back to the period that already exists,
     * and the budget freezes with no current period at all.
     *
     * Capped at the length of the month so an anchor past the 28th does not
     * overflow into the next one.
     */
    private function clampStartDay(int $configuredDay, int $daysInMonth): int
    {
        return max(1, min($configuredDay, $daysInMonth));
    }
}
