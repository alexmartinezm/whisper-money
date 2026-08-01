<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\ProjectCashflow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->project = app(ProjectCashflow::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
});

/** An account holding a known balance as of today. */
function accountWithBalance(
    User $user,
    int $balance,
    AccountType $type = AccountType::Checking,
    string $currency = 'EUR',
    bool $includeInNetWorth = true,
    bool $hiddenOnDashboard = false,
): Account {
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'currency_code' => $currency,
        'type' => $type,
        'include_in_net_worth' => $includeInNetWorth,
        'hidden_on_dashboard' => $hiddenOnDashboard,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => CarbonImmutable::today()->toDateString(),
        'balance' => $balance,
    ]);

    return $account;
}

it('walks today balance forward through the expected charges', function () {
    accountWithBalance($this->user, 100000);

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
    accountWithBalance($this->user, 50000);

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
    accountWithBalance($this->user, 10000);

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

it('counts only the accounts a direct debit can come out of', function () {
    // A runway is about liquidity, not wealth: a flat cannot pay Netflix, and
    // a mortgage would drag the figure below zero every day of the year.
    accountWithBalance($this->user, 30000, AccountType::Checking);
    accountWithBalance($this->user, 20000, AccountType::Savings);
    accountWithBalance($this->user, 5000, AccountType::Others);
    accountWithBalance($this->user, 500000, AccountType::Investment, 'EUR', true, true);
    accountWithBalance($this->user, 900000, AccountType::RealEstate);
    accountWithBalance($this->user, 100000, AccountType::Loan);
    accountWithBalance($this->user, 400000, AccountType::CreditCard);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
        'interval_days' => 30,
    ]);

    expect($this->project->forUser($this->user, 30)['starting_balance'])->toBe(55000);
});

it('counts a spendable account the user left out of net worth', function () {
    // That flag answers "am I getting richer", which is a different question
    // from "can this account pay a bill on Thursday".
    accountWithBalance($this->user, 40000, AccountType::Checking, 'EUR', false);

    expect($this->project->forUser($this->user, 30)['starting_balance'])->toBe(40000);
});

it('names the accounts behind the figure', function () {
    // "It does not match my accounts" is answered by saying which ones count.
    $checking = accountWithBalance($this->user, 30000, AccountType::Checking);
    accountWithBalance($this->user, 900000, AccountType::RealEstate);

    $accounts = $this->project->forUser($this->user, 30)['accounts'];

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['id'])->toBe($checking->id)
        ->and($accounts[0]['name'])->toBe($checking->name);
});

it('converts a second currency instead of dropping it', function () {
    accountWithBalance($this->user, 100000);
    accountWithBalance($this->user, 100000, AccountType::Checking, 'USD', false);

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

    // Balances are converted; series amounts still are not, so the screen says
    // which currencies it is choosing not to project.
    expect($forecast['starting_balance'])->toBe(200000)
        ->and($forecast['occurrences'])->toHaveCount(1)
        ->and($forecast['other_currencies'])->toBe(['USD']);
});

