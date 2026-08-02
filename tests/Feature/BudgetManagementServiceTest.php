<?php

use App\Enums\RolloverType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\BudgetManagementService;
use App\Services\BudgetTransactionService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

function managedBudgetWithPeriods(User $user, string $spaceId, int $startDay = 1): Budget
{
    $category = Category::factory()->create(['user_id' => $user->id, 'space_id' => $spaceId]);
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
        'period_start_day' => $startDay,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $budget->categories()->attach($category->id);

    return $budget;
}

function addManagedPeriod(Budget $budget, string $start, string $end, int $amount = 1000, int $carry = 0): BudgetPeriod
{
    return $budget->periods()->create([
        'start_date' => $start,
        'end_date' => $end,
        'allocated_amount' => $amount,
        'carried_over_amount' => $carry,
        'processing_historical' => false,
    ]);
}

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('updates the current and future monthly periods on their application date', function () {
    Queue::fake();
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000, 200);
    addManagedPeriod($budget, '2026-09-01', '2026-09-30');
    addManagedPeriod($budget, '2026-10-01', '2026-10-31');

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-08-01'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeTrue()
        ->and($result['adjustment']['affected_period_count'])->toBe(3)
        ->and($result['adjustment']['effective_from'])->toBe('2026-08-01');
    expect($budget->periods()->where('allocated_amount', 2500)->count())->toBe(3)
        ->and($budget->periods()->where('start_date', '2026-08-01')->value('carried_over_amount'))->toBe(200);
});

it('preserves the current period when a monthly update is applied after its start', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000);
    addManagedPeriod($budget, '2026-09-01', '2026-09-30');

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-08-02'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeFalse()
        ->and($budget->periods()->where('start_date', '2026-08-01')->value('allocated_amount'))->toBe(1000)
        ->and($budget->periods()->where('start_date', '2026-09-01')->value('allocated_amount'))->toBe(2500);
});

it('uses the real start date for displaced monthly periods and creates a successor when needed', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id, 15);
    addManagedPeriod($budget, '2026-08-15', '2026-09-14', 1000);

    $result = app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2500],
        CarbonImmutable::parse('2026-09-01'),
    );

    expect($result['adjustment']['current_period_changed'])->toBeFalse()
        ->and($budget->periods()->where('start_date', '2026-08-15')->value('allocated_amount'))->toBe(1000)
        ->and($budget->periods()->where('start_date', '2026-09-15')->value('allocated_amount'))->toBe(2500);
});

it('reconciles notification flags without sending notifications during allocation edits', function () {
    Queue::fake();
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $current = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 1000);
    $current->update(['close_to_limit_notified' => true, 'over_limit_notified' => true]);

    app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['allocated_amount' => 2000],
        CarbonImmutable::parse('2026-08-01'),
    );

    expect($current->fresh()->close_to_limit_notified)->toBeFalse()
        ->and($current->fresh()->over_limit_notified)->toBeFalse();
});

it('rejects a budget update without a mutable field', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);

    expect(fn () => app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        [],
        CarbonImmutable::parse('2026-08-01'),
    ))->toThrow(ValidationException::class);
});

it('updates tracking without replacing the budget or closed-period history', function () {
    Queue::fake();
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $oldCategory = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $newCategory = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $oldLabel = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $newLabel = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $budget = managedBudgetWithPeriods($user, $space->id);
    $budget->categories()->sync([$oldCategory->id]);
    $budget->labels()->sync([$oldLabel->id]);
    $previous = addManagedPeriod($budget, '2026-07-01', '2026-07-31');
    $current = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $periodIds = $budget->periods()->orderBy('start_date')->pluck('id')->all();
    $account = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $oldCategory->id,
        'transaction_date' => '2026-07-10',
        'amount' => -500,
    ]);
    $historicalAssignment = $previous->budgetTransactions()->create([
        'transaction_id' => $transaction->id,
        'amount' => 500,
    ]);
    $oldCurrentTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $oldCategory->id,
        'transaction_date' => '2026-08-10',
        'amount' => -700,
    ]);
    $newCurrentTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $newCategory->id,
        'transaction_date' => '2026-08-11',
        'amount' => -900,
    ]);
    $staleCurrentAssignment = $current->budgetTransactions()->create([
        'transaction_id' => $oldCurrentTransaction->id,
        'amount' => 700,
    ]);

    $result = app(BudgetManagementService::class)->update(
        $user,
        $space,
        $budget->id,
        ['category_ids' => [$newCategory->id], 'label_ids' => [$newLabel->id]],
        CarbonImmutable::parse('2026-08-15'),
    );

    expect($result['budget']->id)->toBe($budget->id)
        ->and($result['budget']->categories->modelKeys())->toBe([$newCategory->id])
        ->and($result['budget']->labels->modelKeys())->toBe([$newLabel->id])
        ->and($budget->periods()->orderBy('start_date')->pluck('id')->all())->toBe($periodIds)
        ->and($historicalAssignment->fresh())->not->toBeNull()
        ->and($previous->fresh()->processing_historical)->toBeFalse()
        ->and($current->fresh()->processing_historical)->toBeTrue();
    Queue::assertPushed(
        AssignHistoricalTransactionsToBudget::class,
        fn (AssignHistoricalTransactionsToBudget $job): bool => $job->period->is($current),
    );

    $current = $current->fresh();
    (new AssignHistoricalTransactionsToBudget($budget->fresh(), $current, $current->reconciliation_token))
        ->handle(app(BudgetTransactionService::class));

    expect($historicalAssignment->fresh())->not->toBeNull()
        ->and($staleCurrentAssignment->fresh())->toBeNull()
        ->and($current->budgetTransactions()->where('transaction_id', $newCurrentTransaction->id)->value('amount'))->toBe(900)
        ->and($current->fresh()->processing_historical)->toBeFalse();
});

