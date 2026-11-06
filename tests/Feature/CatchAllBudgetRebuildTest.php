<?php

use App\Enums\CategoryType;
use App\Enums\RolloverType;
use App\Jobs\RebuildCatchAllBudgetPeriods;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\DeleteBudget;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\BudgetManagementService;
use App\Services\BudgetTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Mail::fake();

    $this->travelTo(now()->setDate(2026, 10, 15)->setTime(12, 0));

    $this->user = User::factory()->create(['onboarded_at' => now()]);
    $this->rebuilder = app(BudgetTransactionService::class);
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $this->other = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $this->label = Label::factory()->create(['user_id' => $this->user->id]);
});

/**
 * A budget created the way the app creates one, its history backfilled by the
 * (sync) queue.
 *
 * @param  array<int, string>  $categoryIds
 * @param  array<int, string>  $labelIds
 */
function createBudget(User $user, string $periodType, array $categoryIds = [], array $labelIds = [], bool $isCatchAll = false, string $rollover = 'reset'): Budget
{
    return app(BudgetManagementService::class)->create($user, $user->personalSpace, [
        'name' => $isCatchAll ? 'Everything else' : 'Tracked',
        'period_type' => $periodType,
        'period_start_day' => 1,
        'rollover_type' => $rollover,
        'is_catch_all' => $isCatchAll,
        'allocated_amount' => 100000,
    ], $categoryIds, $labelIds)['budget'];
}

function periodOn(Budget $budget, string $date): BudgetPeriod
{
    return $budget->getCurrentPeriod(Carbon::parse($date));
}

function expense(User $user, Category $category, string $date, int $amount = -1000, ?Label $label = null, array $attributes = []): Transaction
{
    $transaction = Transaction::factory()->forUser($user)->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'category_id' => $category->id,
        'transaction_date' => $date,
        'amount' => $amount,
        ...$attributes,
    ]);

    if ($label) {
        $transaction->labels()->attach($label);
        app(BudgetTransactionService::class)->assignTransaction($transaction);
    }

    return $transaction;
}

/**
 * @param  array<int, array{0: Category, 1: int}>  $lines
 */
function splitInto(Transaction $transaction, array $lines): Transaction
{
    foreach ($lines as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    app(BudgetTransactionService::class)->assignTransaction($transaction->fresh());

    return $transaction;
}

/**
 * The state the bug left behind: the catch-all counting what a budget counts too.
 */
function doubleCount(BudgetPeriod $catchAll, Transaction $transaction, int $amount): void
{
    BudgetTransaction::query()->updateOrCreate(
        ['budget_period_id' => $catchAll->id, 'transaction_id' => $transaction->id],
        ['amount' => $amount],
    );
}

/**
 * @return array<int, string>
 */
function periodTransactionIds(BudgetPeriod $period): array
{
    return BudgetTransaction::query()->where('budget_period_id', $period->id)->pluck('transaction_id')->all();
}

/**
 * @return array<int, string>
 */
function budgetTransactionIds(Budget $budget): array
{
    return BudgetTransaction::query()
        ->whereIn('budget_period_id', $budget->periods()->select('id'))
        ->pluck('transaction_id')
        ->all();
}

function snapshotAmount(BudgetPeriod $period, Transaction $transaction): ?int
{
    return BudgetTransaction::query()
        ->where('budget_period_id', $period->id)
        ->where('transaction_id', $transaction->id)
        ->value('amount');
}

test('creating a budget takes its expenses out of the catch-all', function (string $trackedBy) {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    $label = $trackedBy === 'label' ? $this->label : null;
    $transaction = expense($this->user, $this->category, '2026-10-05', label: $label);

    expect(periodTransactionIds($current))->toBe([$transaction->id]);

    $budget = $trackedBy === 'label'
        ? createBudget($this->user, 'monthly', labelIds: [$this->label->id])
        : createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);

    expect(periodTransactionIds($current))->toBe([])
        ->and(budgetTransactionIds($budget))->toBe([$transaction->id])
        ->and($current->fresh()->processing_historical)->toBeFalse()
        ->and($current->fresh()->reconciliation_token)->toBeNull();

    // Moving rows around is a backfill, not spending: nobody gets an email.
    Mail::assertNothingSent();
})->with(['category', 'label']);

test('a catch-all budget queues no rebuild, nor does a budget with no catch-all around', function () {
    Queue::fake();

    createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    createBudget($this->user, 'yearly', isCatchAll: true);

    Queue::assertNotPushed(RebuildCatchAllBudgetPeriods::class);
});

