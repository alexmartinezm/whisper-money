<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Laravel\Fortify\Features;

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
        ->assertJsonCount(1, 'props.topCategories')
        ->assertJsonPath('props.topCategories.0.category.id', $food->id)
        ->assertJsonPath('props.topCategories.0.amount', 3000);
});

test('dashboard cashflow summary uses user month start day', function () {
    $this->travelTo('2026-02-10');

    $user = User::factory()->onboarded()->create(['month_start_day' => 25]);
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
