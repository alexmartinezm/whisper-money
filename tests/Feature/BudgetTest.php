<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;

test('user can create a budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 100000,
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'user_id' => $user->id,
        'name' => 'Monthly Budget',
        'period_type' => 'monthly',
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertTrue($budget->categories->pluck('id')->contains($category->id));
    $this->assertCount(2, $budget->periods);
});

test('user can create a yearly budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Yearly Budget',
        'period_type' => 'yearly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 1200000,
    ]);

    $response->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->where('period_type', 'yearly')->first();

    $currentPeriod = $budget->getCurrentPeriod();

    expect($budget)->not->toBeNull()
        ->and($budget->periods()->count())->toBe(2)
        ->and($currentPeriod->start_date->toDateString())->toBe(now()->startOfYear()->toDateString())
        ->and($currentPeriod->end_date->toDateString())->toBe(now()->endOfYear()->toDateString());
});

test('user can view their budgets', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get('/budgets');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/index')
        ->has('budgets', 1)
    );
});

test('budget index returns an aggregate summary with catch-all separated', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'allocated_amount' => 100000,
        'carried_over_amount' => 20000,
    ]);

    BudgetTransaction::create([
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
        'budget_period_id' => $period->id,
        'amount' => 30000,
    ]);

    $catchAll = Budget::factory()->monthly()->catchAll()->create(['user_id' => $user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $catchAll->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
        'allocated_amount' => 50000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get(route('budgets.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/index')
        ->where('budgetSummary.budgets_count', 1)
        ->where('budgetSummary.total_allocated', 100000)
        ->where('budgetSummary.total_carried_over', 20000)
        ->where('budgetSummary.total_available', 120000)
        ->where('budgetSummary.total_spent', 30000)
        ->where('budgetSummary.total_remaining', 90000)
        ->where('budgetSummary.over_limit_count', 0)
        ->where('budgetSummary.groups.0.total_available', 120000)
        ->where('budgetSummary.catch_all.budgets_count', 1)
        ->where('budgetSummary.catch_all.total_available', 50000)
    );
});

test('overlapping budget assignments are summed as budget consumption', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $transaction = Transaction::factory()->create(['user_id' => $user->id]);

    foreach ([60000, 40000] as $allocatedAmount) {
        $budget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
        $period = BudgetPeriod::factory()->create([
            'budget_id' => $budget->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'allocated_amount' => $allocatedAmount,
            'carried_over_amount' => 0,
        ]);

        BudgetTransaction::create([
            'transaction_id' => $transaction->id,
            'budget_period_id' => $period->id,
            'amount' => 20000,
        ]);
    }

    $response = $this->actingAs($user)->get(route('budgets.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/index')
        ->where('budgetSummary.budgets_count', 2)
        ->where('budgetSummary.total_available', 100000)
        ->where('budgetSummary.total_spent', 40000)
        ->where('budgetSummary.total_remaining', 60000)
        ->where('budgetSummary.groups.0.total_spent', 40000)
        ->where('budgetSummary.catch_all', null)
    );
});

test('budget status thresholds use exact amounts instead of rounded percentages', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $nearBudget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    $nearPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $nearBudget->id,
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 10000,
        'carried_over_amount' => 0,
    ]);
    BudgetTransaction::create([
        'budget_period_id' => $nearPeriod->id,
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
        'amount' => 8999,
    ]);

    $almostFullBudget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    $almostFullPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $almostFullBudget->id,
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 10000,
        'carried_over_amount' => 0,
    ]);
    BudgetTransaction::create([
        'budget_period_id' => $almostFullPeriod->id,
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
        'amount' => 9000,
    ]);

    $rolloverBudget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    $rolloverPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $rolloverBudget->id,
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
        'allocated_amount' => 10000,
        'carried_over_amount' => 5000,
    ]);
    BudgetTransaction::create([
        'budget_period_id' => $rolloverPeriod->id,
        'transaction_id' => Transaction::factory()->create(['user_id' => $user->id])->id,
        'amount' => 13500,
    ]);

    $this->actingAs($user)
        ->get(route('budgets.index'))
        ->assertInertia(fn ($page) => $page
            ->where('budgetSummary.over_limit_count', 0)
            ->where('budgetSummary.close_to_limit_count', 2)
            ->where('budgetSummary.status', 'on_track')
            ->where('budgetSummary.groups.0.over_limit_count', 0)
            ->where('budgetSummary.groups.0.close_to_limit_count', 2)
        );
});

