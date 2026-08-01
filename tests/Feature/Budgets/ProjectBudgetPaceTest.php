<?php

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Budgets\ProjectBudgetPace;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->pace = app(ProjectBudgetPace::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
});

/**
 * A budget whose current period started `$elapsed` days ago, runs 30 days, and
 * has `$spent` charged against it so far.
 */
function budgetSpending(User $user, string $name, int $allocated, int $spent, int $elapsed = 10): Budget
{
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'name' => $name,
    ]);

    $start = CarbonImmutable::today()->subDays($elapsed - 1);

    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $start->toDateString(),
        'end_date' => $start->addDays(29)->toDateString(),
        'allocated_amount' => $allocated,
        'carried_over_amount' => 0,
        'processing_historical' => false,
    ]);

    if ($spent !== 0) {
        $period->budgetTransactions()->create([
            'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
            'amount' => $spent,
        ]);
    }

    return $budget;
}

it('flags a budget the current pace will overshoot', function () {
    // 200 in ten days is 600 over thirty, against an allocation of 500.
    budgetSpending($this->user, 'Alimentación', 50000, 20000);

    $rows = $this->pace->forUser($this->user);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['name'])->toBe('Alimentación')
        ->and($rows[0]['spent'])->toBe(20000)
        ->and($rows[0]['available'])->toBe(50000)
        ->and($rows[0]['projected'])->toBe(60000)
        ->and($rows[0]['over_by'])->toBe(10000);
});

it('says nothing about a budget that is on track', function () {
    budgetSpending($this->user, 'Ocio', 50000, 10000);

    expect($this->pace->forUser($this->user))->toBeEmpty();
});

it('counts the rollover as part of what is available', function () {
    $budget = budgetSpending($this->user, 'Alimentación', 50000, 20000);
    $budget->periods()->first()->update(['carried_over_amount' => 15000]);

    // 600 projected against 650 available is no longer a warning.
    expect($this->pace->forUser($this->user))->toBeEmpty();
});

it('refuses to extrapolate from the first days of a period', function () {
    // One weekly shop on day two reads as a catastrophic month.
    budgetSpending($this->user, 'Alimentación', 50000, 12000, elapsed: 2);

    expect($this->pace->forUser($this->user))->toBeEmpty();
});

it('skips a period still assigning its history', function () {
    $budget = budgetSpending($this->user, 'Alimentación', 50000, 20000);
    $budget->periods()->first()->update(['processing_historical' => true]);

    expect($this->pace->forUser($this->user))->toBeEmpty();
});

it('leads with the biggest overshoot and stops at three', function () {
    budgetSpending($this->user, 'Pequeño', 10000, 4000);
    budgetSpending($this->user, 'Enorme', 10000, 20000);
    budgetSpending($this->user, 'Mediano', 10000, 8000);
    budgetSpending($this->user, 'Otro', 10000, 5000);
    budgetSpending($this->user, 'Y otro más', 10000, 4500);

    $rows = $this->pace->forUser($this->user);

    expect($rows)->toHaveCount(3)
        ->and(array_column($rows, 'name'))->toBe(['Enorme', 'Mediano', 'Otro']);
});

it('leaves other spaces alone', function () {
    $budget = budgetSpending($this->user, 'Alimentación', 50000, 20000);
    $budget->update(['space_id' => Space::factory()->create()->id]);

    expect($this->pace->forUser($this->user))->toBeEmpty();
});

it('copes with a user who budgets nothing', function () {
    expect($this->pace->forUser($this->user))->toBeEmpty();
});
