<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\LabelSource;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateSavingsGoal;
use App\Mcp\Tools\ListSavingsGoals;
use App\Mcp\Tools\UpdateSavingsGoal;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SavingsGoals\SavingsGoalPeriodService;
use App\Services\SavingsGoals\SavingsGoalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function goalsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

function goalUser(): User
{
    return User::factory()->create(['currency_code' => 'EUR']);
}

function apiMonthlyGoal(User $user, array $attributes = []): SavingsGoal
{
    $goal = SavingsGoal::factory()->monthly(30_000)->create(['user_id' => $user->id, ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

function savingsAccount(User $user, array $attributes = []): Account
{
    return Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'currency_code' => 'EUR', ...$attributes]);
}

function sharedGoalSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * @return array<string, mixed>
 */
function newMonthlyGoal(array $overrides = []): array
{
    return ['name' => 'Emergency fund', 'kind' => 'monthly', 'monthly_target_type' => 'amount', 'monthly_target_amount' => 30_000, ...$overrides];
}

/**
 * A 10% income-rate goal of $saver's, with a closed month and the one in
 * progress, in a space whose only income is $earner's: 4,321,700 in each month
 * before the goal's. The target that gives is 432,170 and September's shortfall
 * 419,825, so any of the three in a response is $earner's income.
 */
function goalOnTheIncomeOf(User $earner, User $saver, Space $space, array $attributes = []): SavingsGoal
{
    $account = Account::factory()->create(['user_id' => $earner->id, 'space_id' => $space->id, 'type' => AccountType::Checking, 'currency_code' => 'EUR']);
    $salary = Category::factory()->create(['user_id' => $earner->id, 'space_id' => $space->id, 'type' => CategoryType::Income]);

    foreach (['2026-06-28', '2026-07-28', '2026-08-28', '2026-09-28'] as $date) {
        Transaction::factory()->create([
            'user_id' => $earner->id,
            'account_id' => $account->id,
            'category_id' => $salary->id,
            'currency_code' => 'EUR',
            'amount' => 4_321_700,
            'transaction_date' => $date,
        ]);
    }

    test()->travelTo(Carbon::parse('2026-09-02 12:00:00'));
    $goal = SavingsGoal::factory()->incomeRate(10)->create(['user_id' => $saver->id, 'space_id' => $space->id, 'created_at' => now(), ...$attributes]);
    app(SavingsGoalPeriodService::class)->advance($goal);
    Transaction::factory()->create([
        'user_id' => $saver->id,
        'account_id' => savingsAccount($saver, ['space_id' => $space->id])->id,
        'category_id' => Category::factory()->create(['user_id' => $saver->id, 'space_id' => $space->id, 'type' => CategoryType::Savings])->id,
        'currency_code' => 'EUR',
        'amount' => 12_345,
        'transaction_date' => '2026-09-15',
    ])->labels()->attach($goal->label_id);
    test()->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    app(SavingsGoalPeriodService::class)->advance($goal);

    return $goal;
}

/**
 * The goal's row hides everything worked out from the income it is measured
 * against, down to the raw body.
 */
function expectNoIncomeFigure(TestResponse $response, string $row = 'data'): void
{
    expect($response->getContent())->not->toContain('4321700')->not->toContain('432170')->not->toContain('419825');

    $month = fn (string $month, int $saved): array => ['month' => $month, 'target' => null, 'saved' => $saved, 'difference' => null, 'status' => null, 'income_base' => null];

    expect($response->json("{$row}.monthly.current"))->toBe([...$month('2026-10', 0), 'remaining' => null, 'days_left' => 29])
        ->and($response->json("{$row}.monthly.history"))->toBe([$month('2026-09', 12_345), $month('2026-10', 0)])
        ->and($response->json("{$row}.monthly"))->toMatchArray([
            'months_met' => null,
            'months_closed' => null,
            'cumulative_difference' => null,
            'streak' => null,
            'best_streak' => null,
        ]);
}

/**
 * The goal's row with its figures: the ones of goalOnTheIncomeOf().
 */
function expectIncomeFigures(TestResponse $response, string $row = 'data'): void
{
    expect($response->json("{$row}.monthly.current"))->toMatchArray(['target' => 432_170, 'income_base' => 4_321_700, 'remaining' => 432_170, 'status' => 'in_progress'])
        ->and($response->json("{$row}.monthly.history.0"))->toMatchArray(['target' => 432_170, 'income_base' => 4_321_700, 'difference' => -419_825, 'status' => 'missed'])
        ->and($response->json("{$row}.monthly"))->toMatchArray(['months_met' => 0, 'months_closed' => 1, 'cumulative_difference' => -419_825, 'streak' => 0, 'best_streak' => 0]);
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
});