it('does not reconcile active periods when submitted tracking is unchanged', function () {
    Queue::fake();
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $budget = managedBudgetWithPeriods($user, $space->id);
    $categoryIds = $budget->categories()->pluck('categories.id')->all();
    $current = addManagedPeriod($budget, '2026-08-01', '2026-08-31');

    app(BudgetManagementService::class)->update(
        $user,
        $space,
        $budget->id,
        ['name' => 'Renamed', 'category_ids' => $categoryIds, 'label_ids' => []],
        CarbonImmutable::parse('2026-08-15'),
    );

    expect($budget->fresh()->name)->toBe('Renamed')
        ->and($current->fresh()->processing_historical)->toBeFalse();
    Queue::assertNothingPushed();
});

it('reconciles the active catch-all period without touching closed catch-all periods', function () {
    Queue::fake();
    $user = User::factory()->create();
    $space = $user->personalSpace;
    $oldCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'type' => 'expense',
    ]);
    $newCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'type' => 'expense',
    ]);
    $budget = managedBudgetWithPeriods($user, $space->id);
    $budget->categories()->sync([$oldCategory->id]);
    $current = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $catchAll = Budget::factory()->monthly()->create([
        'user_id' => $user->id,
        'space_id' => $space->id,
        'is_catch_all' => true,
    ]);
    $closedCatchAllPeriod = addManagedPeriod($catchAll, '2026-07-01', '2026-07-31');
    $currentCatchAllPeriod = addManagedPeriod($catchAll, '2026-08-01', '2026-08-31');
    $account = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);
    $oldCategoryTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $oldCategory->id,
        'transaction_date' => '2026-08-10',
        'amount' => -400,
    ]);
    $newCategoryTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $newCategory->id,
        'transaction_date' => '2026-08-11',
        'amount' => -600,
    ]);
    $otherSpace = Space::factory()->create(['owner_id' => $user->id]);
    $otherSpaceCategory = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $otherSpace->id,
        'type' => 'expense',
    ]);
    $otherSpaceAccount = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $otherSpace->id,
    ]);
    $otherSpaceTransaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $otherSpaceAccount->id,
        'category_id' => $otherSpaceCategory->id,
        'transaction_date' => '2026-08-12',
        'amount' => -800,
    ]);
    $current->budgetTransactions()->create([
        'transaction_id' => $oldCategoryTransaction->id,
        'amount' => 400,
    ]);
    $currentCatchAllPeriod->budgetTransactions()->create([
        'transaction_id' => $newCategoryTransaction->id,
        'amount' => 600,
    ]);

    app(BudgetManagementService::class)->update(
        $user,
        $space,
        $budget->id,
        ['category_ids' => [$newCategory->id]],
        CarbonImmutable::parse('2026-08-15'),
    );

    expect($current->fresh()->processing_historical)->toBeTrue()
        ->and($currentCatchAllPeriod->fresh()->processing_historical)->toBeTrue()
        ->and($closedCatchAllPeriod->fresh()->processing_historical)->toBeFalse();
    Queue::assertPushed(AssignHistoricalTransactionsToBudget::class, 2);
    Queue::assertPushed(
        AssignHistoricalTransactionsToBudget::class,
        fn (AssignHistoricalTransactionsToBudget $job): bool => $job->period->is($currentCatchAllPeriod)
            && $job->budget->is($catchAll),
    );

    $current = $current->fresh();
    $currentCatchAllPeriod = $currentCatchAllPeriod->fresh();
    (new AssignHistoricalTransactionsToBudget($budget->fresh(), $current, $current->reconciliation_token))
        ->handle(app(BudgetTransactionService::class));
    (new AssignHistoricalTransactionsToBudget($catchAll->fresh(), $currentCatchAllPeriod, $currentCatchAllPeriod->reconciliation_token))
        ->handle(app(BudgetTransactionService::class));

    expect($current->budgetTransactions()->where('transaction_id', $oldCategoryTransaction->id)->exists())->toBeFalse()
        ->and($current->budgetTransactions()->where('transaction_id', $newCategoryTransaction->id)->value('amount'))->toBe(600)
        ->and($currentCatchAllPeriod->budgetTransactions()->where('transaction_id', $oldCategoryTransaction->id)->value('amount'))->toBe(400)
        ->and($currentCatchAllPeriod->budgetTransactions()->where('transaction_id', $newCategoryTransaction->id)->exists())->toBeFalse()
        ->and($currentCatchAllPeriod->budgetTransactions()->where('transaction_id', $otherSpaceTransaction->id)->exists())->toBeFalse();
});

