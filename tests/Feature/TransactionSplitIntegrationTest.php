<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\UncategorizedTransactionMatcher;
use App\Services\AutomationRuleService;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionSyncService;
use App\Services\BudgetTransactionService;
use App\Services\Transactions\EffectiveTransactionPostings;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Account, 2: Transaction, 3: Category, 4: Category} */
function splitIntegrationFixture(array $transactionAttributes = []): array
{
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
        ...$transactionAttributes,
    ]);
    $food = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $home = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);

    return [$user, $account, $transaction, $food, $home];
}

function makeIntegrationSplit(Transaction $transaction, Category $first, Category $second): void
{
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);
}

it('uses either the parent or ordered split postings without double counting', function () {
    [, , $transaction, $food, $home] = splitIntegrationFixture();
    $service = app(EffectiveTransactionPostings::class);

    $normal = $service->forTransaction($transaction->load(['category', 'splits.category']));
    expect($normal)->toHaveCount(1)
        ->and($normal->sum('amount'))->toBe(-10000)
        ->and($normal->first()->fromSplit)->toBeFalse();

    makeIntegrationSplit($transaction, $food, $home);
    $postings = $service->forTransaction($transaction->refresh()->load(['category', 'splits.category']));

    expect($postings)->toHaveCount(2)
        ->and($postings->pluck('categoryId')->all())->toBe([$food->id, $home->id])
        ->and($postings->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($postings->sum('amount'))->toBe(-10000)
        ->and($postings->every->fromSplit)->toBeTrue();
});

it('creates, serializes, replaces, and removes splits through transaction CRUD', function () {
    [$user, $account, , $food, $home] = splitIntegrationFixture();

    $created = $this->actingAs($user)->postJson(route('transactions.store'), [
        'account_id' => $account->id,
        'description' => 'Split purchase',
        'transaction_date' => '2026-07-18',
        'amount' => -10000,
        'currency_code' => 'EUR',
        'source' => 'manually_created',
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ])->assertCreated()
        ->assertJsonPath('data.category_id', null)
        ->assertJsonPath('data.is_split', true)
        ->assertJsonPath('data.split_count', 2)
        ->assertJsonPath('data.splits.0.category_id', $food->id);

    $transaction = Transaction::query()->findOrFail($created->json('data.id'));

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'splits' => [
            ['category_id' => $food->id, 'amount' => -2500],
            ['category_id' => $home->id, 'amount' => -7500],
        ],
    ])->assertOk()->assertJsonPath('data.splits.1.amount', -7500);

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $food->id,
        'splits' => [],
    ])->assertOk()
        ->assertJsonPath('data.category_id', $food->id)
        ->assertJsonPath('data.is_split', false);
});

it('rejects implicit category or amount mutations on split parents', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'category_id' => $food->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('splits');

    $this->actingAs($user)->patchJson(route('transactions.update', $transaction), [
        'amount' => -9000,
    ])->assertUnprocessable()->assertJsonValidationErrors('splits');

    expect($transaction->refresh()->amount)->toBe(-10000)
        ->and($transaction->category_id)->toBeNull()
        ->and((int) $transaction->splits()->sum('amount'))->toBe(-10000);
});

it('filters split parents once and excludes them from uncategorized and bulk category updates', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->get(route('transactions.index', ['category_ids' => $food->id]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.id', $transaction->id));

    $this->actingAs($user)->get(route('transactions.index', ['category_ids' => 'uncategorized']))
        ->assertInertia(fn ($page) => $page->has('transactions.data', 0));

    $this->actingAs($user)->patchJson('/transactions/bulk', [
        'transaction_ids' => [$transaction->id],
        'category_id' => $food->id,
    ])->assertOk()
        ->assertJsonPath('updated_count', 0)
        ->assertJsonPath('skipped_split_count', 1);

    expect($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});

it('exposes split details in incremental sync and excludes split parents from AI candidates', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->getJson('/api/sync/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.id', $transaction->id)
        ->assertJsonPath('data.0.is_split', true)
        ->assertJsonPath('data.0.split_count', 2)
        ->assertJsonPath('data.0.splits.0.category.name', $food->name);

    expect(app(UncategorizedTransactionMatcher::class)->total($user))->toBe(0);
});

it('protects split classification from automation category actions', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);
    $replacement = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $replacement->id,
    ]);

    $changed = app(AutomationRuleService::class)
        ->applyRuleActionsToTransactions(new Collection([$transaction]), $rule);

    expect($changed)->toBe(0)
        ->and($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});

