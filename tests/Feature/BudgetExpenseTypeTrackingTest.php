<?php

use App\Enums\BudgetExpenseType;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\User;
use App\Services\BudgetManagementService;
use App\Services\BudgetPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * A fixed budget made the way the app makes one, with its current and
 * previous periods.
 */
function fixedBudgetThroughTheApp(User $user): Budget
{
    test()->actingAs($user)
        ->post('/budgets', [
            'name' => 'Rent',
            'period_type' => 'monthly',
            'period_start_day' => 1,
            'category_ids' => [Category::factory()->create(['user_id' => $user->id])->id],
            'rollover_type' => 'reset',
            'expense_type' => 'fixed',
            'allocated_amount' => 100000,
        ])
        ->assertSessionHasNoErrors();

    return Budget::query()->whereBelongsTo($user)->sole();
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create(['onboarded_at' => now()]);
    $this->budget = fixedBudgetThroughTheApp($this->user);
});

test('changing what a budget tracks keeps its expense type', function (string $field) {
    $tracked = $field === 'label_ids'
        ? Label::factory()->create(['user_id' => $this->user->id])
        : Category::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->patch("/budgets/{$this->budget->id}", [$field => [$tracked->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed)
        ->and($this->budget->fresh()->{$field === 'label_ids' ? 'labels' : 'categories'}->modelKeys())->toContain($tracked->id);
})->with(['category_ids', 'label_ids']);

test('changing the regular allocation keeps the expense type', function () {
    $this->actingAs($this->user)
        ->patch("/budgets/{$this->budget->id}", ['allocated_amount' => 120000])
        ->assertSessionHasNoErrors();

    expect($this->budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed);
});

test('planning the next period keeps the expense type', function () {
    $current = $this->budget->getCurrentPeriod(CarbonImmutable::today());
    $next = app(BudgetPeriodService::class)->ensureSuccessor($this->budget, $current, $current->regularAllocatedAmount());

    $this->actingAs($this->user)
        ->patch("/budgets/{$this->budget->id}/periods/{$next->id}", ['allocated_amount' => 130000])
        ->assertSessionHasNoErrors();

    expect($next->fresh()->allocated_amount)->toBe(130000)
        ->and($this->budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed);
});

test('a one-off allocation keeps the expense type', function () {
    app(BudgetManagementService::class)->updatePeriodAllocation(
        $this->user,
        $this->user->activeSpace(),
        $this->budget->id,
        CarbonImmutable::today(),
        150000,
        CarbonImmutable::today(),
    );

    expect($this->budget->fresh()->expense_type)->toBe(BudgetExpenseType::Fixed)
        ->and($this->budget->getCurrentPeriod(CarbonImmutable::today())->hasOneOffAllocation())->toBeTrue();
});

test('the budget page sends the expense type the edit dialog starts from', function () {
    $this->actingAs($this->user)
        ->get("/budgets/{$this->budget->id}")
        ->assertInertia(fn ($page) => $page->where('budget.expense_type', 'fixed'));
});

test('editing a catch-all budget is accepted and leaves its expense type variable', function () {
    $catchAll = Budget::factory()->for($this->user)->catchAll()->create();

    $this->actingAs($this->user)
        ->patch("/budgets/{$catchAll->id}", ['name' => 'Everything else', 'expense_type' => 'fixed'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($catchAll->fresh())
        ->name->toBe('Everything else')
        ->expense_type->toBe(BudgetExpenseType::Variable);
});