describe('list', function () {
    it('lists the goals of the personal space, newest first, each kind with its own record', function () {
        $user = goalUser();
        $oneOff = SavingsGoal::factory()->create([
            'user_id' => $user->id,
            'name' => 'House',
            'target_amount' => 1_000_000,
            'initial_amount' => 50_000,
            'target_date' => null,
            'created_at' => '2026-09-01 09:30:00',
        ]);
        $this->travelTo(Carbon::parse('2026-09-10'));
        $monthly = apiMonthlyGoal($user, ['name' => 'Emergency fund']);
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => savingsAccount($user)->id,
            'currency_code' => 'EUR',
            'amount' => 32_000,
            'transaction_date' => '2026-09-15',
        ])->labels()->attach($monthly->label_id);
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
        SavingsGoal::factory()->create();

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/savings-goals')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$monthly->id, $oneOff->id])
            ->assertJsonPath('data.1.kind', 'one_off')
            ->assertJsonPath('data.1.currency', 'EUR')
            ->assertJsonPath('data.1.target_amount', 1_000_000)
            ->assertJsonPath('data.1.initial_amount', 50_000)
            ->assertJsonPath('data.1.progress.saved', 50_000)
            ->assertJsonPath('data.1.monthly', null)
            ->assertJsonPath('data.1.monthly_target_type', null)
            ->assertJsonPath('data.0.kind', 'monthly')
            ->assertJsonPath('data.0.label_id', $monthly->label_id)
            ->assertJsonPath('data.0.space_id', $user->personalSpace->id)
            ->assertJsonPath('data.0.target_amount', null)
            ->assertJsonPath('data.0.progress', null)
            ->assertJsonPath('data.0.monthly_target_type', 'amount')
            ->assertJsonPath('data.0.monthly_target_amount', 30_000)
            ->assertJsonPath('data.0.monthly.months_met', 1)
            ->assertJsonPath('data.0.monthly.current.month', '2026-10')
            ->assertJsonPath('data.0.monthly.current.days_left', 29);

        expect($response->json('data.0.monthly.history.0'))->toBe([
            'month' => '2026-09', 'target' => 30_000, 'saved' => 32_000, 'difference' => 2_000, 'status' => 'met', 'income_base' => null,
        ]);
    });

    it('reads a page of goals in the same number of database queries however many it holds', function () {
        $user = goalUser();
        $headers = apiV1Bearer($user);
        $queriesFor = function () use ($headers): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            withHeaders($headers)->getJson('/api/v1/savings-goals')->assertOk();

            // The cache table aside: a monthly goal reads its income target
            // from the cache.
            return collect(DB::getQueryLog())->reject(fn (array $query): bool => str_contains($query['query'], '`cache`'))->count();
        };

        apiMonthlyGoal($user);
        SavingsGoal::factory()->create(['user_id' => $user->id]);
        // The first request also resolves the token.
        $queriesFor();
        $two = $queriesFor();
        apiMonthlyGoal($user);
        apiMonthlyGoal($user);
        SavingsGoal::factory()->count(2)->create(['user_id' => $user->id]);

        expect($queriesFor())->toBe($two);
    });

    it('pages through the goals with a cursor', function () {
        $user = goalUser();
        $goals = collect(range(1, 3))->map(fn (int $day) => SavingsGoal::factory()->create(['user_id' => $user->id, 'created_at' => "2026-03-0{$day} 10:00:00"]))->reverse()->values();
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/savings-goals?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$goals[0]->id, $goals[1]->id]);

        withHeaders($headers)->getJson('/api/v1/savings-goals?limit=2&starting_after='.$goals[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$goals[2]->id]);

        withHeaders($headers)->getJson('/api/v1/savings-goals?starting_after='.SavingsGoal::factory()->create()->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('lists only your own goals of a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create(['currency_code' => 'USD']);
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        SavingsGoal::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $mine = SavingsGoal::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/savings-goals?space={$space->id}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id])
            ->assertJsonPath('data.0.currency', 'USD');

        withHeaders($headers)->getJson('/api/v1/savings-goals?space='.Space::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });
});

