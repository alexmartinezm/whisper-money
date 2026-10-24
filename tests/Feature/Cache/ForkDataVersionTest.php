<?php

use App\Ai\Agents\TransactionCategorizationAgent;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\RecurringSeriesUserState;
use App\Enums\RolloverType;
use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Import;
use App\Models\RecurringSeries;
use App\Models\RecurringSeriesTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\CategorizeTransactions;
use App\Services\Ai\CategoryCatalog;
use App\Services\BudgetManagementService;
use App\Services\BudgetTransactionService;
use App\Services\Cache\DataVersion;
use App\Services\Recurring\DetectRecurringSeries;
use App\Services\Transactions\ReplaceTransactionSplits;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/*
 * The fork's own data — split lines, recurring series and their links, one-off
 * budget periods, per-account net worth inclusion — has to move the user's
 * data-version token like upstream's writes do. Each test reads the token
 * before and after the write.
 */

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR']);
    $this->groceries = Category::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'type' => CategoryType::Expense,
    ]);
    $this->household = Category::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'type' => CategoryType::Expense,
    ]);
    $this->purchase = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'account_id' => $this->account->id,
        'category_id' => $this->groceries->id,
        'amount' => -10000,
        'currency_code' => 'EUR',
    ]);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

it('moves the token when a transaction is split, re-split and unsplit', function () {
    $splits = app(ReplaceTransactionSplits::class);

    $before = DataVersion::current($this->user->id);
    $splits->replace($this->purchase, [
        ['category_id' => $this->groceries->id, 'amount' => -6000],
        ['category_id' => $this->household->id, 'amount' => -4000],
    ]);
    $split = DataVersion::current($this->user->id);

    $splits->replace($this->purchase, [
        ['category_id' => $this->groceries->id, 'amount' => -2500],
        ['category_id' => $this->household->id, 'amount' => -7500],
    ]);
    $resplit = DataVersion::current($this->user->id);

    $splits->replace($this->purchase, [], $this->household->id);

    expect($split)->not->toBe($before)
        ->and($resplit)->not->toBe($split)
        ->and(DataVersion::current($this->user->id))->not->toBe($resplit)
        ->and($this->purchase->splits()->count())->toBe(0);
});

it('moves the token when a split line is written on its own', function () {
    app(ReplaceTransactionSplits::class)->replace($this->purchase, [
        ['category_id' => $this->groceries->id, 'amount' => -6000],
        ['category_id' => $this->household->id, 'amount' => -4000],
    ]);
    $line = $this->purchase->splits()->orderBy('position')->firstOrFail();

    $before = DataVersion::current($this->user->id);
    $line->update(['category_id' => $this->household->id]);
    $updated = DataVersion::current($this->user->id);

    $line->delete();

    expect($updated)->not->toBe($before)
        ->and(DataVersion::current($this->user->id))->not->toBe($updated);
});

it('moves the token when split lines change through the transaction update endpoint', function () {
    $before = DataVersion::current($this->user->id);

    $this->actingAs($this->user)->patchJson(route('transactions.update', $this->purchase), [
        'splits' => [
            ['category_id' => $this->groceries->id, 'amount' => -3000],
            ['category_id' => $this->household->id, 'amount' => -7000],
        ],
    ])->assertOk()->assertJsonPath('data.splits.1.amount', -7000);

    expect(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when recurring detection writes a series and links its charges', function () {
    $anchor = CarbonImmutable::today()->subDays(3);
    foreach (range(3, 0) as $monthsAgo) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => null,
            'creditor_name' => 'EXAMPLE STREAMING LTD',
            'transaction_date' => $anchor->subMonths($monthsAgo)->toDateString(),
            'amount' => -1299,
            'currency_code' => 'EUR',
        ]);
    }

    $before = DataVersion::current($this->user->id);
    app(DetectRecurringSeries::class)->forUser($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(RecurringSeriesTransaction::query()->count())->toBe(4)
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when the user confirms or deletes a recurring series', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'account_id' => $this->account->id,
    ]);

    $before = DataVersion::current($this->user->id);
    $this->actingAs($this->user)
        ->patch(route('recurring.update', $series), ['user_state' => RecurringSeriesUserState::Confirmed->value])
        ->assertRedirect();
    $confirmed = DataVersion::current($this->user->id);

    $this->actingAs($this->user)->delete(route('recurring.destroy', $series))->assertRedirect();

    expect($confirmed)->not->toBe($before)
        ->and(DataVersion::current($this->user->id))->not->toBe($confirmed);
});

it('moves the token when a charge is linked to or unlinked from a recurring series', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'account_id' => $this->account->id,
    ]);

    $before = DataVersion::current($this->user->id);
    $series->transactions()->attach($this->purchase->id);
    $linked = DataVersion::current($this->user->id);

    $series->transactions()->detach($this->purchase->id);

    expect($linked)->not->toBe($before)
        ->and(DataVersion::current($this->user->id))->not->toBe($linked);
});