test('a new budget claims the catch-all periods it overlaps and makes the rebuild wait for its backfill', function () {
    Queue::fake();
    $catchAll = Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id]);
    $october = BudgetPeriod::factory()->create(['budget_id' => $catchAll->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $september = BudgetPeriod::factory()->create(['budget_id' => $catchAll->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    $august = BudgetPeriod::factory()->create(['budget_id' => $catchAll->id, 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']);

    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);

    expect($october->fresh()->processing_historical)->toBeTrue()
        ->and($september->fresh()->processing_historical)->toBeTrue()
        ->and($august->fresh()->processing_historical)->toBeFalse();

    $tokens = [
        $october->id => $october->fresh()->reconciliation_token,
        $september->id => $september->fresh()->reconciliation_token,
    ];

    Queue::assertPushed(RebuildCatchAllBudgetPeriods::class, fn (RebuildCatchAllBudgetPeriods $job): bool => collect($job->reconciliationTokens)->sortKeys()->all() === collect($tokens)->sortKeys()->all()
        && collect($job->awaitPeriodIds)->sort()->values()->all() === $budget->periods()->pluck('id')->sort()->values()->all());
});

test('a new budget also frees the closed catch-all period its backfill reaches, and leaves the rest of it alone', function () {
    $catchAll = createBudget($this->user, 'monthly', isCatchAll: true);
    $september = periodOn($catchAll, '2026-09-10');
    $claimed = expense($this->user, $this->category, '2026-09-05');
    $unclaimed = expense($this->user, $this->other, '2026-09-06', -2500);
    $september->update(['allocated_amount' => 77700, 'regular_allocated_amount' => 100000, 'carried_over_amount' => 1200, 'over_limit_notified' => true]);
    $untouchedRow = BudgetTransaction::query()->where('budget_period_id', $september->id)->where('transaction_id', $unclaimed->id)->sole();

    $this->travel(1)->hour();
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);

    $settled = $september->fresh();

    expect(periodTransactionIds($september))->toBe([$unclaimed->id])
        ->and(budgetTransactionIds($budget))->toBe([$claimed->id])
        ->and($settled->processing_historical)->toBeFalse()
        ->and($settled->allocated_amount)->toBe(77700)
        ->and($settled->regular_allocated_amount)->toBe(100000)
        ->and($settled->carried_over_amount)->toBe(1200)
        ->and($settled->over_limit_notified)->toBeTrue()
        ->and($untouchedRow->fresh()->updated_at->equalTo($untouchedRow->updated_at))->toBeTrue();
});

test('deleting a budget hands the running catch-all period its expenses, and closed periods stay as they ran', function () {
    $catchAll = createBudget($this->user, 'monthly', isCatchAll: true);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $october = expense($this->user, $this->category, '2026-10-05');
    $september = expense($this->user, $this->category, '2026-09-05');

    expect(periodTransactionIds($catchAll->getCurrentPeriod()))->toBe([]);

    $this->actingAs($this->user)->delete(route('budgets.destroy', $budget))->assertRedirect(route('budgets.index'));

    expect(periodTransactionIds($catchAll->getCurrentPeriod()))->toBe([$october->id])
        ->and(periodTransactionIds(periodOn($catchAll, '2026-09-10')))->not->toContain($september->id);
});

test('the MCP delete tool hands expenses back through the service alone', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-10-05');

    $this->user->withAccessToken($this->user->createToken('mcp-rebuild-test', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($this->user)
        ->tool(DeleteBudget::class, ['budget_id' => $budget->id])
        ->assertOk();

    expect(periodTransactionIds($catchAll->getCurrentPeriod()))->toBe([$transaction->id]);
});

test('archiving a budget freezes what it holds and hands the catch-all everything else', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $before = expense($this->user, $this->category, '2026-10-05');
    $sameDay = expense($this->user, $this->category, '2026-10-15');
    $scheduled = expense($this->user, $this->category, '2026-10-20');
    $held = [$before->id, $sameDay->id, $scheduled->id];

    $this->actingAs($this->user)->post(route('budgets.archive', $budget))->assertRedirect(route('budgets.show', $budget));

    // Everything it counted stays with it and only there, unlike upstream,
    // which drops what is dated from the archive day on.
    expect(budgetTransactionIds($budget))->toEqualCanonicalizing($held)
        ->and(periodTransactionIds($current))->toBe([])
        ->and($this->rebuilder->rebuildCatchAllPeriod($current))->toBe(['released' => 0, 'adjusted' => 0, 'absorbed' => 0, 'spent_change' => 0]);

    // And it takes in nothing more, dated before its archive day or not.
    $late = expense($this->user, $this->category, '2026-10-01');
    $after = expense($this->user, $this->category, '2026-10-16');

    expect(budgetTransactionIds($budget))->toEqualCanonicalizing($held)
        ->and(periodTransactionIds($current))->toEqualCanonicalizing([$late->id, $after->id]);

    Mail::assertNothingSent();
});

test('a transaction an archived budget holds stays out of the catch-all even after moving to another category', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-10-05');

    $this->actingAs($this->user)->post(route('budgets.archive', $budget));

    $transaction->update(['category_id' => $this->other->id]);
    $this->rebuilder->assignTransaction($transaction->fresh());

    expect(budgetTransactionIds($budget))->toBe([$transaction->id])
        ->and(periodTransactionIds($catchAll->getCurrentPeriod()))->toBe([]);
});

test('archiving twice queues a single rebuild, and archiving a catch-all none', function () {
    Queue::fake();
    Budget::factory()->yearly()->catchAll()->has(BudgetPeriod::factory()->state(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']), 'periods')->create(['user_id' => $this->user->id]);
    $budget = Budget::factory()->monthly()->forCategories($this->category)->has(BudgetPeriod::factory()->state(['start_date' => '2026-10-01', 'end_date' => '2026-10-31']), 'periods')->create(['user_id' => $this->user->id]);

    $service = app(BudgetManagementService::class);
    $service->archive($budget);
    $service->archive($budget);

    Queue::assertPushed(RebuildCatchAllBudgetPeriods::class, 1);

    $service->archive(Budget::query()->where('is_catch_all', true)->sole());

    Queue::assertPushed(RebuildCatchAllBudgetPeriods::class, 1);
});

test('an expense stays in a longer catch-all period when no budget period covers its date', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    // Its periods only start in September, but they overlap the yearly catch-all.
    $budget = createBudget($this->user, 'monthly', labelIds: [$this->label->id]);

    $march = expense($this->user, $this->category, '2026-03-10', label: $this->label);

    // Nothing else counts it, so overlap semantics would lose it. Both paths
    // read the same claimants, so the rebuild agrees with the assignment.
    expect(periodTransactionIds($current))->toBe([$march->id])
        ->and(budgetTransactionIds($budget))->toBe([]);

    $this->rebuilder->rebuildCatchAllPeriod($current);

    expect(periodTransactionIds($current))->toBe([$march->id]);
});

test('a rebuild counts split lines and ownership the way the snapshots do', function () {
    $account = Account::factory()->create(['user_id' => $this->user->id, 'space_id' => $this->user->personalSpace->id, 'ownership_percentage' => 50]);
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    $transaction = splitInto(
        expense($this->user, $this->category, '2026-10-05', -10000, attributes: ['account_id' => $account->id]),
        [[$this->category, -3000], [$this->other, -7000]],
    );

    expect(snapshotAmount($current, $transaction))->toBe(5000);

    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);

    expect(snapshotAmount($current, $transaction))->toBe(3500)
        ->and(snapshotAmount($budget->getCurrentPeriod(), $transaction))->toBe(1500);
});

test('a label budget takes a split transaction whole out of the catch-all', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    $transaction = splitInto(
        expense($this->user, $this->category, '2026-10-05', -10000, $this->label),
        [[$this->category, -3000], [$this->other, -7000]],
    );

    expect(snapshotAmount($current, $transaction))->toBe(10000);

    createBudget($this->user, 'monthly', labelIds: [$this->label->id]);

    expect(snapshotAmount($current, $transaction))->toBeNull();
});