it('does not alter an existing split parent when bank sync sees its duplicate', function () {
    $user = User::factory()->onboarded()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
        'external_account_id' => 'split-account',
    ]);
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'external_transaction_id' => 'split-bank-transaction',
        'amount' => -10000,
    ]);
    $food = Category::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $user->id, 'space_id' => $transaction->space_id, 'type' => CategoryType::Expense]);
    makeIntegrationSplit($transaction, $food, $home);

    $provider = Mockery::mock(BankingProviderInterface::class);
    $provider->shouldReceive('getTransactions')->once()->andReturn([
        'transactions' => [[
            'transaction_id' => 'split-bank-transaction',
            'transaction_amount' => ['amount' => '999.00', 'currency' => 'EUR'],
            'credit_debit_indicator' => 'DBIT',
            'booking_date' => '2026-07-18',
            'remittance_information' => ['Duplicate changed upstream'],
        ]],
        'continuation_key' => null,
    ]);

    $created = (new TransactionSyncService(
        $provider,
        new TransactionDescriptionFormatter,
    ))->sync($account, '2026-07-01', '2026-07-31');

    expect($created)->toBe(0)
        ->and($transaction->refresh()->amount)->toBe(-10000)
        ->and($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000]);
});

it('allocates only matching split portions to category budgets', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => now(),
    ]);
    makeIntegrationSplit($transaction, $food, $home);

    $foodBudget = Budget::factory()->forCategories($food)->create(['user_id' => $user->id]);
    $homeBudget = Budget::factory()->forCategories($home)->create(['user_id' => $user->id]);
    $foodPeriod = BudgetPeriod::factory()->create([
        'budget_id' => $foodBudget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);
    $homePeriod = BudgetPeriod::factory()->create([
        'budget_id' => $homeBudget->id,
        'start_date' => now()->subDay(),
        'end_date' => now()->addDay(),
    ]);

    app(BudgetTransactionService::class)->assignTransaction($transaction);

    expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(6000)
        ->and((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(4000);
});

it('uses only the matching split portion in category-filtered analysis', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => '2026-07-18',
        'currency_code' => 'USD',
    ]);
    $user->update(['currency_code' => 'USD']);
    makeIntegrationSplit($transaction, $food, $home);

    $this->actingAs($user)->getJson('/api/transactions/analysis?'.http_build_query([
        'category_ids' => $food->id,
    ]))->assertOk()
        ->assertJsonPath('summary.expense', 6000)
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('by_category.0.amount', 6000)
        ->assertJsonPath('largest_expenses.0.amount', 10000)
        ->assertJsonPath('largest_expenses.0.category.name', 'Split');
});

it('keeps descendant split postings when analysis filters by a parent category', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture([
        'transaction_date' => '2026-07-18',
        'currency_code' => 'USD',
    ]);
    $user->update(['currency_code' => 'USD']);
    $groceries = Category::factory()->childOf($food)->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
    ]);
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $groceries->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    $this->actingAs($user)->getJson('/api/transactions/analysis?'.http_build_query([
        'category_ids' => $food->id,
    ]))->assertOk()
        ->assertJsonPath('summary.expense', 6000)
        ->assertJsonPath('summary.count', 1)
        ->assertJsonPath('by_category.0.category_id', $food->id)
        ->assertJsonPath('by_category.0.amount', 6000);
});

it('exposes split reads through MCP and blocks MCP categorization', function () {
    [$user, , $transaction, $food, $home] = splitIntegrationFixture();
    makeIntegrationSplit($transaction, $food, $home);
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['category_id' => $food->id])
        ->assertOk()
        ->assertSee($transaction->id)
        ->assertSee('is_split');

    WhisperMoneyServer::actingAs($user)
        ->tool(CategorizeTransaction::class, [
            'transaction_id' => $transaction->id,
            'category_id' => $food->id,
        ])
        ->assertHasErrors(['split']);

    expect($transaction->refresh()->category_id)->toBeNull()
        ->and($transaction->splits()->count())->toBe(2);
});
