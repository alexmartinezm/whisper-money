<?php

use App\Enums\AccountType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Features\RecurringTransactions;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\GetRunway;
use App\Mcp\Tools\ListSavingsGoals;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\SavingsGoal;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use Carbon\Carbon;
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/*
 * One user, one instant, every surface: the screens, the public API and the MCP
 * tools answer from the same month. The months below are the ones that user is
 * living in, so a surface still on the server's date gives itself away on the
 * clocks that sit a day away from UTC.
 */

beforeEach(function () {
    fakeCurrencyApi();
    $this->withoutVite();
});

afterEach(function () {
    Carbon::setTestNow();
});

dataset('clocks at the turn of the month', [
    'Los Angeles, still October' => ['America/Los_Angeles', '2026-11-01 00:30:00', '2026-10'],
    'Auckland, already November' => ['Pacific/Auckland', '2026-10-31 12:00:00', '2026-11'],
    'Madrid, already November' => ['Europe/Madrid', '2026-10-31 23:30:00', '2026-11'],
    'UTC' => ['UTC', '2026-11-01 00:30:00', '2026-11'],
    'no timezone' => [null, '2026-11-01 00:30:00', '2026-11'],
    'an invalid timezone' => ['Mars/Olympus_Mons', '2026-11-01 00:30:00', '2026-11'],
]);

function agreementUser(?string $timezone, string $now): User
{
    Carbon::setTestNow(Carbon::parse($now));

    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'timezone' => $timezone]);
}

function agreementBudget(User $user): Budget
{
    $budget = Budget::factory()->create([
        'user_id' => $user->id,
        'period_type' => BudgetPeriodType::Monthly,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::Reset,
    ]);

    foreach ([['2026-10-01', '2026-10-31', 10_000], ['2026-11-01', '2026-11-30', 40_000]] as [$start, $end, $allocated]) {
        BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $end,
            'allocated_amount' => $allocated,
            'processing_historical' => false,
        ]);
    }

    return $budget;
}

function agreementInvestedAccount(User $user): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'type' => AccountType::Investment,
        'currency_code' => 'EUR',
    ]);
    AccountBalance::factory()->withInvestedAmount(100)->create(['account_id' => $account->id, 'balance_date' => '2026-10-01']);
    AccountBalance::factory()->withInvestedAmount(200)->create(['account_id' => $account->id, 'balance_date' => '2026-11-01']);

    return $account;
}

function agreementInertiaProp(User $user, string $prop): mixed
{
    return test()->actingAs($user)->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => $prop,
    ])->assertOk()->json("props.{$prop}");
}

it('reads the same month of a savings goal on every surface', function (?string $timezone, string $now, string $month) {
    $user = agreementUser($timezone, '2026-10-05 12:00:00');
    $goal = SavingsGoal::factory()->monthly()->create(['user_id' => $user->id]);
    app(SavingsGoalPeriodService::class)->advance($goal);
    Carbon::setTestNow(Carbon::parse($now));
    $user->refresh();

    $this->actingAs($user)->get(route('budgets.index'))
        ->assertInertia(fn ($page) => $page->where('monthlySavingsGoals.0.monthly.current.month', $month));

    expect(agreementInertiaProp($user, 'monthlySavingsGoals')[0]['monthly']['current']['month'])->toBe($month);

    withHeaders(apiV1Bearer($user))->getJson('/api/v1/savings-goals')
        ->assertOk()
        ->assertJsonPath('data.0.monthly.current.month', $month);

    withHeaders(apiV1Bearer($user))->getJson("/api/v1/savings-goals/{$goal->id}")
        ->assertOk()
        ->assertJsonPath('data.monthly.current.month', $month);

    $listed = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(ListSavingsGoals::class)->assertOk());

    expect($listed['savings_goals'][0]['monthly']['current']['month'])->toBe($month);
})->with('clocks at the turn of the month');

it('holds back the same budget margin on every surface', function (?string $timezone, string $now, string $month) {
    $user = agreementUser($timezone, $now);
    agreementBudget($user);
    $reserved = $month === '2026-10' ? 10_000 : 40_000;
    $range = ['from' => '2026-10-01', 'to' => '2026-11-30'];

    $this->actingAs($user)->getJson('/api/cashflow/summary?'.http_build_query($range))
        ->assertOk()
        ->assertJsonPath('current.reserved', $reserved);

    withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/cashflow?'.http_build_query($range))
        ->assertOk()
        ->assertJsonPath('data.summary.current.reserved', $reserved);

    $cashflow = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetCashflow::class, $range)->assertOk());

    expect($cashflow['summary']['current']['reserved'])->toBe($reserved);
})->with('clocks at the turn of the month');

it('reads the same invested amount on every surface', function (?string $timezone, string $now, string $month) {
    $user = agreementUser($timezone, $now);
    $account = agreementInvestedAccount($user);
    $invested = $month === '2026-10' ? 100 : 200;
    $range = ['from' => '2026-06-01', 'to' => '2026-11-30'];

    expect(agreementInertiaProp($user, 'netWorthEvolution')['accounts'][$account->id]['invested_amount'])->toBe($invested);

    $this->actingAs($user)->getJson('/api/dashboard/net-worth-evolution?'.http_build_query($range))
        ->assertOk()
        ->assertJsonPath("accounts.{$account->id}.invested_amount", $invested);

    $netWorth = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetNetWorth::class, $range)->assertOk());

    expect($netWorth['evolution']['accounts'][$account->id]['invested_amount'])->toBe($invested);
})->with('clocks at the turn of the month');

it('warns about the same pace on the recurring page and the runway', function (?string $timezone, string $now, string $month) {
    $user = agreementUser($timezone, $now);
    Feature::for($user)->activate(RecurringTransactions::class);
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    // One long period, so both clocks read it: 45 days, 9,000 spent of 10,000.
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-11-14',
        'allocated_amount' => 10_000,
        'processing_historical' => false,
    ]);
    BudgetTransaction::factory()->create(['budget_period_id' => $period->id, 'amount' => 9_000]);
    $projected = (int) round(9_000 / ($month === '2026-10' ? 31 : 32) * 45);

    $this->actingAs($user)->get(route('recurring.index'))
        ->assertInertia(fn ($page) => $page->where('budgetPace.0.projected', $projected));

    $user->withAccessToken($user->createToken('mcp-agreement', ['mcp:read'])->accessToken);
    $runway = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetRunway::class)->assertOk());

    expect($runway['budgets_over_pace'][0]['projected'])->toBe($projected);
})->with('clocks at the turn of the month');

it('opens every screen for a user whatever their stored timezone', function (?string $timezone) {
    $user = agreementUser($timezone, '2026-11-01 00:30:00');
    Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::CreditCard, 'currency_code' => 'EUR']);
    $budget = agreementBudget($user);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
    $this->actingAs($user)->get(route('accounts.list'))->assertOk();
    $this->actingAs($user)->get(route('budgets.index'))->assertOk();
    $this->actingAs($user)->get(route('budgets.show', $budget))->assertOk();

    expect(agreementInertiaProp($user, 'creditCardUsage'))->toBeArray();
})->with(['no timezone' => null, 'an invalid timezone' => 'Mars/Olympus_Mons', 'an empty one' => '']);
