<?php

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\SavingsGoal;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\Summaries;
use App\Services\MonthlySummary\SummaryBuilder;
use App\Services\SavingsGoals\MonthlySavingsGoalTotals;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Support\Carbon;

function surfacesUser(): User
{
    return User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
}

function surfacesGoal(User $user, array $attributes = [], int $target = 30000): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly($target)->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function surfacesSave(SavingsGoal $goal, int $amount, string $date): void
{
    Transaction::factory()->create([
        'user_id' => $goal->user_id,
        'account_id' => Account::factory()->create(['user_id' => $goal->user_id, 'type' => AccountType::Savings, 'currency_code' => 'EUR'])->id,
        'currency_code' => 'EUR',
        'amount' => $amount,
        'transaction_date' => $date,
    ])->labels()->attach($goal->label_id);
}

/**
 * Two goals started in August: one met September, the other fell short.
 *
 * @return array{0: User, 1: SavingsGoal, 2: SavingsGoal}
 */
function surfacesSeptember(): array
{
    test()->travelTo(Carbon::parse('2026-08-10'));
    $user = surfacesUser();
    $fund = surfacesGoal($user, ['name' => 'Emergency fund']);
    $trip = surfacesGoal($user, ['name' => 'Japan trip'], target: 20000);

    surfacesSave($fund, 30000, '2026-08-12');
    surfacesSave($fund, 32000, '2026-09-12');
    surfacesSave($trip, 15000, '2026-09-12');

    test()->travelTo(Carbon::parse('2026-10-03'));
    app(SavingsGoalPeriodService::class)->advance($fund);
    app(SavingsGoalPeriodService::class)->advance($trip);

    return [$user, $fund, $trip];
}

test('the dashboard lists running monthly goals only', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = surfacesUser();
    $running = surfacesGoal($user, ['name' => 'Emergency fund']);
    app(SavingsGoalService::class)->archive(surfacesGoal($user, ['name' => 'Old one']));
    SavingsGoal::factory()->create(['user_id' => $user->id, 'name' => 'House']);

    $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySavingsGoals',
    ])
        ->assertOk()
        ->assertJsonCount(1, 'props.monthlySavingsGoals')
        ->assertJsonPath('props.monthlySavingsGoals.0.id', $running->id)
        ->assertJsonPath('props.monthlySavingsGoals.0.monthly.current.month', '2026-10');
});

test('the dashboard sends no monthly goals to a user without any', function () {
    $user = surfacesUser();

    $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySavingsGoals',
    ])
        ->assertOk()
        ->assertJsonCount(0, 'props.monthlySavingsGoals');
});

test('the cashflow card adds every goal up for the month with the months before', function () {
    [$user] = surfacesSeptember();

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-09')
        ->assertJsonPath('data.saved', 47000)
        ->assertJsonPath('data.target', 50000)
        ->assertJsonPath('data.difference', -3000)
        ->assertJsonPath('data.met', 1)
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.status', 'missed')
        ->assertJsonPath('data.history.0.month', '2026-08')
        ->assertJsonPath('data.history.0.saved', 30000)
        ->assertJsonPath('data.history.1.month', '2026-09')
        ->assertJsonMissingPath('data.history.0.goals');
});

test('the cashflow card is empty for a month no goal had', function () {
    [$user] = surfacesSeptember();

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-06')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('the cashflow card never shows another user goals', function () {
    surfacesSeptember();

    $this->actingAs(surfacesUser())->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data', null);
});

test('the cashflow card wants a month', function (string $month) {
    $this->actingAs(surfacesUser())->getJson('/api/cashflow/monthly-savings?month='.$month)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');
})->with(['', '2026-13', '2026-09-01', 'september']);

test('the monthly summary judges each goal against that month', function () {
    [$user] = surfacesSeptember();

    $payload = app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true);

    expect($payload['monthly_goals'])->toMatchArray([
        'total' => 2,
        'met' => 1,
        'saved' => 47000,
        'target' => 50000,
    ])
        ->and($payload['monthly_goals']['goals'])->toBe([
            ['name' => 'Emergency fund', 'saved' => 32000, 'target' => 30000, 'difference' => 2000, 'met' => true, 'streak' => 2],
            ['name' => 'Japan trip', 'saved' => 15000, 'target' => 20000, 'difference' => -5000, 'met' => false, 'streak' => 0],
        ]);
});

test('the monthly summary has no monthly goals section without monthly goals', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = surfacesUser();
    SavingsGoal::factory()->create(['user_id' => $user->id]);

    $payload = app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true);

    expect($payload['monthly_goals'])->toBeNull();
});

