<?php

namespace Database\Factories;

use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringSeries>
 */
class RecurringSeriesFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cadence = RecurringCadence::Monthly;
        $lastOccurred = CarbonImmutable::today()->subDays(5);
        $merchant = fake()->unique()->company();

        return [
            'user_id' => User::factory(),
            'match_field' => 'creditor_name',
            'merchant_key' => mb_strtolower($merchant),
            'display_name' => $merchant,
            'cadence' => $cadence,
            'interval_days' => $cadence->intervalDays(),
            'anchor_day' => $this->anchorDayFor(...),
            'expected_amount' => -fake()->numberBetween(500, 9000),
            'amount_is_variable' => false,
            'currency_code' => 'EUR',
            'direction' => 'expense',
            'first_occurred_on' => $lastOccurred->subDays($cadence->intervalDays() * 3),
            'last_occurred_on' => $lastOccurred,
            'next_expected_on' => $cadence->advance($lastOccurred, $lastOccurred->day),
            'occurrence_count' => 4,
            'status' => RecurringSeriesStatus::Active,
            'user_state' => RecurringSeriesUserState::Detected,
        ];
    }

    /**
     * The billing day of a calendar cadence, worked out from the dates the
     * series ends up with once every state and override is applied.
     *
     * A test that pins `next_expected_on` without an anchor used to keep the
     * day of the last occurrence as the anchor, and the two disagree: on the
     * one day a month when the pinned date is a month end, the cadence reads
     * it as a clipped charge and jumps back to the anchor, so a monthly charge
     * landed twice inside a 30-day window. The anchor follows the pinned date
     * instead, unless that date is exactly where the last occurrence bills
     * next, which is how a charge on the 31st keeps its anchor through a
     * shorter month. An explicit `anchor_day` still wins.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function anchorDayFor(array $attributes): ?int
    {
        $cadence = $attributes['cadence'] instanceof RecurringCadence
            ? $attributes['cadence']
            : RecurringCadence::from((string) $attributes['cadence']);

        if ($cadence->monthsPerOccurrence() === null) {
            return null;
        }

        $lastOccurred = CarbonImmutable::parse($attributes['last_occurred_on']);
        $nextExpected = CarbonImmutable::parse($attributes['next_expected_on']);

        return $nextExpected->isSameDay($cadence->advance($lastOccurred, $lastOccurred->day))
            ? $lastOccurred->day
            : $nextExpected->day;
    }

    public function cadence(RecurringCadence $cadence): static
    {
        return $this->state(function (array $attributes) use ($cadence) {
            $lastOccurred = CarbonImmutable::parse($attributes['last_occurred_on']);

            return [
                'cadence' => $cadence,
                'interval_days' => $cadence->intervalDays(),
                'anchor_day' => $this->anchorDayFor(...),
                'next_expected_on' => $cadence->advance($lastOccurred, $lastOccurred->day),
            ];
        });
    }

    public function lapsed(): static
    {
        return $this->state(function (array $attributes) {
            $lastOccurred = CarbonImmutable::today()->subDays(120);

            $cadence = $attributes['cadence'] instanceof RecurringCadence
                ? $attributes['cadence']
                : RecurringCadence::from((string) $attributes['cadence']);

            return [
                'status' => RecurringSeriesStatus::Lapsed,
                'last_occurred_on' => $lastOccurred,
                'anchor_day' => $this->anchorDayFor(...),
                'next_expected_on' => $cadence->advance($lastOccurred, $lastOccurred->day),
            ];
        });
    }

    public function ignored(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_state' => RecurringSeriesUserState::Ignored,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_state' => RecurringSeriesUserState::Confirmed,
        ]);
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => 'income',
            'expected_amount' => abs((int) $attributes['expected_amount']),
        ]);
    }

    public function variable(): static
    {
        return $this->state(fn (array $attributes) => [
            'amount_is_variable' => true,
        ]);
    }
}
