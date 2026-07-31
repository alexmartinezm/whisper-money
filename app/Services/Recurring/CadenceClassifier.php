<?php

namespace App\Services\Recurring;

use App\Data\CadenceMatch;
use App\Enums\RecurringCadence;
use Carbon\CarbonInterface;

/**
 * Decides whether a sequence of dates repeats on a recognisable cadence.
 *
 * Uses the median gap and the median absolute deviation rather than the mean
 * and standard deviation: a single missed or double-charged month would drag a
 * mean far enough to either invent a cadence or destroy a real one.
 */
class CadenceClassifier
{
    private const MIN_GAPS = 2;

    /**
     * @param  list<CarbonInterface>  $dates  ascending
     */
    public function classify(array $dates): ?CadenceMatch
    {
        $gaps = $this->gaps($dates);

        if (count($gaps) < self::MIN_GAPS) {
            return null;
        }

        $median = $this->median($gaps);
        $deviation = $this->medianAbsoluteDeviation($gaps, $median);

        /** @var array<string, array{min_days: int, max_days: int, max_deviation: int}> $tolerances */
        $tolerances = config('recurring.cadences');

        foreach ($tolerances as $cadence => $tolerance) {
            if ($median >= $tolerance['min_days']
                && $median <= $tolerance['max_days']
                && $deviation <= $tolerance['max_deviation']) {
                return new CadenceMatch(RecurringCadence::from($cadence), (int) round($median));
            }
        }

        return null;
    }

    /**
     * Whole-day gaps between consecutive dates. Same-day repeats collapse to a
     * zero gap, which no cadence tolerates, so a merchant charged twice in one
     * day cannot masquerade as a weekly series.
     *
     * @param  list<CarbonInterface>  $dates
     * @return list<int>
     */
    private function gaps(array $dates): array
    {
        $gaps = [];

        for ($i = 1; $i < count($dates); $i++) {
            $gaps[] = (int) $dates[$i - 1]->startOfDay()->diffInDays($dates[$i]->startOfDay());
        }

        return $gaps;
    }

    /** @param  list<int>  $values */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /** @param  list<int>  $values */
    private function medianAbsoluteDeviation(array $values, float $median): float
    {
        $deviations = array_map(
            fn (int $value): int => (int) round(abs($value - $median)),
            $values,
        );

        return $this->median($deviations);
    }
}
