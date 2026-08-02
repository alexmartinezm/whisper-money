<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Features\RecurringTransactions;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetRunway;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Http::fake();
});

/**
 * @param  array<string, mixed>  $arguments
 */
function callRunwayTool(User $user, array $arguments = []): TestResponse
{
    $user->withAccessToken($user->createToken('mcp-runway-test', ['mcp:read'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool(GetRunway::class, $arguments);
}

function runwayAccount(User $user, int $balance, ?Space $space = null): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => ($space ?? $user->personalSpace)->id,
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
        'include_in_net_worth' => true,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => CarbonImmutable::today()->toDateString(),
        'balance' => $balance,
    ]);

    return $account;
}

it('walks the balance forward through the expected charges', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    runwayAccount($user, 100000);
    RecurringSeries::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Netflix',
        'expected_amount' => -1299,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);

    callRunwayTool($user)
        ->assertOk()
        ->assertSee('"starting_balance":100000')
        ->assertSee('"projected_balance":98701')
        ->assertSee('Netflix');
});

it('names the accounts behind the opening figure', function () {
    // "That is not my balance" should be answerable without another round trip.
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = runwayAccount($user, 40000);

    callRunwayTool($user)
        ->assertOk()
        ->assertSee('accounts_counted')
        ->assertSee($account->name);
});

it('reports the everyday spending estimate apart from the exact figures', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = runwayAccount($user, 300000);
    $groceries = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'type' => CategoryType::Expense,
    ]);

    foreach ([1, 2, 3] as $back) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'space_id' => $user->personalSpace->id,
            'account_id' => $account->id,
            'category_id' => $groceries->id,
            'transaction_date' => CarbonImmutable::today()
                ->startOfMonth()->subMonthsNoOverflow($back)->addDays(9)->toDateString(),
            'amount' => -60000,
            'currency_code' => 'EUR',
        ]);
    }

    callRunwayTool($user)
        ->assertOk()
        ->assertSee('"monthly":-60000')
        ->assertSee('"months_observed":3');
});

it('says nothing about spending it cannot measure', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    runwayAccount($user, 100000);

    callRunwayTool($user)
        ->assertOk()
        ->assertSee('"everyday_spending":null');
});

it('surfaces the budgets the pace will overshoot', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    runwayAccount($user, 100000);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'name' => 'Alimentación',
    ]);
    $start = CarbonImmutable::today()->subDays(9);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $start->toDateString(),
        'end_date' => $start->addDays(29)->toDateString(),
        'allocated_amount' => 50000,
        'carried_over_amount' => 0,
        'processing_historical' => false,
    ]);
    $period->budgetTransactions()->create([
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
        'amount' => 20000,
    ]);

    callRunwayTool($user)
        ->assertOk()
        ->assertSee('budgets_over_pace')
        ->assertSee('Alimentación');
});

it('reads the space it was asked for', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    // Owning the space is enough for accessibleSpaces(); no pivot row needed.
    $other = Space::factory()->create(['owner_id' => $user->id]);

    runwayAccount($user, 100000);
    runwayAccount($user, 777000, $other);

    callRunwayTool($user, ['space' => $other->id])
        ->assertOk()
        ->assertSee('"starting_balance":777000');
});

it('refuses when the feature is switched off for the user', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    Feature::for($user)->deactivate(RecurringTransactions::class);

    callRunwayTool($user)->assertSee('not enabled');
});
