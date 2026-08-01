<?php

use App\Enums\AccountType;
use App\Enums\RecurringCadence;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\RecurringSeries;
use App\Models\User;
use App\Services\Recurring\ProjectCashflow;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = app(ProjectCashflow::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
});

/** An account holding a known balance as of today. */
function liquidAccount(User $user, int $balance, AccountType $type = AccountType::Checking, string $currency = 'EUR'): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => $currency,
        'type' => $type,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => CarbonImmutable::today()->toDateString(),
        'balance' => $balance,
    ]);

    return $account;
}

it('walks today balance forward through the expected charges', function () {
    liquidAccount($this->user, 100000);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'display_name' => 'Netflix',
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['starting_balance'])->toBe(100000)
        ->and($forecast['expected_out'])->toBe(-1299)
        ->and($forecast['expected_in'])->toBe(0)
        ->and($forecast['ending_balance'])->toBe(98701)
        ->and($forecast['occurrences'])->toHaveCount(1)
        ->and($forecast['occurrences'][0]['balance_after'])->toBe(98701);
});

it('counts a weekly charge every time it lands in the window', function () {
    // The old list showed one row per series; a weekly fee hits four times.
    liquidAccount($this->user, 50000);

    RecurringSeries::factory()->cadence(RecurringCadence::Weekly)->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(2),
        'interval_days' => 7,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['occurrences'])->toHaveCount(5)
        ->and($forecast['expected_out'])->toBe(-5000)
        ->and($forecast['ending_balance'])->toBe(45000);
});

it('reports the lowest point, not just the ending balance', function () {
    liquidAccount($this->user, 10000);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'display_name' => 'Rent',
        'expected_amount' => -90000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(3),
        'interval_days' => 30,
    ]);
    RecurringSeries::factory()->income()->create([
        'user_id' => $this->user->id,
        'display_name' => 'Salary',
        'expected_amount' => 200000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(10),
        'interval_days' => 30,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    // Ends comfortably, but dips hard before payday — that is the useful bit.
    expect($forecast['ending_balance'])->toBe(120000)
        ->and($forecast['lowest']['balance'])->toBe(-80000)
        ->and($forecast['lowest']['date'])->toBe(CarbonImmutable::today()->addDays(3)->toDateString());
});

it('counts only spendable accounts', function () {
    liquidAccount($this->user, 30000, AccountType::Checking);
    liquidAccount($this->user, 20000, AccountType::Savings);
    liquidAccount($this->user, 500000, AccountType::Investment);
    liquidAccount($this->user, 900000, AccountType::RealEstate);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
        'interval_days' => 30,
    ]);

    expect($this->project->forUser($this->user, 30)['starting_balance'])->toBe(50000);
});

it('leaves other currencies out and says so', function () {
    liquidAccount($this->user, 100000);
    liquidAccount($this->user, 100000, AccountType::Checking, 'USD');

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
        'interval_days' => 30,
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -5000,
        'currency_code' => 'USD',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
        'interval_days' => 30,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['starting_balance'])->toBe(100000)
        ->and($forecast['occurrences'])->toHaveCount(1)
        ->and($forecast['other_currencies'])->toBe(['USD']);
});

it('ignores dismissed and lapsed series', function () {
    liquidAccount($this->user, 100000);

    RecurringSeries::factory()->ignored()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -9900,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);
    RecurringSeries::factory()->lapsed()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -8800,
        'currency_code' => 'EUR',
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['occurrences'])->toBeEmpty()
        ->and($forecast['ending_balance'])->toBe(100000);
});

it('rolls a stale expected date forward instead of reporting a past charge', function () {
    // Detection runs daily, so a series can be a few days out of date.
    liquidAccount($this->user, 100000);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->subDays(4),
        'interval_days' => 30,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['occurrences'])->toHaveCount(1)
        ->and($forecast['occurrences'][0]['date'])
        ->toBe(CarbonImmutable::today()->addDays(26)->toDateString());
});

it('copes with a user who has no accounts', function () {
    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['starting_balance'])->toBe(0)
        ->and($forecast['occurrences'])->toBeEmpty();
});
