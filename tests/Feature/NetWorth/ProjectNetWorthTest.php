<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Category;
use App\Models\LoanDetail;
use App\Models\RealEstateDetail;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetWorth\ProjectNetWorth;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->project = app(ProjectNetWorth::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->today = CarbonImmutable::today();
});

/** An account holding one balance as of today. */
function accountWorth(User $user, AccountType $type, int $balance, bool $includeInNetWorth = true): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'type' => $type,
        'currency_code' => 'EUR',
        'include_in_net_worth' => $includeInNetWorth,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => CarbonImmutable::today()->toDateString(),
        'balance' => $balance,
    ]);

    return $account;
}

/** Three complete months of irregular, non-recurring outflow. */
function spendingOverThreeMonths(User $user, Account $account): void
{
    $groceries = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Expense,
    ]);

    foreach ([1, 2, 3] as $monthsBack) {
        $month = CarbonImmutable::today()->startOfMonth()->subMonthsNoOverflow($monthsBack);

        foreach ([-4210, -6730, -3890, -5510] as $index => $amount) {
            Transaction::factory()->plaintext()->create([
                'user_id' => $user->id,
                'space_id' => $user->personalSpace->id,
                'account_id' => $account->id,
                'category_id' => $groceries->id,
                'description' => 'Supermercado '.$index,
                'transaction_date' => $month->addDays(2 + $index * 3)->toDateString(),
                'amount' => $amount,
                'currency_code' => 'EUR',
            ]);
        }
    }
}

/** The last forward point — where the projection is read. */
function horizonPoint(array $projection): array
{
    return $projection['points'][count($projection['points']) - 1];
}

it('adds assets and subtracts debt to reach today', function () {
    accountWorth($this->user, AccountType::Checking, 200000);
    accountWorth($this->user, AccountType::Investment, 1000000);
    accountWorth($this->user, AccountType::Loan, 500000);

    expect($this->project->forUser($this->user)['today'])->toBe(700000);
});

it('leaves credit cards out of the total entirely', function () {
    // Neither added nor subtracted: a card is a bill, not wealth.
    accountWorth($this->user, AccountType::Checking, 200000);
    accountWorth($this->user, AccountType::CreditCard, -45000);

    expect($this->project->forUser($this->user)['today'])->toBe(200000);
});

it('respects the exclude-from-net-worth setting', function () {
    accountWorth($this->user, AccountType::Checking, 200000);
    accountWorth($this->user, AccountType::Savings, 900000, includeInNetWorth: false);

    expect($this->project->forUser($this->user)['today'])->toBe(200000);
});

it('grows net worth as a mortgage amortises', function () {
    // No cash moves at all, so every euro of the rise is the debt shrinking.
    $mortgage = accountWorth($this->user, AccountType::Loan, 10000000);
    LoanDetail::factory()->create([
        'account_id' => $mortgage->id,
        'annual_interest_rate' => 3.0,
        'loan_term_months' => 240,
        'start_date' => $this->today->subYears(2)->toDateString(),
        'original_amount' => 12000000,
    ]);

    $projection = $this->project->forUser($this->user);

    expect($projection['committed'])->toBeGreaterThan($projection['today'])
        ->and($projection['drivers']['debt_repaid'])->toBe($projection['committed'] - $projection['today']);
});

it('revalues property at the rate on the account', function () {
    $flat = accountWorth($this->user, AccountType::RealEstate, 20000000);
    RealEstateDetail::factory()->create([
        'account_id' => $flat->id,
        'revaluation_percentage' => 3.0,
        'linked_loan_account_id' => null,
    ]);

    $growth = $this->project->forUser($this->user)['drivers']['property_growth'];

    // A year at 3% on 200,000 is about 6,000. The horizon lands on a month end
    // rather than the anniversary, so this is close rather than exact.
    expect($growth)->toBeGreaterThan(550000)->toBeLessThan(650000);
});

it('holds a property with no rate configured still', function () {
    $flat = accountWorth($this->user, AccountType::RealEstate, 20000000);
    RealEstateDetail::factory()->create([
        'account_id' => $flat->id,
        'revaluation_percentage' => null,
        'linked_loan_account_id' => null,
    ]);

    $projection = $this->project->forUser($this->user);

    expect($projection['drivers']['property_growth'])->toBe(0)
        ->and($projection['assumptions']['revalued_accounts'])->toBe([]);
});

