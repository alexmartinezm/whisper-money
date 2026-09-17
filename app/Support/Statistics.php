<?php

namespace App\Support;

/**
 * Median and median absolute deviation, shared by everything that reasons about
 * repeating money.
 *
 * The mean and the standard deviation are the wrong tools here: one missed
 * month, one double charge or one unusually expensive week drags them enough to
 * change the answer. The median barely moves, which is what lets cadence
 * detection, the everyday-spending estimate and price-change detection all
 * agree about what "typical" means.
 */
class Statistics
{
    /** @param  list<int>  $values */
    public static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    /**
     * The median of the most recent values: what a charge costs now, rather
     * than what it has cost over its whole life.
     *
     * Takes fewer than the window when fewer exist. A quarterly premium or an
     * annual renewal would otherwise never have enough history to answer the
     * question at all, and those are exactly the charges whose old price is
     * most out of date.
     *
     * @param  list<int>  $values  oldest first
     */
    public static function recentMedian(array $values, int $window): float
    {
        return self::median(array_slice($values, -max(1, $window)));
    }

    /**
     * How far the values typically sit from their own median — the spread, told
     * without letting a single outlier decide.
     *
     * @param  list<int>  $values
     */
    public static function medianAbsoluteDeviation(array $values, ?float $median = null): float
    {
        $median ??= self::median($values);

        return self::median(array_map(
            fn (int $value): int => (int) round(abs($value - $median)),
            $values,
        ));
    }
}