it('clears the historical processing flag after the assignment job fails permanently', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $period = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $period->update(['processing_historical' => true]);

    (new AssignHistoricalTransactionsToBudget($budget, $period))
        ->failed(new RuntimeException('Assignment failed'));

    expect($period->fresh()->processing_historical)->toBeFalse();
});

it('updates only the direct successor allocation', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $july = addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $august = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);
    $september = addManagedPeriod($budget, '2026-09-01', '2026-09-30', 40000);

    app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $august->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    );

    expect($august->fresh()->allocated_amount)->toBe(45000)
        ->and($july->fresh()->allocated_amount)->toBe(40000)
        ->and($september->fresh()->allocated_amount)->toBe(40000);
});

it('seeds the following period with the pre-edit planning allocation', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $august = addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);

    app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $august->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    );

    expect($budget->periods()->whereDate('start_date', '2026-09-01')->value('allocated_amount'))->toBe(40000);
});

it('rejects planning updates for any period except the direct successor', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $july = addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    addManagedPeriod($budget, '2026-08-01', '2026-08-31', 40000);
    $september = addManagedPeriod($budget, '2026-09-01', '2026-09-30', 40000);

    foreach ([$july->id, $september->id] as $periodId) {
        expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
            $user,
            $user->personalSpace,
            $budget->id,
            $periodId,
            45000,
            CarbonImmutable::parse('2026-07-30'),
        ))->toThrow(ModelNotFoundException::class);
    }
});

it('rejects planning updates for a budget belonging to another user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherBudget = managedBudgetWithPeriods($otherUser, $otherUser->personalSpace->id);
    addManagedPeriod($otherBudget, '2026-07-01', '2026-07-31', 40000);
    $otherPeriod = addManagedPeriod($otherBudget, '2026-08-01', '2026-08-31', 40000);

    expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $otherBudget->id,
        $otherPeriod->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    ))->toThrow(ValidationException::class);
});

it('rejects planning updates for a period belonging to another budget', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    addManagedPeriod($budget, '2026-07-01', '2026-07-31', 40000);
    $otherBudget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $otherPeriod = addManagedPeriod($otherBudget, '2026-08-01', '2026-08-31', 40000);

    expect(fn () => app(BudgetManagementService::class)->updateNextPeriodAllocation(
        $user,
        $user->personalSpace,
        $budget->id,
        $otherPeriod->id,
        45000,
        CarbonImmutable::parse('2026-07-30'),
    ))->toThrow(ModelNotFoundException::class);
});

it('stamps the reconciled periods with a token the job can recognise', function () {
    Queue::fake();
    CarbonImmutable::setTestNow('2026-08-10');
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $period = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $replacement = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
    ]);

    app(BudgetManagementService::class)->update(
        $user,
        $user->personalSpace,
        $budget->id,
        ['category_ids' => [$replacement->id]],
        CarbonImmutable::parse('2026-08-10'),
    );

    $token = $period->fresh()->reconciliation_token;

    expect($token)->not->toBeNull();
    Queue::assertPushed(
        AssignHistoricalTransactionsToBudget::class,
        fn (AssignHistoricalTransactionsToBudget $job): bool => $job->period->id === $period->id
            && $job->reconciliationToken === $token,
    );
});

it('leaves the flag raised when a superseded run finishes after a newer edit', function () {
    // Two tracking edits in a row queue two jobs. The first to finish must not
    // announce the period as settled while the second is still rewriting it.
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $period = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $period->update([
        'processing_historical' => true,
        'reconciliation_token' => 'newer-run',
    ]);

    (new AssignHistoricalTransactionsToBudget($budget, $period, 'older-run'))
        ->handle(app(BudgetTransactionService::class));

    expect($period->fresh()->processing_historical)->toBeTrue()
        ->and($period->fresh()->reconciliation_token)->toBe('newer-run');
});

it('hands the period back when the run that owns it finishes', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $period = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $period->update([
        'processing_historical' => true,
        'reconciliation_token' => 'current-run',
    ]);

    (new AssignHistoricalTransactionsToBudget($budget, $period, 'current-run'))
        ->handle(app(BudgetTransactionService::class));

    expect($period->fresh()->processing_historical)->toBeFalse()
        ->and($period->fresh()->reconciliation_token)->toBeNull();
});

it('leaves the flag to the newer run when a superseded one fails permanently', function () {
    $user = User::factory()->create();
    $budget = managedBudgetWithPeriods($user, $user->personalSpace->id);
    $period = addManagedPeriod($budget, '2026-08-01', '2026-08-31');
    $period->update([
        'processing_historical' => true,
        'reconciliation_token' => 'newer-run',
    ]);

    (new AssignHistoricalTransactionsToBudget($budget, $period, 'older-run'))
        ->failed(new RuntimeException('Assignment failed'));

    expect($period->fresh()->processing_historical)->toBeTrue();
});