it('holds investments and pensions at today’s value', function () {
    accountWorth($this->user, AccountType::Investment, 2400000);
    accountWorth($this->user, AccountType::Retirement, 980000);

    $projection = $this->project->forUser($this->user);

    expect($projection['committed'])->toBe($projection['today'])
        ->and($projection['assumptions']['investments_flat'])->toBeTrue();
});

it('walks recurring charges into the cash side', function () {
    $account = accountWorth($this->user, AccountType::Checking, 500000);
    RecurringSeries::factory()->cadence(RecurringCadence::Monthly)->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->personalSpace->id,
        'account_id' => $account->id,
        'display_name' => 'Netflix',
        'direction' => 'expense',
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => $this->today->addDays(3)->toDateString(),
    ]);

    $projection = $this->project->forUser($this->user);

    expect($projection['drivers']['recurring_net'])->toBeLessThan(0)
        ->and($projection['committed'])->toBeLessThan($projection['today']);
});

it('does not treat money moved into savings as money gone', function () {
    // Saving harder must not read as net worth falling. The cash leaves the
    // current account and the category says where it landed.
    $account = accountWorth($this->user, AccountType::Checking, 5000000);
    $savings = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Savings,
    ]);

    RecurringSeries::factory()->cadence(RecurringCadence::Monthly)->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->personalSpace->id,
        'account_id' => $account->id,
        'category_id' => $savings->id,
        'display_name' => 'Traspaso ahorro',
        'direction' => 'expense',
        'expected_amount' => -30000,
        'currency_code' => 'EUR',
        'next_expected_on' => $this->today->addDays(3)->toDateString(),
    ]);

    $projection = $this->project->forUser($this->user);

    expect($projection['drivers']['contributions'])->toBeGreaterThan(0)
        ->and($projection['committed'])->toBe($projection['today']);
});

it('draws the estimated line below the committed one once spending is known', function () {
    $account = accountWorth($this->user, AccountType::Checking, 5000000);
    spendingOverThreeMonths($this->user, $account);

    $projection = $this->project->forUser($this->user);

    expect($projection['estimated'])->not->toBeNull()
        ->and($projection['estimated'])->toBeLessThan($projection['committed'])
        ->and($projection['drivers']['everyday_spending'])->toBeLessThan(0)
        ->and($projection['assumptions']['months_observed'])->toBe(3);
});

it('says nothing about everyday spending without enough history', function () {
    accountWorth($this->user, AccountType::Checking, 5000000);

    $projection = $this->project->forUser($this->user);

    expect($projection['estimated'])->toBeNull()
        ->and($projection['drivers']['everyday_spending'])->toBeNull()
        ->and(horizonPoint($projection)['estimated'])->toBeNull();
});

it('joins the past to the future at today', function () {
    // Without all three values on the shared point the forward lines start
    // floating a month away from where the real one stops.
    accountWorth($this->user, AccountType::Checking, 200000);

    $projection = $this->project->forUser($this->user);
    $shared = collect($projection['points'])
        ->first(fn (array $point): bool => $point['actual'] !== null && $point['committed'] !== null);

    expect($shared['date'])->toBe($this->today->toDateString())
        ->and($shared['actual'])->toBe($projection['today'])
        ->and($shared['committed'])->toBe($projection['today']);
});

it('reports twelve months behind and twelve ahead', function () {
    accountWorth($this->user, AccountType::Checking, 200000);

    $projection = $this->project->forUser($this->user);
    $points = collect($projection['points']);

    expect($projection['months'])->toBe(12)
        ->and($points->whereNotNull('actual'))->toHaveCount(13)
        ->and($points->whereNotNull('committed'))->toHaveCount(13)
        ->and(horizonPoint($projection)['date'])
        ->toBe($this->today->startOfMonth()->addMonthsNoOverflow(11)->endOfMonth()->toDateString());
});

it('keeps another user’s wealth out of the total', function () {
    accountWorth($this->user, AccountType::Checking, 200000);
    accountWorth(User::factory()->create(['currency_code' => 'EUR']), AccountType::Investment, 9900000);

    expect($this->project->forUser($this->user)['today'])->toBe(200000);
});
