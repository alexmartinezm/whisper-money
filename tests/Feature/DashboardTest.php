<?php

use App\Enums\CategoryType;
use App\Features\CustomMonthStartDay;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Fortify\Features;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['landing.hide_auth_buttons' => false]);
});

test('new guests are redirected to the registration page', function () {
    $this->get(route('dashboard'))->assertRedirect(route('register'));
});

test('returning guests are redirected to the login page', function () {
    $this
        ->withCookie('whisper_money_returning_user', '1')
        ->get(route('dashboard'))
        ->assertRedirect(route('login'));
});

test('new guests are redirected to the login page when registration is disabled', function () {
    config([
        'fortify.features' => array_values(array_filter(
            config('fortify.features'),
            fn (string $feature): bool => $feature !== Features::registration(),
        )),
    ]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('new guests are redirected to the login page when auth buttons are hidden', function () {
    config(['landing.hide_auth_buttons' => true]);

    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

test('new guests with landing auth override are redirected to the registration page', function () {
    config(['landing.hide_auth_buttons' => true]);

    $this
        ->withCookie(config('landing.auth_override.cookie_name'), '1')
        ->get(route('dashboard'))
        ->assertRedirect(route('register'));
});

test('authenticated users can visit the dashboard', function () {
    $this->actingAs(User::factory()->onboarded()->create());

    $this->get(route('dashboard'))->assertOk();
});

test('dashboard top categories roll child spending up into the parent', function () {
    $user = User::factory()->onboarded()->create();
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['user_id' => $user->id, 'name' => 'Groceries']);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $food->id,
        'amount' => -1000,
        'transaction_date' => now(),
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $groceries->id,
        'amount' => -2000,
        'transaction_date' => now(),
    ]);

    $response = $this->actingAs($user)->withoutVite()->get(route('dashboard'), [
        'X-Inertia' => 'true',
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'topCategories',
    ]);

    $response->assertOk()
        ->assertJsonCount(1, 'props.topCategories.categories')
        ->assertJsonPath('props.topCategories.categories.0.category.id', $food->id)
        ->assertJsonPath('props.topCategories.categories.0.amount', 3000);
});

test('dashboard cashflow summary uses user month start day', function () {
    $this->travelTo('2026-02-10');

    $user = User::factory()->onboarded()->create(['month_start_day' => 25]);
    Feature::for($user)->activate(CustomMonthStartDay::class);
    $this->actingAs($user);

    $incomeCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Income,
    ]);

    foreach ([
        ['date' => '2026-01-24', 'amount' => 10000],
        ['date' => '2026-01-25', 'amount' => 20000],
        ['date' => '2026-02-24', 'amount' => 30000],
    ] as $transaction) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'amount' => $transaction['amount'],
            'transaction_date' => $transaction['date'],
        ]);
    }

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->missing('cashflowSummary')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('cashflowSummary.current.income', 50000)
                ->where('cashflowSummary.previous.income', 10000)
            )
        );
});

test('dashboard previous period stays aligned across a short salary month', function () {
    // Current period Feb 25 - Mar 24 is only 28 days; the previous period must
    // still be the full Jan 25 - Feb 24 salary month, not a 28-day count back.
    $this->travelTo('2026-03-10');

    $user = User::factory()->onboarded()->create(['month_start_day' => 25]);
    Feature::for($user)->activate(CustomMonthStartDay::class);
    $this->actingAs($user);

    $incomeCategory = Category::factory()->create([
        'user_id' => $user->id,
        'type' => CategoryType::Income,
    ]);

    foreach ([
        ['date' => '2026-01-25', 'amount' => 10000], // previous period opening day
        ['date' => '2026-02-24', 'amount' => 20000], // previous period closing day
        ['date' => '2026-02-25', 'amount' => 40000], // current period opening day
        ['date' => '2026-03-10', 'amount' => 30000], // current period
    ] as $transaction) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'category_id' => $incomeCategory->id,
            'amount' => $transaction['amount'],
            'transaction_date' => $transaction['date'],
        ]);
    }

    $response = $this->get(route('dashboard'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('cashflowSummary.current.income', 70000)
                ->where('cashflowSummary.previous.income', 30000)
            )
        );
});
