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
            'expected_amount' => -fake()->numberBetween(500, 9000),
            'amount_is_variable' => false,
            'currency_code' => 'EUR',
            'direction' => 'expense',
            'first_occurred_on' => $lastOccurred->subDays($cadence->intervalDays() * 3),
            'last_occurred_on' => $lastOccurred,
            'next_expected_on' => $lastOccurred->addDays($cadence->intervalDays()),
            'occurrence_count' => 4,
            'status' => RecurringSeriesStatus::Active,
            'user_state' => RecurringSeriesUserState::Detected,
        ];
    }

    public function cadence(RecurringCadence $cadence): static
    {
        return $this->state(function (array $attributes) use ($cadence) {
            $lastOccurred = CarbonImmutable::parse($attributes['last_occurred_on']);

            return [
                'cadence' => $cadence,
                'interval_days' => $cadence->intervalDays(),
                'next_expected_on' => $lastOccurred->addDays($cadence->intervalDays()),
            ];
        });
    }

    public function lapsed(): static
    {
        return $this->state(function (array $attributes) {
            $lastOccurred = CarbonImmutable::today()->subDays(120);

            return [
                'status' => RecurringSeriesStatus::Lapsed,
                'last_occurred_on' => $lastOccurred,
                'next_expected_on' => $lastOccurred->addDays($attributes['interval_days']),
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
