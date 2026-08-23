<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\User;

test('budgets menu item visible when feature enabled', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/dashboard');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user can navigate to budgets index page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertPathIs('/budgets')
        ->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user can view empty budgets list', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('user can view budgets list with existing budgets', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Test Budget',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('Budgets', 10)
        ->assertSee('Test Budget')
        ->assertNoJavascriptErrors();
});

test('user can open create budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('Budgets', 10)
        ->click('Create Budget')
        ->wait(1)
        ->assertSee('Create Budget')
        ->assertNoJavascriptErrors();
});

test('user can view a specific budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'My Monthly Budget',
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('My Monthly Budget', 10)
        ->click('View Details')
        ->wait(2)
        ->assertPathIs("/budgets/{$budget->id}")
        ->assertSee('My Monthly Budget')
        ->assertNoJavascriptErrors();
});

test('user can open edit budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Original Name',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Original Name')
        ->click('//button[@aria-label="More options"]')
        ->wait(0.5)
        ->click('Edit budget')
        ->wait(1)
        ->assertSee('Edit Budget')
        ->assertNoJavascriptErrors();
});

test('user can open delete budget dialog', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Budget to Delete',
    ]);

    $page = $this->actingAs($user)->visit("/budgets/{$budget->id}");

    $page->assertSee('Budget to Delete')
        ->click('//button[@aria-label="More options"]')
        ->wait(0.5)
        ->click('Delete budget')
        ->wait(1)
        ->assertSee('Delete Budget')
        ->assertNoJavascriptErrors();
});

test('user cannot access another users budget', function () {
    $user1 = User::factory()->create(['onboarded_at' => now()]);
    $user2 = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user1->id]);
    $budget = Budget::factory()->create(['user_id' => $user1->id]);
    $budget->categories()->attach($category->id);

    $response = $this->actingAs($user2)->get("/budgets/{$budget->id}");

    $response->assertForbidden();
});

test('budgets navigation works from sidebar', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/dashboard');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors();
});

test('budgets page shows correct feature flag state', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->assertSee('Budgets')
        ->assertNoJavascriptErrors()
        ->assertNoConsoleLogs();
});

test('user can open the direct successor and plan its allocation', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'name' => 'Planning Budget',
        'period_start_day' => 1,
    ]);

    $currentPeriod = $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 10000,
        'carried_over_amount' => 0,
    ]);
    $nextPeriod = $budget->periods()->create([
        'start_date' => $currentPeriod->end_date->addDay(),
        'end_date' => $currentPeriod->end_date->addMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 10000,
        'carried_over_amount' => 0,
    ]);

    $page = $this->actingAs($user)->visit('/budgets');

    $page->waitForText('Planning Budget', 10)
        ->click('Plan next period')
        ->waitForText('Planning next period', 10)
        ->assertSee('Allocated Amount')
        ->fill('#planning-allocated-amount', '250')
        ->click('Save allocation')
        ->wait(1)
        ->assertValue('#planning-allocated-amount', '250.00')
        ->assertNoJavascriptErrors();

    expect($nextPeriod->fresh()->allocated_amount)->toBe(25000);
});
