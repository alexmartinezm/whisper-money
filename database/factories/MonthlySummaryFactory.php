<?php

namespace Database\Factories;

use App\Models\MonthlySummary;
use App\Models\Space;
use App\Models\User;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MonthlySummary> */
class MonthlySummaryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'space_id' => Space::factory(),
            'period' => now()->subMonth()->format('Y-m'),
            'payload' => ['currency' => 'EUR'],
            'ai_analysis' => null,
            'ai_generated_at' => null,
            'complete' => true,
        ];
    }

    /**
     * A snapshot taken while the month was still open, which is the only kind
     * {@see Summaries::freeze()} will rebuild.
     */
    public function incomplete(): static
    {
        return $this->state(fn (): array => ['complete' => false]);
    }
}
