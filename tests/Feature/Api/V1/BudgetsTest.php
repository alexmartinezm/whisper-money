<?php

use App\Enums\BudgetExpenseType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Jobs\RebuildCatchAllBudgetPeriods;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBudget;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function budgetsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function budgetUser(): User
{
    return User::factory()->create(['currency_code' => 'EUR']);
}

function sharedBudgetSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * A monthly budget over October 2026 with 320.50 spent of its 500.00.
 */
function groceriesBudget(User $user, array $attributes = []): Budget
{
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => $attributes['name'] ?? 'Groceries']);
    $budget = Budget::factory()->monthly()->forCategories($category)->create([
        'user_id' => $user->id,
        'name' => 'Groceries',
        'period_start_day' => 1,
        'rollover_type' => 'reset',
        ...$attributes,
    ]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-10-01',
        'end_date' => '2026-10-31',
        'allocated_amount' => 50_000,
        'processing_historical' => false,
    ]);
    BudgetTransaction::factory()->create(['budget_period_id' => $period->id, 'amount' => 32_050]);

    return $budget;
}

/**
 * @return array<string, mixed>
 */
function newBudget(User $user, array $overrides = []): array
{
    return [
        'name' => 'Groceries',
        'allocated_amount' => 50_000,
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
        'period_start_day' => 1,
        'category_ids' => [Category::factory()->create(['user_id' => $user->id])->id],
        ...$overrides,
    ];
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

describe('list', function () {
    it('lists the user\'s running budgets, newest first, with the period in progress', function () {
        $user = budgetUser();
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Essentials']);
        $groceries = groceriesBudget($user, ['created_at' => '2026-01-15 09:30:00', 'updated_at' => '2026-01-20 18:05:00']);
        $groceries->labels()->attach($label);
        $newer = Budget::factory()->fixed()->create(['user_id' => $user->id, 'created_at' => '2026-02-01 10:00:00']);
        Budget::factory()->archived()->create(['user_id' => $user->id]);
        Budget::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/budgets')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$newer->id, $groceries->id])
            ->assertJsonPath('data.0.expense_type', 'fixed')
            ->assertJsonPath('data.0.current_period', null)
            ->assertJsonPath('data.1', [
                'id' => $groceries->id,
                'space_id' => $user->personalSpace->id,
                'name' => 'Groceries',
                'period_type' => 'monthly',
                'period_start_day' => 1,
                'rollover_type' => 'reset',
                'expense_type' => 'variable',
                'is_catch_all' => false,
                'categories' => [['id' => $groceries->categories->sole()->id, 'name' => 'Groceries']],
                'labels' => [['id' => $label->id, 'name' => 'Essentials']],
                'currency' => 'EUR',
                'current_period' => [
                    'id' => $groceries->periods()->sole()->id,
                    'start_date' => '2026-10-01',
                    'end_date' => '2026-10-31',
                    'allocated_amount' => 50_000,
                    'is_one_off' => false,
                    'regular_allocated_amount' => 50_000,
                    'carried_over_amount' => 0,
                    'available_amount' => 50_000,
                    'spent_amount' => 32_050,
                    'remaining_amount' => 17_950,
                    'status' => 'on_track',
                    'processing_historical' => false,
                ],
                'archived_at' => null,
                'created_at' => '2026-01-15T09:30:00+00:00',
                'updated_at' => '2026-01-20T18:05:00+00:00',
            ]);
    });

    it('reads a page of budgets in the same number of queries however many it holds', function () {
        $user = budgetUser();
        $headers = apiV1Bearer($user);
        $queriesFor = function () use ($headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            withHeaders($headers)->getJson('/api/v1/budgets')->assertOk();

            return count(DB::getQueryLog());
        };

        groceriesBudget($user);
        // The first request also resolves the token.
        $queriesFor();
        $one = $queriesFor();
        groceriesBudget($user, ['name' => 'Rent']);
        groceriesBudget($user, ['name' => 'Leisure']);

        expect($queriesFor())->toBe($one);
    });

    it('pages through the budgets with a cursor', function () {
        $user = budgetUser();
        $budgets = collect(range(1, 3))->map(fn (int $day) => Budget::factory()->create(['user_id' => $user->id, 'created_at' => "2026-03-0{$day} 10:00:00"]))->reverse()->values();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/budgets?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$budgets[0]->id, $budgets[1]->id]);

        withHeaders($headers)->getJson('/api/v1/budgets?limit=2&starting_after='.$budgets[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$budgets[2]->id]);

        withHeaders($headers)->getJson('/api/v1/budgets?starting_after='.Budget::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });
});