it('ignores dismissed and lapsed series', function () {
    accountWithBalance($this->user, 100000);

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
    accountWithBalance($this->user, 100000);

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

it('surfaces a quarterly charge that never lands inside the window', function () {
    // The gap this list exists for: detected, but shown against no date before.
    accountWithBalance($this->user, 100000);

    RecurringSeries::factory()->cadence(RecurringCadence::Quarterly)->create([
        'user_id' => $this->user->id,
        'display_name' => 'Seguro Coche',
        'expected_amount' => -14550,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(66),
        'interval_days' => 91,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['occurrences'])->toBeEmpty();

    $months = collect($forecast['later']);
    $month = $months->firstWhere('month', CarbonImmutable::today()->addDays(66)->format('Y-m'));

    expect($month)->not->toBeNull()
        ->and($month['charges'][0]['display_name'])->toBe('Seguro Coche')
        ->and($month['charges'][0]['amount'])->toBe(-14550)
        ->and($month['charges'][0]['date'])
        ->toBe(CarbonImmutable::today()->addDays(66)->toDateString());
});

it('leaves out anything already billing every month', function () {
    // Twelve months of Netflix, Spotify and the gym would bury the one premium
    // this list exists for, and the projection above already shows them.
    accountWithBalance($this->user, 100000);

    RecurringSeries::factory()->cadence(RecurringCadence::Monthly)->create([
        'user_id' => $this->user->id,
        'display_name' => 'Netflix',
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);
    RecurringSeries::factory()->cadence(RecurringCadence::Weekly)->create([
        'user_id' => $this->user->id,
        'display_name' => 'Gimnasio',
        'expected_amount' => -1000,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDay(),
        'interval_days' => 7,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    expect($forecast['occurrences'])->not->toBeEmpty()
        ->and($forecast['later'])->toBeEmpty();
});

it('lists only the months that have something due', function () {
    accountWithBalance($this->user, 100000);

    RecurringSeries::factory()->cadence(RecurringCadence::Yearly)->create([
        'user_id' => $this->user->id,
        'display_name' => 'Dominio',
        'expected_amount' => -1200,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(90),
        'interval_days' => 365,
    ]);

    // Eleven empty cards would be chrome saying nothing.
    expect($this->project->forUser($this->user, 30)['later'])->toHaveCount(1);
});

it('orders the charges by the day they land', function () {
    accountWithBalance($this->user, 100000);

    foreach ([['Later', 58], ['Sooner', 40], ['Middle', 50]] as [$name, $offset]) {
        RecurringSeries::factory()->cadence(RecurringCadence::Yearly)->create([
            'user_id' => $this->user->id,
            'display_name' => $name,
            'expected_amount' => -1000,
            'currency_code' => 'EUR',
            'next_expected_on' => CarbonImmutable::today()->addDays($offset),
            'interval_days' => 365,
        ]);
    }

    $charges = collect($this->project->forUser($this->user, 30)['later'])
        ->flatMap(fn (array $month): array => $month['charges']);

    // A yearly charge comes round again before the outlook ends, so compare the
    // order the names first appear in rather than the raw list.
    expect($charges->pluck('display_name')->unique()->values()->all())
        ->toBe(['Sooner', 'Middle', 'Later']);
});

it('keeps the outlook clear of the projection window', function () {
    accountWithBalance($this->user, 100000);

    RecurringSeries::factory()->cadence(RecurringCadence::Quarterly)->create([
        'user_id' => $this->user->id,
        'display_name' => 'Seguro Coche',
        'expected_amount' => -14550,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 91,
    ]);

    $forecast = $this->project->forUser($this->user, 30);
    $firstOutlookDate = collect($forecast['later'])->first()['charges'][0]['date'];

    // Nothing is counted twice: the outlook starts after the window ends.
    expect($forecast['occurrences'])->toHaveCount(1)
        ->and($firstOutlookDate)->toBe(
            CarbonImmutable::today()->addDays(96)->toDateString(),
        );
});

it('totals a month rather than restating a lone charge', function () {
    accountWithBalance($this->user, 100000);

    // Anchored to a month start so both charges are guaranteed the same month.
    $monthStart = CarbonImmutable::today()->addDays(60)->startOfMonth();

    foreach ([-14550, -3000] as $index => $amount) {
        RecurringSeries::factory()->cadence(RecurringCadence::Yearly)->create([
            'user_id' => $this->user->id,
            'display_name' => "Anual {$index}",
            'expected_amount' => $amount,
            'currency_code' => 'EUR',
            'next_expected_on' => $monthStart->addDays(5 + $index),
            'interval_days' => 365,
        ]);
    }

    $month = collect($this->project->forUser($this->user, 30)['later'])->first();

    expect($month['total'])->toBe(-17550);
});

it('walks the window again with everyday spending in it', function () {
    $account = accountWithBalance($this->user, 300000);
    $groceries = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    foreach ([1, 2, 3] as $back) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $groceries->id,
            'transaction_date' => CarbonImmutable::today()
                ->startOfMonth()->subMonthsNoOverflow($back)->addDays(9)->toDateString(),
            'amount' => -60000,
            'currency_code' => 'EUR',
        ]);
    }

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);

    $forecast = $this->project->forUser($this->user, 30);

    // The exact figures stay exact: they reconcile against the list below.
    expect($forecast['ending_balance'])->toBe(298701)
        ->and($forecast['spending']['monthly'])->toBe(-60000)
        ->and($forecast['spending']['months_observed'])->toBe(3)
        // A month of groceries on top of the subscription.
        ->and($forecast['spending']['ending_balance'])->toBe(238701);
});

it('warns on the estimate, where the dip actually happens', function () {
    $account = accountWithBalance($this->user, 50000);
    $groceries = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    foreach ([1, 2, 3] as $back) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $account->id,
            'category_id' => $groceries->id,
            'transaction_date' => CarbonImmutable::today()
                ->startOfMonth()->subMonthsNoOverflow($back)->addDays(9)->toDateString(),
            'amount' => -90000,
            'currency_code' => 'EUR',
        ]);
    }

    $forecast = $this->project->forUser($this->user, 30);

    // Nothing recurring is due, so the exact walk sees no problem at all —
    // while the month's shopping empties the account before it is out.
    expect($forecast['lowest']['balance'])->toBe(50000)
        ->and($forecast['spending']['lowest']['balance'])->toBeLessThan(0)
        ->and($forecast['spending']['lowest']['date'])
        ->toBe(CarbonImmutable::today()->addDays(30)->toDateString());
});

it('stays quiet about spending it cannot measure', function () {
    accountWithBalance($this->user, 100000);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);

    expect($this->project->forUser($this->user, 30)['spending'])->toBeNull();
});