test('budget summary groups use a stable cadence order', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $weeklyBudget = Budget::factory()->weekly()->create(['user_id' => $user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $weeklyBudget->id,
        'start_date' => today()->startOfWeek(),
        'end_date' => today()->endOfWeek(),
    ]);

    $monthlyBudget = Budget::factory()->monthly()->create(['user_id' => $user->id]);
    BudgetPeriod::factory()->create([
        'budget_id' => $monthlyBudget->id,
        'start_date' => today()->startOfMonth(),
        'end_date' => today()->endOfMonth(),
    ]);

    $this->actingAs($user)
        ->get(route('budgets.index'))
        ->assertInertia(fn ($page) => $page
            ->has('budgetSummary.groups', 2)
            ->where('budgetSummary.groups.0.period_type', 'monthly')
            ->where('budgetSummary.groups.1.period_type', 'weekly')
        );
});

test('user can view a specific budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('budget')
        ->has('currentPeriod')
    );
});

test('budget show serializes split details for assigned transactions', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $food = Category::factory()->create(['user_id' => $user->id]);
    $gifts = Category::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->monthly()->forCategories($food)->create(['user_id' => $user->id]);
    $account = Account::factory()->create(['user_id' => $user->id]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
    ]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -1000,
    ]);

    $transaction->splits()->createMany([
        ['category_id' => $food->id, 'amount' => -700, 'position' => 0],
        ['category_id' => $gifts->id, 'amount' => -300, 'position' => 1],
    ]);
    BudgetTransaction::create([
        'budget_period_id' => $period->id,
        'transaction_id' => $transaction->id,
        'amount' => -700,
    ]);

    $this->actingAs($user)
        ->get(route('budgets.show', $budget))
        ->assertInertia(fn ($page) => $page
            ->where('currentPeriod.budget_transactions.0.transaction.id', $transaction->id)
            ->where('currentPeriod.budget_transactions.0.transaction.category_id', null)
            ->has('currentPeriod.budget_transactions.0.transaction.splits', 2)
            ->where('currentPeriod.budget_transactions.0.transaction.splits.0.category.id', $food->id)
            ->where('currentPeriod.budget_transactions.0.transaction.splits.1.category.id', $gifts->id)
        );
});

test('budget show exposes tracking options only from the budget space', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $space = $user->personalSpace;
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    Category::factory()->create(['user_id' => $user->id, 'space_id' => $otherSpace->id]);
    Label::factory()->create(['user_id' => $user->id, 'space_id' => $otherSpace->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);

    $this->actingAs($user)
        ->get("/budgets/{$budget->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('categories', 1)
            ->where('categories.0.id', $category->id)
            ->has('labels', 1)
            ->where('labels.0.id', $label->id));
});

test('user cannot view another users budget', function () {
    $user1 = User::factory()->create(['onboarded_at' => now()]);
    $user2 = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create(['user_id' => $user1->id]);

    $response = $this->actingAs($user2)->get("/budgets/{$budget->id}");

    $response->assertForbidden();
});

test('user can update their budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->patch("/budgets/{$budget->id}", [
        'name' => 'Updated Budget Name',
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('budgets', [
        'id' => $budget->id,
        'name' => 'Updated Budget Name',
    ]);
});

test('user can update budget categories and labels', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $oldCategory = Category::factory()->create(['user_id' => $user->id]);
    $newCategory = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);
    $budget = Budget::factory()->forCategories($oldCategory)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", [
            'category_ids' => [$newCategory->id],
            'label_ids' => [$label->id],
        ])
        ->assertRedirect();

    expect($budget->fresh()->categories->modelKeys())->toBe([$newCategory->id])
        ->and($budget->fresh()->labels->modelKeys())->toBe([$label->id]);
});

test('user can clear one tracking dimension while preserving the omitted dimension', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $space = $user->personalSpace;
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $budget = Budget::factory()->forCategories($category)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);
    $budget->labels()->sync([$label->id]);

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['category_ids' => []])
        ->assertRedirect();

    expect($budget->fresh()->categories)->toBeEmpty()
        ->and($budget->fresh()->labels->modelKeys())->toBe([$label->id]);

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", ['label_ids' => []])
        ->assertSessionHasErrors('selection');

    expect($budget->fresh()->labels->modelKeys())->toBe([$label->id]);
});