describe('show', function () {
    it('retrieves a budget, archived ones included', function () {
        $user = budgetUser();
        $archived = Budget::factory()->archived()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson("/api/v1/budgets/{$archived->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $archived->id)
            ->assertJsonPath('data.archived_at', '2026-10-15T12:00:00+00:00');
    });

    it('404s on someone else\'s budget and on an id that is not one', function () {
        $user = budgetUser();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/budgets/'.Budget::factory()->create()->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        withHeaders($headers)->getJson('/api/v1/budgets/not-an-id')->assertNotFound();
    });

    it('reads a budget of another of your spaces only when asked for that space', function () {
        $user = budgetUser();
        $space = sharedBudgetSpace($user, budgetUser());
        $budget = Budget::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/budgets/{$budget->id}")->assertNotFound();
        withHeaders($headers)->getJson("/api/v1/budgets/{$budget->id}?space={$space->id}")
            ->assertOk()
            ->assertJsonPath('data.space_id', $space->id);
    });
});

describe('shared spaces', function () {
    it('lists, reads and changes only your own budgets of a shared space', function () {
        Queue::fake();
        $user = budgetUser();
        $owner = budgetUser();
        $space = sharedBudgetSpace($user, $owner);
        $theirs = Budget::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);
        $mine = Budget::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'name' => 'Mine']);
        Budget::factory()->create(['user_id' => $user->id]);
        $headers = budgetsWriter($user);

        withHeaders($headers)->getJson("/api/v1/budgets?space={$space->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);

        withHeaders($headers)->getJson("/api/v1/budgets/{$theirs->id}?space={$space->id}")->assertNotFound();
        withHeaders($headers)->patchJson("/api/v1/budgets/{$theirs->id}?space={$space->id}", ['name' => 'Taken'])->assertNotFound();
        withHeaders($headers)->patchJson("/api/v1/budgets/{$theirs->id}/period?space={$space->id}", ['date' => '2026-10-15', 'allocated_amount' => 1])->assertNotFound();
        withHeaders($headers)->deleteJson("/api/v1/budgets/{$theirs->id}?space={$space->id}")->assertNotFound();

        expect($theirs->fresh()->name)->toBe('Theirs');
    });

    it('creates the budget in a shared space, tracking only your own categories there', function () {
        Queue::fake();
        $user = budgetUser();
        $owner = budgetUser();
        $space = sharedBudgetSpace($user, $owner);
        $mine = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
        $theirs = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $personal = Category::factory()->create(['user_id' => $user->id]);
        $headers = budgetsWriter($user);

        withHeaders($headers)
            ->postJson('/api/v1/budgets', newBudget($user, ['space' => $space->id, 'category_ids' => [$mine->id]]))
            ->assertCreated()
            ->assertJsonPath('data.space_id', $space->id);

        foreach ([$theirs, $personal] as $unreachable) {
            withHeaders($headers)
                ->postJson('/api/v1/budgets', newBudget($user, ['space' => $space->id, 'name' => 'Other', 'category_ids' => [$unreachable->id]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('category_ids.0');
        }

        expect($user->budgets()->count())->toBe(1);
    });
});

describe('create', function () {
    it('creates a budget with its periods and backfills them in the background', function () {
        Queue::fake();
        $user = budgetUser();
        $label = Label::factory()->create(['user_id' => $user->id]);
        $body = newBudget($user, ['label_ids' => [$label->id], 'expense_type' => 'fixed']);

        $response = withHeaders(budgetsWriter($user))
            ->postJson('/api/v1/budgets', $body)
            ->assertCreated()
            ->assertJsonPath('data.name', 'Groceries')
            ->assertJsonPath('data.expense_type', 'fixed')
            ->assertJsonPath('data.categories.0.id', $body['category_ids'][0])
            ->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('data.current_period.start_date', '2026-10-01')
            ->assertJsonPath('data.current_period.allocated_amount', 50_000)
            ->assertJsonPath('data.current_period.processing_historical', true);

        $budget = $user->budgets()->sole();
        expect($response->json('data.id'))->toBe($budget->id)
            ->and($budget->periods()->count())->toBe(2);
        Queue::assertPushed(AssignHistoricalTransactionsToBudget::class, 2);
    });

    it('creates the catch-all budget without categories, and refuses a second one', function () {
        Queue::fake();
        $user = budgetUser();
        $body = newBudget($user, ['category_ids' => [], 'is_catch_all' => true, 'expense_type' => 'fixed']);
        $headers = budgetsWriter($user);

        withHeaders($headers)->postJson('/api/v1/budgets', $body)
            ->assertCreated()
            ->assertJsonPath('data.is_catch_all', true)
            ->assertJsonPath('data.expense_type', 'variable');

        withHeaders($headers)->postJson('/api/v1/budgets', $body)
            ->assertUnprocessable()
            ->assertJsonPath('errors.is_catch_all.0', 'You already have a catch-all budget.');
    });

    it('refuses invalid input', function (Closure $body, string $field, ?string $message = null) {
        $user = budgetUser();

        $response = withHeaders(budgetsWriter($user))
            ->postJson('/api/v1/budgets', $body($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        if ($message !== null) {
            expect($response->json('errors')[$field][0])->toBe($message);
        }

        expect($user->budgets()->count())->toBe(0);
    })->with([
        'nothing to track' => [fn (User $user) => newBudget($user, ['category_ids' => []]), 'selection', 'You must select at least one category or label.'],
        'missing limit' => [fn (User $user) => newBudget($user, ['allocated_amount' => null]), 'allocated_amount'],
        'negative limit' => [fn (User $user) => newBudget($user, ['allocated_amount' => -1]), 'allocated_amount'],
        'limit over the maximum' => [fn (User $user) => newBudget($user, ['allocated_amount' => Budget::MAX_ALLOCATED_AMOUNT + 1]), 'allocated_amount'],
        'name too long' => [fn (User $user) => newBudget($user, ['name' => str_repeat('a', 256)]), 'name'],
        'too many categories' => [fn (User $user) => newBudget($user, ['category_ids' => array_map(fn (int $i): string => "id-{$i}", range(1, 101))]), 'category_ids'],
        'too many labels' => [fn (User $user) => newBudget($user, ['label_ids' => array_map(fn (int $i): string => "id-{$i}", range(1, 101))]), 'label_ids'],
        'repeated category' => [fn (User $user) => newBudget($user, ['category_ids' => array_fill(0, 2, Category::factory()->create(['user_id' => $user->id])->id)]), 'category_ids.0'],
        'repeated label' => [fn (User $user) => newBudget($user, ['label_ids' => array_fill(0, 2, Label::factory()->create(['user_id' => $user->id])->id)]), 'label_ids.0'],
        'unknown period' => [fn (User $user) => newBudget($user, ['period_type' => 'daily']), 'period_type'],
        'weekday out of range' => [fn (User $user) => newBudget($user, ['period_type' => 'weekly', 'period_start_day' => 7]), 'period_start_day', 'The start day is invalid for the selected cadence.'],
        'day of month zero' => [fn (User $user) => newBudget($user, ['period_start_day' => 0]), 'period_start_day'],
        'day of month 32' => [fn (User $user) => newBudget($user, ['period_start_day' => 32]), 'period_start_day'],
        'a quarter starting mid-month' => [fn (User $user) => newBudget($user, ['period_type' => 'quarterly', 'period_start_day' => 15]), 'period_start_day'],
        'a start day that is no number' => [fn (User $user) => newBudget($user, ['period_start_day' => 'first']), 'period_start_day'],
        'unknown expense type' => [fn (User $user) => newBudget($user, ['expense_type' => 'sometimes']), 'expense_type'],
        'someone else\'s category' => [fn (User $user) => newBudget($user, ['category_ids' => [Category::factory()->create()->id]]), 'category_ids.0'],
        'someone else\'s label' => [fn (User $user) => newBudget($user, ['label_ids' => [Label::factory()->create()->id]]), 'label_ids.0'],
        'unknown field' => [fn (User $user) => newBudget($user, ['limit' => 100]), 'limit', 'This endpoint does not take a limit field.'],
        'a list for the period type' => [fn (User $user) => newBudget($user, ['period_type' => ['monthly']]), 'period_type'],
        'a list for the name' => [fn (User $user) => newBudget($user, ['name' => ['Groceries']]), 'name'],
        'a list for the start day' => [fn (User $user) => newBudget($user, ['period_start_day' => [1]]), 'period_start_day'],
        'catch-all with categories' => [fn (User $user) => newBudget($user, ['is_catch_all' => true]), 'tracking', 'A catch-all budget cannot have categories or labels.'],
    ]);

    it('takes the start day the way the MCP tools do', function (array $fields, ?int $stored) {
        Queue::fake();
        $user = budgetUser();

        withHeaders(budgetsWriter($user))
            ->postJson('/api/v1/budgets', newBudget($user, $fields))
            ->assertCreated()
            ->assertJsonPath('data.period_start_day', $stored);
    })->with([
        'a weekly budget with no day' => [['period_type' => 'weekly', 'period_start_day' => null], null],
        'a weekly budget on Sunday' => [['period_type' => 'weekly', 'period_start_day' => 0], 0],
        'a quarterly budget with no day' => [['period_type' => 'quarterly', 'period_start_day' => null], 1],
        'a yearly budget left out' => [['period_type' => 'yearly', 'period_start_day' => null], 1],
        'a monthly budget on the 25th' => [['period_start_day' => 25], 25],
    ]);

    it('makes the existing catch-all give up what a new budget tracks', function () {
        Queue::fake();
        $user = budgetUser();
        $catchAll = Budget::factory()->monthly()->create(['user_id' => $user->id, 'is_catch_all' => true]);
        BudgetPeriod::factory()->create([
            'budget_id' => $catchAll->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
            'processing_historical' => false,
        ]);

        withHeaders(budgetsWriter($user))->postJson('/api/v1/budgets', newBudget($user))->assertCreated();

        Queue::assertPushed(RebuildCatchAllBudgetPeriods::class);
    });

    it('refuses a read-only token', function () {
        $user = budgetUser();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/budgets', newBudget($user))
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->budgets()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second budget', function () {
        Queue::fake();
        $user = budgetUser();
        $headers = [...budgetsWriter($user), 'Idempotency-Key' => 'create-groceries'];
        $body = newBudget($user);

        $first = withHeaders($headers)->postJson('/api/v1/budgets', $body)->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/budgets', $body)->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->budgets()->count())->toBe(1);
    });
});

describe('update', function () {
    it('renames the budget and moves the limit of the periods from today on, not the one under way', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $past = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'allocated_amount' => 50_000]);
        $next = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'allocated_amount' => 50_000]);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", ['name' => 'Food', 'allocated_amount' => 60_000, 'expense_type' => 'fixed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Food')
            ->assertJsonPath('data.expense_type', 'fixed')
            ->assertJsonPath('data.current_period.allocated_amount', 50_000)
            ->assertJsonPath('data.current_period.remaining_amount', 17_950);

        expect($past->fresh()->allocated_amount)->toBe(50_000)
            ->and($next->fresh()->allocated_amount)->toBe(60_000);
    });

    it('moves the period in progress too on the day it starts', function () {
        $this->travelTo(Carbon::parse('2026-10-01 09:00:00'));
        $user = budgetUser();
        $budget = groceriesBudget($user);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", ['allocated_amount' => 60_000])
            ->assertOk()
            ->assertJsonPath('data.current_period.allocated_amount', 60_000)
            ->assertJsonPath('data.current_period.remaining_amount', 27_950);
    });

    it('re-points the budget, keeping closed periods and recalculating the active one', function () {
        Queue::fake();
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $past = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'processing_historical' => false]);
        $dining = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", ['category_ids' => [$dining->id]])
            ->assertOk()
            ->assertJsonPath('data.categories', [['id' => $dining->id, 'name' => 'Dining']])
            ->assertJsonPath('data.current_period.processing_historical', true);

        expect($past->fresh()->processing_historical)->toBeFalse();
        Queue::assertPushed(AssignHistoricalTransactionsToBudget::class, fn (AssignHistoricalTransactionsToBudget $job): bool => true);
    });

    it('rebuilds the catch-all when a budget\'s tracking moves', function () {
        Queue::fake();
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $catchAll = Budget::factory()->monthly()->create(['user_id' => $user->id, 'is_catch_all' => true]);
        $period = BudgetPeriod::factory()->create(['budget_id' => $catchAll->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", ['label_ids' => [Label::factory()->create(['user_id' => $user->id])->id]])
            ->assertOk();

        expect($period->fresh()->processing_historical)->toBeTrue();
        Queue::assertPushed(RebuildCatchAllBudgetPeriods::class);
    });

    it('keeps a category when only labels are sent, and refuses leaving nothing to track', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $category = $budget->categories->sole();
        $label = Label::factory()->create(['user_id' => $user->id]);
        $headers = budgetsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}", ['label_ids' => [$label->id]])
            ->assertOk()
            ->assertJsonPath('data.categories.0.id', $category->id);

        withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}", ['category_ids' => [], 'label_ids' => []])
            ->assertUnprocessable()
            ->assertJsonPath('errors.selection.0', 'You must select at least one category or label.');

        expect($budget->fresh()->categories)->toHaveCount(1);
    });

    it('refuses tracking on a catch-all and keeps it variable whatever is sent', function () {
        $user = budgetUser();
        $catchAll = Budget::factory()->monthly()->create(['user_id' => $user->id, 'is_catch_all' => true]);
        $headers = budgetsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/budgets/{$catchAll->id}", ['category_ids' => [Category::factory()->create(['user_id' => $user->id])->id]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking.0', 'A catch-all budget cannot have categories or labels.');

        withHeaders($headers)->patchJson("/api/v1/budgets/{$catchAll->id}", ['expense_type' => 'fixed'])
            ->assertOk()
            ->assertJsonPath('data.expense_type', 'variable');

        expect($catchAll->fresh()->expense_type)->toBe(BudgetExpenseType::Variable);
    });

    it('refuses an update that changes nothing', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        withHeaders(budgetsWriter($user))->patchJson("/api/v1/budgets/{$budget->id}", [])
            ->assertUnprocessable()
            ->assertJsonPath('errors.budget.0', 'Provide a mutable budget field.');
    });

    it('keeps a one-off amount when the limit moves, and only moves what it goes back to', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $headers = budgetsWriter($user);

        $oneOff = withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}/period", ['date' => '2026-11-15', 'allocated_amount' => 90_000])
            ->assertOk()
            ->json('data.period.id');

        withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}", ['allocated_amount' => 60_000])->assertOk();

        $period = BudgetPeriod::query()->findOrFail($oneOff);
        expect($period->allocated_amount)->toBe(90_000)
            ->and($period->hasOneOffAllocation())->toBeTrue()
            ->and($period->regularAllocatedAmount())->toBe(60_000);
    });

    it('refuses the fixed fields, explaining how to change them', function (string $field, mixed $value) {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", ['name' => 'Food', $field => $value])
            ->assertUnprocessable()
            ->assertJsonPath("errors.{$field}.0", "A budget's {$field} is fixed once it is created, because its periods are calculated over its history. To change it, delete the budget and create it again.");

        expect($budget->fresh()->name)->toBe('Groceries');
    })->with([
        ['period_type', 'weekly'],
        ['period_start_day', 15],
        ['rollover_type', 'carry_over'],
        ['is_catch_all', true],
    ]);

    it('refuses an invalid name or limit', function (array $body, string $field) {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect($budget->fresh()->name)->toBe('Groceries');
    })->with([
        'limit over the maximum' => [['allocated_amount' => Budget::MAX_ALLOCATED_AMOUNT + 1], 'allocated_amount'],
        'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    ]);

    it('refuses an archived budget, someone else\'s and a read-only token', function () {
        $user = budgetUser();
        $archived = Budget::factory()->archived()->create(['user_id' => $user->id]);

        withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$archived->id}", ['name' => 'Back'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.budget.0', "Budget {$archived->id} is archived and can no longer be changed. Archiving cannot be undone, but you can still delete it.");

        withHeaders(budgetsWriter($user))
            ->patchJson('/api/v1/budgets/'.Budget::factory()->create()->id, ['name' => 'Mine'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/budgets/'.groceriesBudget($user)->id, ['name' => 'Food'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
    });
});

describe('a single period', function () {
    it('gives one period its own amount and leaves the next on the regular one', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        $response = withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}/period", ['date' => '2026-10-20', 'allocated_amount' => 90_000])
            ->assertOk()
            ->assertJsonPath('data.budget.id', $budget->id)
            ->assertJsonPath('data.period.start_date', '2026-10-01')
            ->assertJsonPath('data.period.allocated_amount', 90_000)
            ->assertJsonPath('data.period.is_one_off', true)
            ->assertJsonPath('data.period.regular_allocated_amount', 50_000)
            ->assertJsonPath('data.period.remaining_amount', 57_950)
            ->assertJsonPath('data.following_period.start_date', '2026-11-01')
            ->assertJsonPath('data.following_period.allocated_amount', 50_000)
            ->assertJsonPath('data.following_period.is_one_off', false);

        expect($response->json('data.budget.current_period.allocated_amount'))->toBe(90_000);
    });

    it('sets a future period, then puts it back with reset', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $headers = budgetsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}/period", ['date' => '2026-12-01', 'allocated_amount' => 80_000])
            ->assertOk()
            ->assertJsonPath('data.period.start_date', '2026-12-01')
            ->assertJsonPath('data.period.is_one_off', true);

        withHeaders($headers)->patchJson("/api/v1/budgets/{$budget->id}/period", ['date' => '2026-12-01', 'reset' => true])
            ->assertOk()
            ->assertJsonPath('data.period.allocated_amount', 50_000)
            ->assertJsonPath('data.period.is_one_off', false);
    });

    it('refuses what update_budget_period refuses', function (array $body, string $field, ?string $message = null) {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        $response = withHeaders(budgetsWriter($user))
            ->patchJson("/api/v1/budgets/{$budget->id}/period", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        if ($message !== null) {
            expect($response->json('errors')[$field][0])->toBe($message);
        }

        expect($budget->periods()->sole()->allocated_amount)->toBe(50_000);
    })->with([
        'a closed period' => [['date' => '2026-09-15', 'allocated_amount' => 1], 'date', 'That period has already closed; only the period in progress and future ones can change.'],
        'too far ahead' => [['date' => '2027-11-01', 'allocated_amount' => 1], 'date', 'A one-off allocation can be set at most 12 months ahead.'],
        'neither' => [['date' => '2026-10-20'], 'allocated_amount', 'Pass either allocated_amount or reset: true.'],
        'both' => [['date' => '2026-10-20', 'allocated_amount' => 1, 'reset' => true], 'allocated_amount', 'Pass either allocated_amount or reset: true.'],
        'no date' => [['allocated_amount' => 1], 'date'],
        'not a date' => [['date' => '2026-02-31', 'allocated_amount' => 1], 'date'],
        'over the maximum' => [['date' => '2026-10-20', 'allocated_amount' => Budget::MAX_ALLOCATED_AMOUNT + 1], 'allocated_amount'],
        'negative' => [['date' => '2026-10-20', 'allocated_amount' => -1], 'allocated_amount'],
        'unknown field' => [['date' => '2026-10-20', 'allocated_amount' => 1, 'note' => 'x'], 'note'],
        'a list for the date' => [['date' => ['2026-10-20'], 'allocated_amount' => 1], 'date'],
    ]);

    it('refuses an archived budget, someone else\'s and a read-only token', function () {
        $user = budgetUser();
        $archived = Budget::factory()->archived()->create(['user_id' => $user->id]);
        $body = ['date' => '2026-10-20', 'allocated_amount' => 1];

        withHeaders(budgetsWriter($user))->patchJson("/api/v1/budgets/{$archived->id}/period", $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('budget');
        withHeaders(budgetsWriter($user))->patchJson('/api/v1/budgets/'.Budget::factory()->create()->id.'/period', $body)
            ->assertNotFound();
        withHeaders(apiV1Bearer($user))->patchJson('/api/v1/budgets/'.groceriesBudget($user)->id.'/period', $body)
            ->assertForbidden();
    });
});