test('a closed period is judged by the rows other budgets hold, not by what they track today', function () {
    $catchAll = createBudget($this->user, 'monthly', isCatchAll: true);
    $september = periodOn($catchAll, '2026-09-10');
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $heldThen = expense($this->user, $this->category, '2026-09-05', -1000);
    $trackedOnlyNow = expense($this->user, $this->other, '2026-09-06', -2000);
    $unassigned = expense($this->user, $this->other, '2026-09-07', -3000);
    BudgetTransaction::query()->where('transaction_id', $unassigned->id)->delete();
    doubleCount($september, $heldThen, 1000);

    app(BudgetManagementService::class)->update($this->user, $this->user->personalSpace, $budget->id, ['category_ids' => [$this->other->id]], CarbonImmutable::today());

    $changes = $this->rebuilder->rebuildCatchAllPeriod($september->fresh());

    // The double count goes, what only the catch-all counted stays although the
    // budget tracks that category now, and a closed period absorbs nothing.
    expect($changes)->toBe(['released' => 1, 'adjusted' => 0, 'absorbed' => 0, 'spent_change' => -1000])
        ->and(periodTransactionIds($september))->toBe([$trackedOnlyNow->id])
        ->and(periodTransactionIds(periodOn($budget, '2026-09-10')))->toBe([$heldThen->id]);
});