test('the month in review page carries how each monthly goal landed', function () {
    [$user] = surfacesSeptember();

    // The fork renders the report from the frozen payload on the page itself.
    expect(app(Summaries::class)->freeze($user, Carbon::parse('2026-09-01')))->not->toBeNull();

    $this->actingAs($user)->get('/monthly-summary?period=2026-09')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('monthly-summary/index')
            ->where('summary.payload.monthly_goals.met', 1)
            ->where('summary.payload.monthly_goals.total', 2)
            ->where('summary.payload.monthly_goals.goals.0.name', 'Emergency fund')
            ->where('summary.payload.monthly_goals.goals.0.difference', 2000)
            ->where('summary.payload.monthly_goals.goals.1.name', 'Japan trip')
            ->where('summary.payload.monthly_goals.goals.1.difference', -5000));
});

test('the cashflow card counts goals reached so far in the month still running', function () {
    [$user, $fund] = surfacesSeptember();
    surfacesSave($fund, 30000, '2026-10-02');

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.status', 'in_progress')
        ->assertJsonPath('data.met', 1)
        ->assertJsonPath('data.total', 2);
});

test('a partial month adds nothing to the cashflow card or the monthly summary', function () {
    [$user] = surfacesSeptember();
    // Created on 28 September: September is partial for it.
    test()->travelTo(Carbon::parse('2026-09-28'));
    $late = surfacesGoal($user, ['name' => 'Late starter']);
    surfacesSave($late, 9000, '2026-09-29');
    test()->travelTo(Carbon::parse('2026-10-03'));
    app(SavingsGoalPeriodService::class)->advance($late);

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonPath('data.saved', 47000)
        ->assertJsonPath('data.target', 50000)
        ->assertJsonPath('data.total', 2);

    $payload = app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true);

    expect($payload['monthly_goals']['total'])->toBe(2)
        ->and(array_column($payload['monthly_goals']['goals'], 'name'))->not->toContain('Late starter');
});

test('the cashflow card only adds up the months it shows', function () {
    [$user] = surfacesSeptember();

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertOk()
        ->assertJsonCount(2, 'data.history');

    expect(app(MonthlySavingsGoalTotals::class)->forMonth($user, Carbon::parse('2026-09-01'), historyMonths: 1)['history'])
        ->toHaveCount(1);
});

test('the dashboard gets this month for each goal, nothing more', function () {
    [$user] = surfacesSeptember();

    $response = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'monthlySavingsGoals,monthlySavingsLastMonth',
    ])->assertOk();

    expect($response->json('props.monthlySavingsGoals.0'))->toHaveKeys(['id', 'name', 'monthly'])
        ->not->toHaveKeys(['user', 'periods', 'label'])
        ->and(array_keys($response->json('props.monthlySavingsGoals.0.monthly')))->toBe(['current'])
        ->and($response->json('props.monthlySavingsGoals.0.monthly.current.month'))->toBe('2026-10')
        ->and($response->json('props.monthlySavingsLastMonth'))->toBe(['month' => '2026-09', 'met' => 1, 'total' => 2]);
});

test('every surface counts last month the same way after an archive', function () {
    [$user, , $trip] = surfacesSeptember();
    app(SavingsGoalService::class)->archive($trip);

    $this->actingAs($user)->get('/budgets')
        ->assertInertia(fn ($page) => $page->where('monthlySavingsLastMonth', ['month' => '2026-09', 'met' => 1, 'total' => 2]));

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-09')
        ->assertJsonPath('data.met', 1)
        ->assertJsonPath('data.total', 2);

    expect(app(SummaryBuilder::class)->build($user, Carbon::parse('2026-09-01'), complete: true)['monthly_goals'])
        ->toMatchArray(['met' => 1, 'total' => 2]);
});

test('the cashflow card keeps the history for a month no goal was judged in', function () {
    [$user] = surfacesSeptember();

    $this->travelTo(Carbon::parse('2027-01-10'));

    // Archived in October: nothing is judged after September.
    SavingsGoal::query()->where('user_id', $user->id)->get()
        ->each(fn (SavingsGoal $goal) => $goal->forceFill(['archived_at' => '2026-10-31 12:00:00'])->save());

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-12')
        ->assertOk()
        ->assertJsonPath('data.month', '2026-12')
        ->assertJsonPath('data.total', 0)
        ->assertJsonPath('data.status', null)
        ->assertJsonPath('data.history.0.month', '2026-08');
});

test('a month whose every target follows an income not yet in is pending, not reached', function () {
    $this->travelTo(Carbon::parse('2026-10-03'));
    $user = surfacesUser();
    surfacesGoal($user, ['monthly_target_type' => 'income_rate', 'monthly_target_amount' => null, 'monthly_target_rate' => 20]);

    $this->actingAs($user)->getJson('/api/cashflow/monthly-savings?month=2026-10')
        ->assertOk()
        ->assertJsonPath('data.target', 0)
        ->assertJsonPath('data.target_pending', true)
        ->assertJsonPath('data.met', 0);
});