test('user cannot update budget tracking with references from another space', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $space = $user->personalSpace;
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $oldCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);
    $foreignCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $otherSpace->id,
    ]);
    $budget = Budget::factory()->forCategories($oldCategory)->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
    ]);

    $this->actingAs($user)
        ->patch("/budgets/{$budget->id}", [
            'category_ids' => [$foreignCategory->id],
        ])
        ->assertSessionHasErrors('category_ids');

    expect($budget->fresh()->categories->modelKeys())->toBe([$oldCategory->id]);
});

test('user can delete their budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->delete("/budgets/{$budget->id}");

    $response->assertRedirect();

    $this->assertSoftDeleted('budgets', [
        'id' => $budget->id,
    ]);
});

test('budget show returns previous period when it exists', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period (last month)
    $budget->periods()->create([
        'start_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'end_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->has('previousPeriod')
        ->where('previousPeriod.start_date', now()->subMonthNoOverflow()->startOfMonth()->toJSON())
    );
});

test('budget show returns null previous period when it is the first period', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create only the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->where('previousPeriod', null)
    );
});

test('budget show returns next period only when it starts on or before today', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period (two months ago)
    $budget->periods()->create([
        'start_date' => now()->subMonths(2)->startOfMonth(),
        'end_date' => now()->subMonths(2)->endOfMonth(),
        'allocated_amount' => 20000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Create a future period (should be excluded from nextPeriod)
    $budget->periods()->create([
        'start_date' => now()->addMonth()->startOfMonth(),
        'end_date' => now()->addMonth()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Navigate to the previous period — next should be the current (today), not the future one
    $previousPeriod = $budget->periods()
        ->where('start_date', now()->subMonths(2)->startOfMonth()->toDateString())
        ->first();

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}?period={$previousPeriod->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->has('nextPeriod')
        ->where('nextPeriod.start_date', now()->startOfMonth()->toJSON())
    );
});

test('budget show returns null next period when on the latest period', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create only the current period (no future period)
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->has('currentPeriod')
        ->where('nextPeriod', null)
    );
});

test('budget show can navigate to a specific period via query param', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'period_start_day' => 1,
    ]);

    // Create a previous period
    $previousPeriod = $budget->periods()->create([
        'start_date' => now()->subMonthNoOverflow()->startOfMonth(),
        'end_date' => now()->subMonthNoOverflow()->endOfMonth(),
        'allocated_amount' => 20000,
        'carried_over_amount' => 0,
    ]);

    // Create the current period
    $budget->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    $response = $this->actingAs($user)->get("/budgets/{$budget->id}?period={$previousPeriod->id}");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('budgets/show')
        ->where('currentPeriod.id', $previousPeriod->id)
        ->where('previousPeriod', null)
        ->has('nextPeriod')
    );
});

test('budget show returns 404 when period does not belong to budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $budget1 = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    $budget2 = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);

    // Create a period for budget2
    $otherPeriod = $budget2->periods()->create([
        'start_date' => now()->startOfMonth(),
        'end_date' => now()->endOfMonth(),
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);

    // Try to access budget1 with budget2's period ID
    $response = $this->actingAs($user)->get("/budgets/{$budget1->id}?period={$otherPeriod->id}");

    $response->assertNotFound();
});

test('budget period is automatically generated', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $category = Category::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)->post('/budgets', [
        'name' => 'Test Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$category->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $budget = Budget::where('user_id', $user->id)->first();
    $this->assertNotNull($budget);
    $this->assertCount(2, $budget->periods);

    $period = $budget->getCurrentPeriod();
    $this->assertNotNull($period->start_date);
    $this->assertNotNull($period->end_date);
});

test('user can create a budget tracking multiple categories and labels', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $food = Category::factory()->create(['user_id' => $user->id]);
    $restaurants = Category::factory()->create(['user_id' => $user->id]);
    $trip = Label::factory()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Food Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$food->id, $restaurants->id],
        'label_ids' => [$trip->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 80000,
    ]);

    $response->assertRedirect();

    $budget = Budget::where('user_id', $user->id)->first();

    expect($budget->categories->pluck('id')->all())
        ->toEqualCanonicalizing([$food->id, $restaurants->id])
        ->and($budget->labels->pluck('id')->all())->toEqualCanonicalizing([$trip->id]);
});

