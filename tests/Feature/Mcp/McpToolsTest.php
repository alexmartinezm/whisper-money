<?php

use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Models\Account;
use App\Models\Budget;
use App\Models\Category;
use App\Models\CreditCardDetail;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('blocks read tools when subscriptions are enabled and the user has no paid plan', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertHasErrors()
        ->assertSee('Pro');
});

it('allows read tools for a user on a paid plan', function () {
    // subscriptions disabled => everyone is treated as Pro (hasProPlan()).
    $user = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListSpaces::class)
        ->assertOk()
        ->assertSee('Personal');
});

it('searches transactions scoped to the user\'s space', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['query' => 'Blue Bottle'])
        ->assertOk()
        ->assertSee('Blue Bottle Coffee');
});

it('filters transactions by label id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);

    $labelled = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Labelled Lunch',
    ]);
    $labelled->labels()->attach($label->id);

    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Unlabelled Dinner',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$label->id]])
        ->assertOk()
        ->assertSee('Labelled Lunch')
        ->assertDontSee('Unlabelled Dinner');
});

it('rejects a label id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $foreignLabel = Label::factory()->create(['user_id' => $other->id]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, ['label_ids' => [$foreignLabel->id]])
        ->assertHasErrors();
});

it('never exposes another user\'s transactions', function () {
    $user = User::factory()->create();
    $userAccount = Account::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $userAccount->id,
        'description' => 'My Own Groceries',
    ]);

    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
        'description' => 'Secret Steakhouse',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertSee('My Own Groceries')
        ->assertDontSee('Secret Steakhouse');
});

it('rejects a space id the user cannot access', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, ['space' => $other->personalSpace->id])
        ->assertHasErrors();
});

it('lists the user\'s accounts for the space', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'name' => 'Everyday Checking']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('Everyday Checking');
});

it('presents the next credit card payment', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));

    $cards = Account::factory()->creditCard()->count(3)->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $cards->each(fn (Account $card) => CreditCardDetail::factory()->create([
        'account_id' => $card->id,
        'statement_closing_date' => '2026-03-05',
        'payment_due_date' => '2026-03-20',
    ]));
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $cards->first()->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'currency_code' => 'EUR',
        'amount' => -4321,
        'transaction_date' => '2026-03-01',
    ]);

    DB::enableQueryLog();

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('credit_card_statement')
        ->assertSee('"amount":4321')
        ->assertSee('"is_final":true')
        ->assertSee('2026-03-20')
        ->assertSee('"credit_card_usage":{"limit":null,"used":4321,"available":null,"period_from":"2026-02-06","period_to":"2026-04-05"}', false)
        ->assertDontSee('daily');

    // One ledger query per card on top of the fixed ones, never one per row.
    expect(collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from `transactions`'))->count())->toBe(3);
});

it('presents the credit limit and what is left of it', function () {
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00', 'UTC'));

    $card = Account::factory()->creditCard()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    CreditCardDetail::factory()->create([
        'account_id' => $card->id,
        'statement_closing_date' => null,
        'payment_due_date' => null,
        'credit_limit' => 100000,
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $card->id,
        'category_id' => Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense])->id,
        'currency_code' => 'EUR',
        'amount' => -2500,
        'transaction_date' => '2026-03-02',
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertSee('"credit_card_detail":{"statement_closing_date":null,"payment_due_date":null,"statement_frequency":"monthly","credit_limit":100000}', false)
        ->assertSee('"credit_card_statement":null', false)
        ->assertSee('"credit_card_usage":{"limit":100000,"used":2500,"available":97500,"period_from":"2026-03-01","period_to":"2026-03-31"}', false);
});

it('lists the user\'s categories for the space', function () {
    $user = User::factory()->create();
    Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListCategories::class, [])
        ->assertOk()
        ->assertSee('Groceries');
});

it('says which currency the rolled-up figures are in', function (string $tool) {
    // The amounts alone cannot tell euros from dollars, and without this the
    // agent answered a euro account's spending with a dollar sign.
    $user = User::factory()->create(['currency_code' => 'EUR']);

    WhisperMoneyServer::actingAs($user)
        ->tool($tool, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
        ->assertOk()
        ->assertSee('"currency":"EUR"');
})->with([
    'spending_by_category' => SpendingByCategory::class,
    'get_cashflow' => GetCashflow::class,
    'get_net_worth' => GetNetWorth::class,
    'list_budgets' => ListBudgets::class,
]);

it('formats the spending amounts in the user\'s currency', function () {
    // Given only minor units and a currency, ChatGPT once scaled €2,198.88
    // down to €219.89; the formatted amount spares it the maths.
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries']);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $groceries->id,
        'amount' => -219888,
        'currency_code' => 'EUR',
        'transaction_date' => now()->toDateString(),
    ]);

    WhisperMoneyServer::actingAs($user)
        ->tool(SpendingByCategory::class, ['from' => now()->startOfMonth()->toDateString(), 'to' => now()->toDateString()])
        ->assertOk()
        ->assertSee('"amount":219888')
        ->assertSee('"amount_formatted":"€2,198.88"');
});

it('tells the agent on the accounts tool that nothing moves money', function () {
    // ChatGPT reads tool descriptions more reliably than the server
    // instructions: asked to "transfer 500 euros to my mum's account", it
    // offered to do it once it knew the destination account.
    expect((new ListAccounts)->description())->toContain('no tool moves money');
});

it('leaves archived budgets out of the list', function () {
    $user = User::factory()->create();
    Budget::factory()->create(['user_id' => $user->id, 'name' => 'Running Budget']);
    Budget::factory()->archived()->create(['user_id' => $user->id, 'name' => 'Archived Budget']);

    WhisperMoneyServer::actingAs($user)
        ->tool(ListBudgets::class)
        ->assertOk()
        ->assertSee('Running Budget')
        ->assertDontSee('Archived Budget');
});