it('moves the token when a one-off allocation is set on a budget period and reset', function () {
    CarbonImmutable::setTestNow('2026-09-10');
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'period_start_day' => 1,
        'rollover_type' => RolloverType::CarryOver,
    ]);
    $budget->categories()->attach($this->groceries->id);
    $budget->periods()->create([
        'start_date' => '2026-09-01',
        'end_date' => '2026-09-30',
        'allocated_amount' => 30000,
        'carried_over_amount' => 0,
    ]);
    $service = app(BudgetManagementService::class);
    $space = $this->account->space;

    $before = DataVersion::current($this->user->id);
    $result = $service->updatePeriodAllocation($this->user, $space, $budget->id, CarbonImmutable::parse('2026-10-15'), 90000, CarbonImmutable::now());
    $oneOff = DataVersion::current($this->user->id);

    $service->updatePeriodAllocation($this->user, $space, $budget->id, CarbonImmutable::parse('2026-10-15'), null, CarbonImmutable::now());

    expect($result['period']->allocated_amount)->toBe(90000)
        ->and($oneOff)->not->toBe($before)
        ->and(DataVersion::current($this->user->id))->not->toBe($oneOff);
});

it('moves the token when an account is taken out of net worth', function () {
    $before = DataVersion::current($this->user->id);

    $this->actingAs($this->user)
        ->patch(route('accounts.net-worth-inclusion', $this->account), ['include_in_net_worth' => false])
        ->assertRedirect();

    expect($this->account->fresh()->include_in_net_worth)->toBeFalse()
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when a bulk edit recategorizes transactions', function () {
    // Faked so the budget reassignment the edit queues cannot be what bumps.
    Queue::fake();

    $before = DataVersion::current($this->user->id);

    $this->actingAs($this->user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$this->purchase->id],
        'category_id' => $this->household->id,
    ])->assertOk()->assertJsonPath('updated_count', 1);

    expect($this->purchase->fresh()->category_id)->toBe($this->household->id)
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when a budget starts tracking other categories and labels', function () {
    // Faked so the historical reassignment the change queues cannot be what bumps.
    Queue::fake();
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'period_start_day' => 1,
    ]);
    $budget->categories()->attach($this->groceries->id);

    $before = DataVersion::current($this->user->id);
    app(BudgetManagementService::class)->update(
        $this->user,
        $this->account->space,
        $budget->id,
        ['category_ids' => [$this->household->id]],
        CarbonImmutable::now(),
    );

    expect($budget->categories()->pluck('categories.id')->all())->toBe([$this->household->id])
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when the model files an imported row, which is saved quietly', function () {
    // Faked so the batch budget reassignment of imported rows cannot be what bumps.
    Queue::fake();
    $import = Import::factory()->create(['user_id' => $this->user->id]);
    // Quietly, so no categorization or budget listener runs before the pass under test.
    $imported = Transaction::factory()->plaintext()->createQuietly([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'account_id' => $this->account->id,
        'import_id' => $import->id,
        'category_id' => null,
        'category_source' => null,
        'description' => 'EXAMPLE GROCER 0042',
        'amount' => -2350,
        'currency_code' => 'EUR',
    ]);
    $catalog = CategoryCatalog::forUser($this->user);
    $index = collect(range(0, 20))->first(fn (int $candidate): bool => $catalog->categoryIdForIndex($candidate) === $this->groceries->id);
    TransactionCategorizationAgent::fake([
        ['results' => [['ref' => $imported->id, 'category_index' => $index, 'confidence' => 0.97, 'merchant_unambiguous' => false]]],
    ]);

    $before = DataVersion::current($this->user->id);
    $outcomes = app(CategorizeTransactions::class)->forTransactions($this->user, collect([$imported]));

    expect($outcomes)->toHaveCount(1)
        ->and($imported->fresh()->category_id)->toBe($this->groceries->id)
        ->and($imported->fresh()->category_source)->toBe(CategorySource::Ai)
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when a historical assignment with nothing to assign releases its period', function () {
    $budget = Budget::factory()->monthly()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->account->space_id,
        'period_start_day' => 1,
    ]);
    $budget->categories()->attach($this->groceries->id);
    // Years before any transaction, so releasing processing_historical is the only write.
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2001-03-01',
        'end_date' => '2001-03-31',
        'processing_historical' => true,
        'reconciliation_token' => 'example-run',
    ]);

    $before = DataVersion::current($this->user->id);
    (new AssignHistoricalTransactionsToBudget($budget, $period, 'example-run'))
        ->handle(app(BudgetTransactionService::class));

    expect($period->fresh()->processing_historical)->toBeFalse()
        ->and($period->budgetTransactions()->count())->toBe(0)
        ->and(DataVersion::current($this->user->id))->not->toBe($before);
});

it('moves the token when the split reconciliation sweep reassigns a split transaction', function () {
    app(ReplaceTransactionSplits::class)->replace($this->purchase, [
        ['category_id' => $this->groceries->id, 'amount' => -6000],
        ['category_id' => $this->household->id, 'amount' => -4000],
    ]);

    $before = DataVersion::current($this->user->id);
    $this->artisan('transactions:reconcile-splits', ['--execute' => true, '--chunk' => 1])->assertSuccessful();

    expect(DataVersion::current($this->user->id))->not->toBe($before);
});
