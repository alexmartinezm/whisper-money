<?php

namespace App\Services\Recurring;

use App\Data\RecurringPriceRise;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Models\User;
use App\Support\Statistics;
use Illuminate\Support\Collection;

/**
 * Finds the subscriptions that quietly went up.
 *
 * A price rise is the one thing on this screen that costs real money and the
 * one thing nothing surfaces. `expected_amount` cannot do it: it is a median
 * over the whole history, so a rise drags it a little each month and never
 * announces itself — by the time the median has caught up you have been paying
 * the new price for half a year.
 *
 * So the comparison is between windows: the most recent charges against the
 * ones before them.
 */
class DetectPriceChanges
{
    /**
     * Rises worth telling the user about, biggest first.
     *
     * @return Collection<int, RecurringPriceRise>
     */
    public function forUser(User $user): Collection
    {
        $window = (int) config('recurring.price_window');

        return RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('status', RecurringSeriesStatus::Active)
            ->where('user_state', '!=', RecurringSeriesUserState::Ignored)
            // Income going up is a pay rise, not a cost to warn about.
            ->where('direction', 'expense')
            ->with(['transactions' => fn ($query) => $query
                ->orderByDesc('transaction_date')
                ->limit($window * 2)])
            ->get()
            ->map(fn (RecurringSeries $series): ?RecurringPriceRise => $this->riseFor($series, $window))
            ->filter()
            ->sortByDesc(fn (RecurringPriceRise $rise): int => $rise->increase())
            ->values();
    }

    /**
     * Compare the two windows and decide whether the price actually moved.
     */
    private function riseFor(RecurringSeries $series, int $window): ?RecurringPriceRise
    {
        // Amounts are negative for expenses; work in magnitudes so "higher"
        // means what it says and the thresholds read naturally.
        $amounts = $series->transactions
            ->sortByDesc('transaction_date')
            ->map(fn ($transaction): int => abs((int) $transaction->amount))
            ->values();

        if ($amounts->count() < $window * 2) {
            return null;
        }

        $after = $amounts->take($window)->all();
        $before = $amounts->slice($window, $window)->values()->all();

        $afterMedian = Statistics::median($after);
        $beforeMedian = Statistics::median($before);

        if ($beforeMedian <= 0 || ! $this->isSteady($after, $afterMedian) || ! $this->isSteady($before, $beforeMedian)) {
            return null;
        }

        $increase = (int) round($afterMedian - $beforeMedian);

        if (! $this->isMaterial($increase, $beforeMedian)) {
            return null;
        }

        $current = (int) round($afterMedian);

        // Announced already: stay quiet until it moves again by as much as it
        // would have taken to speak up the first time.
        if ($series->price_alerted_amount !== null
            && ! $this->isMaterial($current - abs($series->price_alerted_amount), abs((float) $series->price_alerted_amount))) {
            return null;
        }

        return new RecurringPriceRise(
            series: $series,
            previousAmount: (int) round($beforeMedian),
            currentAmount: $current,
        );
    }

    /**
     * Whether a window looks like a fixed price rather than a bill that swings.
     *
     * This is what keeps the electricity out of the results: its spread is wide
     * in every window, so it never reads as a price that held still and then
     * moved.
     *
     * @param  list<int>  $amounts
     */
    private function isSteady(array $amounts, float $median): bool
    {
        if ($median <= 0) {
            return false;
        }

        return Statistics::medianAbsoluteDeviation($amounts, $median) / $median
            <= (float) config('recurring.price_stability');
    }

    /** Big enough to be worth an email, in both relative and absolute terms. */
    private function isMaterial(int $increase, float $baseline): bool
    {
        return $increase >= (int) config('recurring.price_rise_minimum')
            && $baseline > 0
            && $increase / $baseline >= (float) config('recurring.price_rise_threshold');
    }
}
