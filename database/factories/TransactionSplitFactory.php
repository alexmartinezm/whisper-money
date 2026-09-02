<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TransactionSplit> */
class TransactionSplitFactory extends Factory
{
    public function definition(): array
    {
        return [
            'transaction_id' => Transaction::factory(),
            'category_id' => Category::factory(),
            'amount' => fake()->numberBetween(-10000, -1),
            // Parts are unique on (transaction_id, position), so a caller
            // making more than one part for the same transaction has to number
            // them itself — a random default collides roughly 1 run in 100.
            'position' => fake()->numberBetween(0, 100),
        ];
    }
}