describe('delete', function () {
    it('deletes a budget with its periods, archived ones too, and leaves the transactions alone', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $archived = Budget::factory()->archived()->create(['user_id' => $user->id]);
        $transaction = BudgetTransaction::query()->sole()->transaction;

        withHeaders(budgetsWriter($user))
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertOk()
            ->assertExactJson(['id' => $budget->id, 'deleted' => true]);
        withHeaders(budgetsWriter($user))
            ->deleteJson("/api/v1/budgets/{$archived->id}")
            ->assertOk();

        expect(Budget::query()->whereKey([$budget->id, $archived->id])->exists())->toBeFalse()
            ->and($transaction->fresh())->not->toBeNull();
    });

    it('hands what the deleted budget tracked back to the catch-all', function () {
        Queue::fake();
        $user = budgetUser();
        $budget = groceriesBudget($user);
        $catchAll = Budget::factory()->monthly()->create(['user_id' => $user->id, 'is_catch_all' => true]);
        BudgetPeriod::factory()->create(['budget_id' => $catchAll->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

        withHeaders(budgetsWriter($user))->deleteJson("/api/v1/budgets/{$budget->id}")->assertOk();

        Queue::assertPushed(RebuildCatchAllBudgetPeriods::class);
    });

    it('404s on someone else\'s budget and refuses a read-only token and unknown fields', function () {
        $user = budgetUser();
        $budget = groceriesBudget($user);

        withHeaders(budgetsWriter($user))
            ->deleteJson('/api/v1/budgets/'.Budget::factory()->create()->id)
            ->assertNotFound();
        withHeaders(apiV1Bearer($user))
            ->deleteJson("/api/v1/budgets/{$budget->id}")
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');
        withHeaders(budgetsWriter($user))
            ->deleteJson("/api/v1/budgets/{$budget->id}", ['force' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('force');

        expect($budget->fresh())->not->toBeNull();
    });
});

it('leaves the MCP tool taking any limit, as it always has', function () {
    Queue::fake();
    $user = budgetUser();
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($user)->tool(CreateBudget::class, [
        'name' => 'Huge',
        'period_type' => 'monthly',
        'rollover_type' => 'reset',
        'allocated_amount' => Budget::MAX_ALLOCATED_AMOUNT + 1,
        'category_ids' => [Category::factory()->create(['user_id' => $user->id])->id],
    ])->assertOk();
});