test('creating a budget requires at least one category or label', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Empty Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $response->assertSessionHasErrors('selection');
    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

test('user can create a catch-all budget without categories or labels', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Everything Else',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
        'is_catch_all' => true,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $budget = Budget::where('user_id', $user->id)->first();
    expect($budget)->not->toBeNull()
        ->and($budget->is_catch_all)->toBeTrue()
        ->and($budget->categories)->toBeEmpty()
        ->and($budget->labels)->toBeEmpty();
});

test('user cannot create a second catch-all budget', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Budget::factory()->catchAll()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Another Catch-all',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [],
        'label_ids' => [],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
        'is_catch_all' => true,
    ]);

    $response->assertSessionHasErrors('is_catch_all');
    expect(Budget::where('user_id', $user->id)->count())->toBe(1);
});

test('creating a budget rejects categories owned by another user', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $otherUser = User::factory()->create();
    $foreignCategory = Category::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($user)->post('/budgets', [
        'name' => 'Sneaky Budget',
        'period_type' => 'monthly',
        'period_start_day' => 1,
        'category_ids' => [$foreignCategory->id],
        'rollover_type' => 'reset',
        'allocated_amount' => 50000,
    ]);

    $response->assertSessionHasErrors('category_ids.0');
    expect(Budget::where('user_id', $user->id)->count())->toBe(0);
});

test('budget index hides period_duration and category pivot', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $budget->categories()->attach($category->id);

    $response = $this->actingAs($user)->get(route('budgets.index'));

    $budgetData = $response->viewData('page')['props']['budgets'][0];

    expect($budgetData)->not->toHaveKey('period_duration');
    expect($budgetData['categories'][0])->not->toHaveKey('pivot');
});

test('budget index exposes only the direct next planning period', function () {
    Carbon::setTestNow('2026-07-30');
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    $active = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 40000,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 40000,
    ]);

    $this->actingAs($user)->get(route('budgets.index'))
        ->assertInertia(fn ($page) => $page
            ->has('budgets.0.periods', 1)
            ->where('budgets.0.periods.0.id', $active->id)
            ->where('budgets.0.next_planning_period.id', $august->id)
            ->where('budgets.0.next_planning_period.allocated_amount', 40000)
            ->missing('budgets.0.next_planning_period.carried_over_amount')
        );
});

test('budget planning is unavailable outside the active space', function () {
    Carbon::setTestNow('2026-07-30');
    $user = User::factory()->create(['onboarded_at' => now()]);
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $otherSpace->id,
        'period_start_day' => 1,
    ]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 40000,
    ]);

    $this->actingAs($user)->get(route('budgets.index'))
        ->assertInertia(fn ($page) => $page
            ->where('budgets.0.next_planning_period', null)
        );
    $this->actingAs($user)->get("/budgets/{$budget->id}?period={$august->id}")->assertNotFound();
});

test('budget show permits only the direct next period as planning', function () {
    Carbon::setTestNow('2026-07-30');
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 40000,
    ]);
    $september = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 40000,
    ]);

    $this->actingAs($user)->get("/budgets/{$budget->id}?period={$august->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('is_planning_period', true)
            ->where('currentPeriod.id', $august->id)
        );
    $this->actingAs($user)->get("/budgets/{$budget->id}?period={$september->id}")->assertNotFound();
});

test('budget period planning patch updates only the direct successor', function () {
    Carbon::setTestNow('2026-07-30');
    $user = User::factory()->create(['onboarded_at' => now()]);
    $budget = Budget::factory()->monthly()->create(['user_id' => $user->id, 'period_start_day' => 1]);
    BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-07-01',
        'end_date' => '2026-07-31',
        'allocated_amount' => 40000,
    ]);
    $august = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
        'allocated_amount' => 40000,
    ]);
    $september = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 40000,
    ]);

    $this->actingAs($user)->patch("/budgets/{$budget->id}/periods/{$august->id}", ['allocated_amount' => 45000])
        ->assertRedirect("/budgets/{$budget->id}?period={$august->id}");
    expect($august->fresh()->allocated_amount)->toBe(45000)
        ->and($september->fresh()->allocated_amount)->toBe(40000);
    $this->actingAs($user)->patch("/budgets/{$budget->id}/periods/{$august->id}", ['allocated_amount' => -1])
        ->assertSessionHasErrors('allocated_amount');
});