test('a running catch-all period does not pick up what a closed budget period still holds', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $current = $catchAll->getCurrentPeriod();
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-09-05');

    app(BudgetManagementService::class)->update($this->user, $this->user->personalSpace, $budget->id, ['category_ids' => [$this->other->id]], CarbonImmutable::today());

    expect(periodTransactionIds(periodOn($budget, '2026-09-10')))->toBe([$transaction->id])
        ->and(periodTransactionIds($current))->toBe([]);
});

test('a row in a running budget period vouches for nothing: a pending tracking change may drop it', function () {
    $catchAll = createBudget($this->user, 'yearly', isCatchAll: true);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-10-05');

    // What a tracking edit looks like while its own reconciliation is queued.
    $budget->categories()->sync([$this->other->id]);

    $this->rebuilder->rebuildCatchAllPeriod($catchAll->getCurrentPeriod());

    expect(periodTransactionIds($catchAll->getCurrentPeriod()))->toBe([$transaction->id]);
});

test('a tracking change reconciles the running catch-all and leaves an archived one alone', function () {
    Queue::fake();
    $archived = Budget::factory()->archived()->monthly()->catchAll()->create(['user_id' => $this->user->id]);
    $archivedPeriod = BudgetPeriod::factory()->create(['budget_id' => $archived->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $running = Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id]);
    $runningPeriod = BudgetPeriod::factory()->create(['budget_id' => $running->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $budget = Budget::factory()->monthly()->forCategories($this->category)->create(['user_id' => $this->user->id]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);

    app(BudgetManagementService::class)->update($this->user, $this->user->personalSpace, $budget->id, ['category_ids' => [$this->other->id]], CarbonImmutable::today());

    expect($runningPeriod->fresh()->processing_historical)->toBeTrue()
        ->and($archivedPeriod->fresh()->processing_historical)->toBeFalse();
});

test('a rebuild leaves an archived catch-all as it is', function () {
    $archived = Budget::factory()->archived()->monthly()->catchAll()->create(['user_id' => $this->user->id, 'space_id' => $this->user->personalSpace->id]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $archived->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
    $transaction = expense($this->user, $this->category, '2026-10-05');
    doubleCount($period, $transaction, 1000);

    expect($this->rebuilder->rebuildCatchAllPeriod($period)['released'])->toBe(0)
        ->and(periodTransactionIds($period))->toBe([$transaction->id]);
});

describe('the rebuild job', function () {
    beforeEach(function () {
        $this->catchAll = Budget::factory()->monthly()->catchAll()->create(['user_id' => $this->user->id, 'space_id' => $this->user->personalSpace->id]);
        $this->claimed = BudgetPeriod::factory()->create(['budget_id' => $this->catchAll->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'processing_historical' => true, 'reconciliation_token' => 'ours']);
        $this->superseded = BudgetPeriod::factory()->create(['budget_id' => $this->catchAll->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-30', 'processing_historical' => true, 'reconciliation_token' => 'newer']);
        $this->job = fn () => new RebuildCatchAllBudgetPeriods([$this->claimed->id => 'ours', $this->superseded->id => 'overtaken']);
    });

    test('it reconciles the periods it still holds and hands them back', function () {
        $missing = expense($this->user, $this->category, '2026-10-05');
        $overtaken = expense($this->user, $this->category, '2026-11-05');
        BudgetTransaction::query()->delete();

        ($this->job)()->handle($this->rebuilder);

        expect(periodTransactionIds($this->claimed))->toBe([$missing->id])
            ->and($this->claimed->fresh()->processing_historical)->toBeFalse()
            ->and($this->claimed->fresh()->reconciliation_token)->toBeNull()
            ->and(periodTransactionIds($this->superseded))->not->toContain($overtaken->id)
            ->and($this->superseded->fresh()->processing_historical)->toBeTrue()
            ->and($this->superseded->fresh()->reconciliation_token)->toBe('newer');
    });

    test('out of tries it hands back what it holds, from a fresh instance, and nothing a newer run owns', function () {
        $failing = Mockery::mock(BudgetTransactionService::class);
        $failing->shouldReceive('rebuildCatchAllPeriod')->andThrow(new RuntimeException('boom'));

        expect(fn () => ($this->job)()->handle($failing))->toThrow(RuntimeException::class);
        expect($this->claimed->fresh()->processing_historical)->toBeTrue();

        // The queue calls failed() on a new object, not the one that ran handle().
        ($this->job)()->failed(new RuntimeException('boom'));

        expect($this->claimed->fresh()->processing_historical)->toBeFalse()
            ->and($this->claimed->fresh()->reconciliation_token)->toBeNull()
            ->and($this->superseded->fresh()->processing_historical)->toBeTrue()
            ->and($this->superseded->fresh()->reconciliation_token)->toBe('newer');
    });

    test('it steps aside while the backfill it depends on is running', function () {
        $backfilling = BudgetPeriod::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'processing_historical' => true]);
        $missing = expense($this->user, $this->category, '2026-10-05');
        BudgetTransaction::query()->delete();

        $job = (new RebuildCatchAllBudgetPeriods([$this->claimed->id => 'ours'], [$backfilling->id]))->withFakeQueueInteractions();
        $job->handle($this->rebuilder);

        $job->assertReleased(delay: 10);
        expect(periodTransactionIds($this->claimed))->toBe([])
            ->and($this->claimed->fresh()->reconciliation_token)->toBe('ours');

        $backfilling->update(['processing_historical' => false]);
        $job = (new RebuildCatchAllBudgetPeriods([$this->claimed->id => 'ours'], [$backfilling->id]))->withFakeQueueInteractions();
        $job->handle($this->rebuilder);

        $job->assertNotReleased();
        expect(periodTransactionIds($this->claimed))->toBe([$missing->id]);
    });

    test('it keeps retrying for a while rather than counting tries', function () {
        expect(($this->job)()->retryUntil()->greaterThan(now()->addMinutes(14)))->toBeTrue()
            ->and(($this->job)()->maxExceptions)->toBe(3);
    });
});

/**
 * Double counts in a closed and in a running period, a split one among them,
 * and one expense the running period never picked up.
 *
 * @return array{catchAll: Budget, september: BudgetPeriod, october: BudgetPeriod, budget: Budget, closedWhole: Transaction, closedSplit: Transaction, closedOwn: Transaction, runningWhole: Transaction, missed: Transaction}
 */
function driftedCatchAll(object $test): array
{
    $catchAll = createBudget($test->user, 'monthly', isCatchAll: true);
    $budget = createBudget($test->user, 'monthly', categoryIds: [$test->category->id]);
    $september = periodOn($catchAll, '2026-09-10');
    $october = periodOn($catchAll, '2026-10-10');

    $closedWhole = expense($test->user, $test->category, '2026-09-05', -1000);
    $closedSplit = splitInto(expense($test->user, $test->category, '2026-09-06', -10000), [[$test->category, -3000], [$test->other, -7000]]);
    $closedOwn = expense($test->user, $test->other, '2026-09-07', -2500);
    $runningWhole = expense($test->user, $test->category, '2026-10-05', -4000);
    $missed = expense($test->user, $test->other, '2026-10-06', -500);
    BudgetTransaction::query()->where('transaction_id', $missed->id)->delete();

    doubleCount($september, $closedWhole, 1000);
    doubleCount($september, $closedSplit, 10000);
    doubleCount($october, $runningWhole, 4000);

    return compact('catchAll', 'september', 'october', 'budget', 'closedWhole', 'closedSplit', 'closedOwn', 'runningWhole', 'missed');
}

test('the rebuild command heals double counts, on closed periods only by what is double counted', function () {
    $world = driftedCatchAll($this);

    $this->artisan('budgets:rebuild-catch-all')
        ->expectsOutputToContain('Running periods: 1 transaction(s) were released, 0 were adjusted, 1 were absorbed')
        ->expectsOutputToContain('Closed periods: 1 transaction(s) were released, 1 were reduced; a closed period absorbs nothing')
        ->assertSuccessful();

    expect(periodTransactionIds($world['september']))->toEqualCanonicalizing([$world['closedSplit']->id, $world['closedOwn']->id])
        ->and(snapshotAmount($world['september'], $world['closedSplit']))->toBe(7000)
        ->and(snapshotAmount($world['september'], $world['closedOwn']))->toBe(2500)
        ->and(periodTransactionIds($world['october']))->toBe([$world['missed']->id])
        ->and(budgetTransactionIds($world['budget']))->toEqualCanonicalizing([$world['closedWhole']->id, $world['closedSplit']->id, $world['runningWhole']->id]);

    Mail::assertNothingSent();

    // Nothing left to do, so a second run changes nothing.
    $this->artisan('budgets:rebuild-catch-all')
        ->expectsOutputToContain('Running periods: 0 transaction(s) were released, 0 were adjusted, 0 were absorbed')
        ->expectsOutputToContain('Closed periods: 0 transaction(s) were released, 0 were reduced')
        ->assertSuccessful();
});

test('the dry run writes nothing and reports exactly what the real run then does', function () {
    $world = driftedCatchAll($this);
    Queue::fake();
    $tables = ['budget_transactions', 'budget_periods', 'budgets'];
    $dump = fn (): array => collect($tables)->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
    $before = $dump();
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    $drift = collect([$world['september'], $world['october']])->map(fn (BudgetPeriod $period): array => $this->rebuilder->catchAllDrift($period->fresh()))->all();

    $this->artisan('budgets:rebuild-catch-all', ['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('Running periods: 1 transaction(s) would be released, 0 would be adjusted, 1 would be absorbed')
        ->expectsOutputToContain('Closed periods: 1 transaction(s) would be released, 1 would be reduced')
        ->assertSuccessful();

    $writes = array_values(array_filter($statements, fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $sql) === 1));

    expect($statements)->not->toBe([])
        ->and($writes)->toBe([])
        ->and($dump())->toEqual($before);
    Queue::assertNothingPushed();
    Mail::assertNothingSent();

    $real = collect([$world['september'], $world['october']])->map(fn (BudgetPeriod $period): array => $this->rebuilder->rebuildCatchAllPeriod($period->fresh()))->all();

    expect($real)->toBe($drift)
        ->and($real[0])->toBe(['released' => 1, 'adjusted' => 1, 'absorbed' => 0, 'spent_change' => -4000])
        ->and($real[1])->toBe(['released' => 1, 'adjusted' => 0, 'absorbed' => 1, 'spent_change' => -3500]);
});

test('the rebuild command can be narrowed to one user', function () {
    $world = driftedCatchAll($this);
    $stranger = User::factory()->create();

    $this->artisan('budgets:rebuild-catch-all', ['--user' => $stranger->email])
        ->expectsOutputToContain('0 catch-all period(s) checked')
        ->assertSuccessful();

    expect(periodTransactionIds($world['october']))->toContain($world['runningWhole']->id);

    $this->artisan('budgets:rebuild-catch-all', ['--user' => $this->user->email])->assertSuccessful();

    expect(periodTransactionIds($world['october']))->not->toContain($world['runningWhole']->id);
});

test('the rebuild command refuses an unknown user', function () {
    $this->artisan('budgets:rebuild-catch-all', ['--user' => 'nobody@example.com'])->assertFailed();
});

test('healing a closed carry-over period is reported, and only the nightly close moves the carry-over', function () {
    $catchAll = createBudget($this->user, 'monthly', isCatchAll: true, rollover: RolloverType::CarryOver->value);
    $budget = createBudget($this->user, 'monthly', categoryIds: [$this->category->id]);
    $transaction = expense($this->user, $this->category, '2026-09-05', -1000);
    $september = periodOn($catchAll, '2026-09-10');
    $october = periodOn($catchAll, '2026-10-10');
    doubleCount($september, $transaction, 1000);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    expect($october->fresh()->carried_over_amount)->toBe(99000);

    $this->artisan('budgets:rebuild-catch-all')
        ->expectsOutputToContain('1 closed period(s) of carry-over budgets changed')
        ->assertSuccessful();

    expect($october->fresh()->carried_over_amount)->toBe(99000)
        ->and(budgetTransactionIds($budget))->toBe([$transaction->id]);

    $this->artisan('budgets:generate-periods')->assertSuccessful();

    expect($october->fresh()->carried_over_amount)->toBe(100000);
});