describe('show', function () {
    it('retrieves a goal of the space and 404s on one outside it', function () {
        $user = goalUser();
        $goal = apiMonthlyGoal($user);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson("/api/v1/savings-goals/{$goal->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $goal->id)
            ->assertJsonPath('data.monthly.current.month', '2026-10');

        withHeaders($headers)->getJson('/api/v1/savings-goals/'.SavingsGoal::factory()->create()->id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    });
});

describe('create', function () {
    it('creates a one-off goal with its label', function () {
        $user = goalUser();

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', ['name' => 'House', 'kind' => 'one_off', 'target_amount' => 1_000_000, 'initial_amount' => 50_000, 'target_date' => '2027-06-30'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'one_off')
            ->assertJsonPath('data.target_date', '2027-06-30')
            ->assertJsonPath('data.progress.saved', 50_000);

        $goal = $user->savingsGoals()->sole();
        expect($goal->target_amount)->toBe(1_000_000)
            ->and($goal->label->name)->toBe('House');
    });

    it('creates a monthly goal whose rule tags the transfers already in the savings account', function () {
        $user = goalUser();
        $savings = savingsAccount($user, ['name' => 'Rainy day']);
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'account_id' => $savings->id, 'currency_code' => 'EUR', 'amount' => 25_000, 'transaction_date' => '2026-10-01']);

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', newMonthlyGoal(['auto_tag_account_id' => $savings->id]))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'monthly')
            ->assertJsonPath('data.auto_tag_account_id', $savings->id)
            ->assertJsonPath('data.monthly.current.month', '2026-10')
            ->assertJsonPath('data.monthly.current.saved', 25_000);

        $goal = $user->savingsGoals()->sole();
        $rule = AutomationRule::query()->where('user_id', $user->id)->sole();
        expect($goal->periods()->count())->toBe(1)
            ->and($rule->labels()->pluck('labels.id')->all())->toBe([$goal->label_id]);
    });

    it('creates the goal in a shared space when asked', function () {
        $user = goalUser();
        $space = sharedGoalSpace($user, goalUser());

        withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', [...newMonthlyGoal(), 'space' => $space->id])
            ->assertCreated()
            ->assertJsonPath('data.space_id', $space->id);
    });

    it('refuses invalid input', function (Closure $body, string $field, ?string $message = null) {
        $user = goalUser();
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Taken']);

        $response = withHeaders(goalsWriter($user))
            ->postJson('/api/v1/savings-goals', $body($user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        if ($message !== null) {
            expect($response->json('errors')[$field][0])->toBe($message);
        }

        expect($user->savingsGoals()->count())->toBe(0);
    })->with([
        'no kind' => [fn () => newMonthlyGoal(['kind' => null]), 'kind'],
        'no monthly target' => [fn () => newMonthlyGoal(['monthly_target_type' => null]), 'monthly_target_type'],
        'rate over 100' => [fn () => newMonthlyGoal(['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 120]), 'monthly_target_rate'],
        'one-off without total' => [fn () => ['name' => 'Fund', 'kind' => 'one_off'], 'target_amount'],
        'one-off date in the past' => [fn () => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100, 'target_date' => '2026-01-01'], 'target_date'],
        'field of the other kind' => [fn () => newMonthlyGoal(['target_amount' => 100]), 'target_amount', 'target_amount only applies to one_off goals; this one is monthly.'],
        'auto-tag on a one-off goal' => [fn (User $user) => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100, 'auto_tag_account_id' => savingsAccount($user)->id], 'auto_tag_account_id', 'auto_tag_account_id only applies to monthly goals; this one is one_off.'],
        'name clash' => [fn () => newMonthlyGoal(['name' => 'Taken']), 'name', 'A label or goal named "Taken" already exists. Pick another name.'],
        'amount over the limit' => [fn () => newMonthlyGoal(['monthly_target_amount' => SavingsGoal::MAX_AMOUNT + 1]), 'monthly_target_amount', 'monthly_target_amount is in minor units of EUR: at most 100000000000, which is €1,000,000,000.00.'],
        'total over the limit' => [fn () => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => SavingsGoal::MAX_AMOUNT + 1], 'target_amount'],
        'saved amount over the limit' => [fn () => ['name' => 'Fund', 'kind' => 'one_off', 'target_amount' => 100, 'initial_amount' => SavingsGoal::MAX_AMOUNT + 1], 'initial_amount'],
        'rate with more than two decimals' => [fn () => newMonthlyGoal(['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 10.555]), 'monthly_target_rate'],
        'name too long' => [fn () => newMonthlyGoal(['name' => str_repeat('a', 256)]), 'name'],
        'not a savings account' => [fn (User $user) => newMonthlyGoal(['auto_tag_account_id' => Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Checking])->id]), 'auto_tag_account_id'],
        'account of another space' => [fn (User $user) => newMonthlyGoal(['auto_tag_account_id' => savingsAccount(User::factory()->create())->id]), 'auto_tag_account_id', 'The auto tag account id is not an account of yours in this space.'],
        'unknown field' => [fn () => newMonthlyGoal(['target' => 100]), 'target', 'This endpoint does not take a target field.'],
        'a list for the kind' => [fn () => newMonthlyGoal(['kind' => ['monthly']]), 'kind'],
        'a list for the name' => [fn () => newMonthlyGoal(['name' => ['Fund']]), 'name'],
        'a list for the auto-tag account' => [fn (User $user) => newMonthlyGoal(['auto_tag_account_id' => [savingsAccount($user)->id]]), 'auto_tag_account_id'],
    ]);

    it('refuses an account another running goal already auto-tags', function () {
        $user = goalUser();
        $savings = savingsAccount($user);
        $headers = goalsWriter($user);

        withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal(['auto_tag_account_id' => $savings->id]))->assertCreated();
        $error = withHeaders($headers)
            ->postJson('/api/v1/savings-goals', newMonthlyGoal(['name' => 'Japan trip', 'auto_tag_account_id' => $savings->id]))
            ->assertUnprocessable()
            ->json('errors.auto_tag_account_id.0');

        expect($error)->toContain('already feeds the running monthly goal "Emergency fund"')
            ->and($user->savingsGoals()->count())->toBe(1);
    });

    it('never names another member\'s goal when refusing an account an agent tagged for them', function () {
        $user = goalUser();
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $mine = savingsAccount($user, ['space_id' => $space->id]);
        SavingsGoal::factory()->monthly(30_000)->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Secret surgery', 'auto_tag_account_id' => $mine->id]);

        $error = withHeaders(goalsWriter($user))
            ->postJson("/api/v1/savings-goals?space={$space->id}", newMonthlyGoal(['auto_tag_account_id' => $mine->id]))
            ->assertUnprocessable()
            ->json('errors.auto_tag_account_id.0');

        expect($error)->toContain('already feeds a running monthly goal')
            ->not->toContain('Secret surgery');
    });

    it('refuses another member\'s savings account of the shared space as the auto-tag account', function () {
        $user = goalUser();
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $theirs = savingsAccount($owner, ['space_id' => $space->id]);

        withHeaders(goalsWriter($user))
            ->postJson("/api/v1/savings-goals?space={$space->id}", newMonthlyGoal(['auto_tag_account_id' => $theirs->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.auto_tag_account_id.0', 'The auto tag account id is not an account of yours in this space.');

        expect($user->savingsGoals()->count())->toBe(0);
    });

    it('refuses a read-only token', function () {
        $user = goalUser();

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/savings-goals', newMonthlyGoal())
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($user->savingsGoals()->count())->toBe(0);
    });

    it('replays a POST with the same idempotency key instead of creating a second goal', function () {
        $user = goalUser();
        $headers = [...goalsWriter($user), 'Idempotency-Key' => 'create-emergency-fund'];

        $first = withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal())->assertCreated();
        $replay = withHeaders($headers)->postJson('/api/v1/savings-goals', newMonthlyGoal())->assertCreated();

        $replay->assertHeader('Idempotent-Replayed', 'true');
        expect($replay->json('data.id'))->toBe($first->json('data.id'))
            ->and($user->savingsGoals()->count())->toBe(1);
    });
});

describe('update', function () {
    it('changes the month in progress and leaves past months alone', function () {
        $this->travelTo(Carbon::parse('2026-09-10'));
        $user = goalUser();
        $goal = apiMonthlyGoal($user, ['name' => 'Emergency fund']);
        $this->travelTo(Carbon::parse('2026-10-03 12:00:00'));
        app(SavingsGoalPeriodService::class)->advance($goal);

        withHeaders(goalsWriter($user))
            ->patchJson("/api/v1/savings-goals/{$goal->id}", [
                'name' => 'Rainy day',
                'monthly_target_type' => 'income_rate',
                'monthly_target_rate' => 15,
                'notify_on_month_end_reminder' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Rainy day')
            ->assertJsonPath('data.monthly_target_type', 'income_rate')
            ->assertJsonPath('data.monthly_target_amount', null)
            ->assertJsonPath('data.notify_on_month_end_reminder', false);

        $goal->refresh();
        $periods = $goal->periods()->orderBy('month')->get();
        expect($goal->label->name)->toBe('Rainy day')
            ->and($periods[0]->target_amount)->toBe(30_000)
            ->and($periods[1]->target_type->value)->toBe('income_rate');
    });

    it('refuses the kind and the fields of the other kind', function (array $body, string $field, string $message) {
        $user = goalUser();
        $goal = apiMonthlyGoal($user, ['name' => 'Fund']);

        $response = withHeaders(goalsWriter($user))
            ->patchJson("/api/v1/savings-goals/{$goal->id}", $body)
            ->assertUnprocessable();

        expect($response->json('errors')[$field][0])->toBe($message)
            ->and($goal->fresh()->isMonthly())->toBeTrue();
    })->with([
        'kind' => [['kind' => 'one_off'], 'kind', 'A goal\'s kind is fixed once created. To switch, create a new goal of the other kind.'],
        'one-off field' => [['target_amount' => 100], 'target_amount', 'target_amount only applies to one_off goals; this one is monthly.'],
        'amount without its type' => [['monthly_target_amount' => 100], 'monthly_target_type', 'The monthly target type field is required when monthly target amount / monthly target rate is present.'],
    ]);

    it('refuses an archived goal and a read-only token, and answers 404 for a co-member\'s or someone else\'s', function () {
        $user = goalUser();
        $archived = apiMonthlyGoal($user, ['name' => 'Old']);
        app(SavingsGoalService::class)->archive($archived);
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $coMembers = SavingsGoal::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
        $headers = goalsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/savings-goals/{$archived->id}", ['name' => 'Back'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.savings_goal.0', "Savings goal {$archived->id} is archived and can no longer be changed. Archiving and deleting goals happen in the Whisper Money app.");

        withHeaders($headers)->getJson("/api/v1/savings-goals/{$coMembers->id}?space={$space->id}")
            ->assertNotFound();

        withHeaders($headers)->patchJson("/api/v1/savings-goals/{$coMembers->id}?space={$space->id}", ['name' => 'Mine'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');

        withHeaders($headers)->patchJson('/api/v1/savings-goals/'.SavingsGoal::factory()->create()->id, ['name' => 'Mine'])
            ->assertNotFound();

        withHeaders(apiV1Bearer($user))
            ->patchJson('/api/v1/savings-goals/'.apiMonthlyGoal($user)->id, ['name' => 'Fund'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect($archived->fresh()->name)->toBe('Old')
            ->and($coMembers->fresh()->name)->not->toBe('Mine');
    });
});

describe('labels', function () {
    it('keeps a goal\'s label out of reach of the labels endpoints, and renames it with the goal', function () {
        $user = goalUser();
        $goal = apiMonthlyGoal($user, ['name' => 'Fund']);
        $headers = goalsWriter($user);

        withHeaders($headers)->patchJson("/api/v1/labels/{$goal->label_id}", ['name' => 'Other'])->assertUnprocessable();
        withHeaders($headers)->deleteJson("/api/v1/labels/{$goal->label_id}")->assertUnprocessable();

        withHeaders($headers)->patchJson("/api/v1/savings-goals/{$goal->id}", ['name' => 'Renamed'])->assertOk();

        expect($goal->fresh()->label->name)->toBe('Renamed')
            ->and($goal->fresh()->label->source)->toBe(LabelSource::SavingsGoal);
    });
});

describe('a goal measured against a shared space\'s income', function () {
    it('never shows a co-member\'s income, whoever of the two owns the space', function (bool $callerOwnsTheSpace) {
        $saver = goalUser();
        $earner = goalUser();
        $space = $callerOwnsTheSpace ? sharedGoalSpace($earner, $saver) : sharedGoalSpace($saver, $earner);
        $goal = goalOnTheIncomeOf($earner, $saver, $space);
        $headers = goalsWriter($saver);

        $list = withHeaders($headers)->getJson("/api/v1/savings-goals?space={$space->id}")->assertOk();
        expectNoIncomeFigure($list, 'data.0');
        $list->assertJsonPath('data.0.monthly_target_type', 'income_rate')
            ->assertJsonPath('data.0.monthly_target_rate', 10)
            ->assertJsonPath('data.0.monthly_target_amount', null);

        expectNoIncomeFigure(withHeaders($headers)->getJson("/api/v1/savings-goals/{$goal->id}?space={$space->id}")->assertOk());
        expectNoIncomeFigure(
            withHeaders($headers)->patchJson("/api/v1/savings-goals/{$goal->id}?space={$space->id}", ['name' => 'Renamed'])
                ->assertOk()
                ->assertJsonPath('data.name', 'Renamed'),
        );
    })->with(['the caller is a member' => false, 'the caller is the owner' => true]);

    it('hides them on an archived goal too, and leaves a one-off goal of the same space alone', function () {
        $saver = goalUser();
        $earner = goalUser();
        $space = sharedGoalSpace($saver, $earner);
        $goal = goalOnTheIncomeOf($earner, $saver, $space);
        app(SavingsGoalService::class)->archive($goal);
        $oneOff = SavingsGoal::factory()->create(['user_id' => $saver->id, 'space_id' => $space->id, 'target_amount' => 500_000]);

        $response = withHeaders(goalsWriter($saver))->getJson("/api/v1/savings-goals?space={$space->id}")->assertOk();
        $row = fn (string $id): array => collect($response->json('data'))->firstWhere('id', $id);

        expect($response->getContent())->not->toContain('4321700')->not->toContain('432170')->not->toContain('419825')
            ->and($row($goal->id)['monthly']['current'])->toBeNull()
            ->and($row($goal->id)['monthly']['history'][0]['saved'])->toBe(12_345)
            ->and($row($goal->id)['monthly']['history'][0]['target'])->toBeNull()
            ->and($row($oneOff->id)['target_amount'])->toBe(500_000)
            ->and($row($oneOff->id)['progress'])->not->toBeNull();
    });

    it('keeps the months measured against that income hidden once the goal moves to a fixed amount', function () {
        $saver = goalUser();
        $earner = goalUser();
        $space = sharedGoalSpace($saver, $earner);
        $goal = goalOnTheIncomeOf($earner, $saver, $space);
        $headers = goalsWriter($saver);
        $url = "/api/v1/savings-goals/{$goal->id}?space={$space->id}";

        $moved = withHeaders($headers)->patchJson($url, ['monthly_target_type' => 'amount', 'monthly_target_amount' => 20_000])
            ->assertOk()
            ->assertJsonPath('data.monthly_target_type', 'amount')
            ->assertJsonPath('data.monthly_target_amount', 20_000);

        foreach ([$moved, withHeaders($headers)->getJson($url)->assertOk()] as $response) {
            expect($response->getContent())->not->toContain('4321700')->not->toContain('432170')->not->toContain('419825')
                ->and($response->json('data.monthly.history.0.target'))->toBeNull()
                ->and($response->json('data.monthly.history.0.income_base'))->toBeNull()
                ->and($response->json('data.monthly.cumulative_difference'))->toBeNull();
        }
    });

    it('hides the figures of a goal whose space gains a member after it was created', function () {
        $saver = goalUser();
        $earner = goalUser();
        $space = Space::factory()->create(['owner_id' => $saver->id, 'personal' => false]);
        $goal = goalOnTheIncomeOf($saver, $saver, $space);
        $url = "/api/v1/savings-goals/{$goal->id}?space={$space->id}";

        expectIncomeFigures(withHeaders(goalsWriter($saver))->getJson($url)->assertOk());

        $space->members()->attach($earner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

        expectNoIncomeFigure(withHeaders(goalsWriter($saver))->getJson($url)->assertOk());
    });

    it('leaves a goal with nobody else in its space, and a fixed amount in a shared one, as they are', function () {
        $user = goalUser();
        $alone = Space::factory()->create(['owner_id' => $user->id, 'personal' => false]);
        $goal = goalOnTheIncomeOf($user, $user, $alone);
        $personal = goalOnTheIncomeOf($user, $user, $user->personalSpace);

        expectIncomeFigures(withHeaders(goalsWriter($user))->getJson("/api/v1/savings-goals/{$goal->id}?space={$alone->id}")->assertOk());
        expectIncomeFigures(withHeaders(goalsWriter($user))->getJson("/api/v1/savings-goals/{$personal->id}")->assertOk());

        $shared = sharedGoalSpace($user, goalUser());
        $amount = apiMonthlyGoal($user, ['space_id' => $shared->id]);

        withHeaders(goalsWriter($user))->getJson("/api/v1/savings-goals/{$amount->id}?space={$shared->id}")
            ->assertOk()
            ->assertJsonPath('data.monthly.current.target', 30_000)
            ->assertJsonPath('data.monthly.current.remaining', 30_000)
            ->assertJsonPath('data.monthly.current.status', 'in_progress')
            ->assertJsonPath('data.monthly.months_closed', 0);
    });

    it('reads a page of a shared space\'s goals in the same number of database queries however many it holds', function () {
        $user = goalUser();
        $space = sharedGoalSpace($user, goalUser());
        $headers = apiV1Bearer($user);
        $queriesFor = function () use ($headers, $space): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            withHeaders($headers)->getJson("/api/v1/savings-goals?space={$space->id}")->assertOk();

            return collect(DB::getQueryLog())->reject(fn (array $query): bool => str_contains($query['query'], '`cache`'))->count();
        };

        apiMonthlyGoal($user, ['space_id' => $space->id]);
        $queriesFor();
        $one = $queriesFor();
        apiMonthlyGoal($user, ['space_id' => $space->id]);
        apiMonthlyGoal($user, ['space_id' => $space->id]);

        expect($queriesFor())->toBe($one);
    });

    it('refuses an income rate on a goal in a space with another member', function (string $target) {
        $saver = goalUser();
        $space = sharedGoalSpace($saver, goalUser());
        $body = ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 10];
        $amount = apiMonthlyGoal($saver, ['space_id' => $space->id]);
        $rate = SavingsGoal::factory()->incomeRate(5)->create(['user_id' => $saver->id, 'space_id' => $space->id]);

        $response = match ($target) {
            'create' => withHeaders(goalsWriter($saver))->postJson("/api/v1/savings-goals?space={$space->id}", newMonthlyGoal(['monthly_target_amount' => null, ...$body])),
            'switch' => withHeaders(goalsWriter($saver))->patchJson("/api/v1/savings-goals/{$amount->id}?space={$space->id}", $body),
            'new rate' => withHeaders(goalsWriter($saver))->patchJson("/api/v1/savings-goals/{$rate->id}?space={$space->id}", $body),
        };

        $response->assertUnprocessable()
            ->assertJsonPath('errors.monthly_target_type.0', 'An income rate is measured against the income of everyone in a shared space. Use an amount in this space.');

        expect($amount->fresh()->monthly_target_type->value)->toBe('amount')
            ->and((float) $rate->fresh()->monthly_target_rate)->toBe(5.0)
            ->and($saver->savingsGoals()->count())->toBe(2);
    })->with(['create', 'switch', 'new rate']);

    it('takes an income rate where the user is alone', function () {
        $user = goalUser();
        $alone = Space::factory()->create(['owner_id' => $user->id, 'personal' => false]);
        $body = newMonthlyGoal(['monthly_target_amount' => null, 'monthly_target_type' => 'income_rate', 'monthly_target_rate' => 10]);

        withHeaders(goalsWriter($user))->postJson('/api/v1/savings-goals', $body)->assertCreated();
        $created = withHeaders(goalsWriter($user))->postJson("/api/v1/savings-goals?space={$alone->id}", [...$body, 'name' => 'Other'])->assertCreated();

        withHeaders(goalsWriter($user))->patchJson('/api/v1/savings-goals/'.$created->json('data.id')."?space={$alone->id}", ['monthly_target_type' => 'income_rate', 'monthly_target_rate' => 12])
            ->assertOk()
            ->assertJsonPath('data.monthly_target_rate', 12);
    });

    it('reports a type refused for its own reason only once', function () {
        $saver = goalUser();
        $space = sharedGoalSpace($saver, goalUser());

        withHeaders(goalsWriter($saver))->postJson("/api/v1/savings-goals?space={$space->id}", newMonthlyGoal(['monthly_target_type' => ['income_rate']]))
            ->assertUnprocessable()
            ->assertJsonCount(1, 'errors.monthly_target_type');
    });
});

describe('the MCP tools, which read a whole space', function () {
    it('still lists every member\'s goals and tags any member\'s account', function () {
        $user = goalUser();
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $theirs = SavingsGoal::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Their goal']);
        $theirAccount = savingsAccount($owner, ['space_id' => $space->id]);
        $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

        WhisperMoneyServer::actingAs($user)->tool(ListSavingsGoals::class, ['space' => $space->id])
            ->assertOk()
            ->assertSee($theirs->id);

        WhisperMoneyServer::actingAs($user)->tool(CreateSavingsGoal::class, [
            'space' => $space->id,
            'name' => 'Rainy day',
            'kind' => 'monthly',
            'monthly_target_type' => 'amount',
            'monthly_target_amount' => 30_000,
            'auto_tag_account_id' => $theirAccount->id,
        ])->assertOk();

        expect($user->savingsGoals()->sole()->auto_tag_account_id)->toBe($theirAccount->id);
    });

    it('still shows the figures of a goal measured against a shared space\'s income, and still takes a rate there', function () {
        $saver = goalUser();
        $earner = goalUser();
        $space = sharedGoalSpace($saver, $earner);
        $goal = goalOnTheIncomeOf($earner, $saver, $space);
        $saver->withAccessToken($saver->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

        WhisperMoneyServer::actingAs($saver)->tool(ListSavingsGoals::class, ['space' => $space->id])
            ->assertOk()
            ->assertSee('{"month":"2026-09","target":432170,"saved":12345,"difference":-419825,"status":"missed","income_base":4321700}')
            ->assertSee('"months_met":0,"months_closed":1,"cumulative_difference":-419825,"streak":0,"best_streak":0');

        WhisperMoneyServer::actingAs($saver)->tool(UpdateSavingsGoal::class, [
            'space' => $space->id,
            'savings_goal_id' => $goal->id,
            'monthly_target_type' => 'income_rate',
            'monthly_target_rate' => 12,
        ])->assertOk();

        WhisperMoneyServer::actingAs($saver)->tool(CreateSavingsGoal::class, [
            'space' => $space->id,
            'name' => 'Rainy day',
            'kind' => 'monthly',
            'monthly_target_type' => 'income_rate',
            'monthly_target_rate' => 10,
        ])->assertOk();

        expect((float) $goal->fresh()->monthly_target_rate)->toBe(12.0);
    });

    it('still names the goal feeding an account', function () {
        $user = goalUser();
        $owner = goalUser();
        $space = sharedGoalSpace($user, $owner);
        $account = savingsAccount($user, ['space_id' => $space->id]);
        SavingsGoal::factory()->monthly(30_000)->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Their goal', 'auto_tag_account_id' => $account->id]);
        $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

        WhisperMoneyServer::actingAs($user)->tool(CreateSavingsGoal::class, [
            'space' => $space->id,
            'name' => 'Rainy day',
            'kind' => 'monthly',
            'monthly_target_type' => 'amount',
            'monthly_target_amount' => 30_000,
            'auto_tag_account_id' => $account->id,
        ])->assertHasErrors(['already feeds the running monthly goal "Their goal"']);
    });
});
