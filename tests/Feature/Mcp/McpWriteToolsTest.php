<?php

use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Jobs\GenerateHistoricalLoanBalancesJob;
use App\Jobs\GenerateHistoricalRealEstateBalancesJob;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAccount;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\UpdateAccount;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a write tool as $user, giving them a real personal access token with the
 * given abilities so the tool's tokenCan('mcp:write') gate is exercised exactly
 * as it is over HTTP.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callWriteTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('creates a manual transaction and defaults the currency to the account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Blue Bottle Coffee',
        'amount' => -450,
        'transaction_date' => '2026-01-15',
    ])->assertOk()->assertSee('Blue Bottle Coffee');

    $transaction = Transaction::query()->where('account_id', $account->id)->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->description)->toBe('Blue Bottle Coffee');
    expect($transaction->currency_code)->toBe('EUR');
    expect($transaction->source->value)->toBe('manually_created');
});

it('creates a transaction on a connected account without touching its balances', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);
    $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);

    callWriteTool($user, CreateTransaction::class, [
        'account_id' => $account->id,
        'description' => 'Cash withdrawal the bank missed',
        'amount' => -100,
        'transaction_date' => '2026-01-15',
        'update_balance' => true,
    ])->assertOk()->assertSee('Cash withdrawal the bank missed');

    expect(Transaction::query()->where('account_id', $account->id)->count())->toBe(1);
    expect($account->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
});

it('edits a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Old description',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Fresh description',
    ])->assertOk()->assertSee('Fresh description');

    expect($transaction->fresh()->description)->toBe('Fresh description');
});

it('moves a transaction onto a connected account, unwinding only the manual side', function () {
    $user = User::factory()->create();
    $manualAccount = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $connectedAccount = Account::factory()->connected()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

    $manualAccount->balances()->create(['balance_date' => '2026-01-15', 'balance' => 9_000]);
    $connectedAccount->balances()->create(['balance_date' => '2026-01-15', 'balance' => 50_000]);

    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $manualAccount->id,
        'transaction_date' => '2026-01-15',
        'amount' => -1_000,
        'currency_code' => 'EUR',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'account_id' => $connectedAccount->id,
        'update_balance' => true,
    ])->assertOk();

    expect($transaction->fresh()->account_id)->toBe($connectedAccount->id);
    // The manual account gets the money back; the bank's balance is left alone.
    expect($manualAccount->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
    expect($connectedAccount->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(50_000);
});

it('refuses to edit a core field of an imported transaction, naming the field', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Untouched',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Hacked',
        'amount' => -1,
    ])->assertHasErrors(['manually-created'])->assertSee('description, amount');

    expect($transaction->fresh()->description)->toBe('Untouched');
});

it('writes notes on a bank-synced transaction and returns them', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->enableBanking()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'notes' => null,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'notes' => 'Migrated from the old spreadsheet',
    ])->assertOk()->assertSee('Migrated from the old spreadsheet');

    // notes_iv belongs to the client-side encryption being migrated away, so a
    // plain-text note must never claim to be encrypted.
    expect($transaction->fresh()->notes)->toBe('Migrated from the old spreadsheet')
        ->and($transaction->fresh()->notes_iv)->toBeNull();
});

it('writes notes on an imported transaction and clears them again', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'notes' => null,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'notes' => 'Reimbursed by work',
    ])->assertOk();

    expect($transaction->fresh()->notes)->toBe('Reimbursed by work');

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'notes' => null,
    ])->assertOk();

    expect($transaction->fresh()->notes)->toBeNull();
});

it('categorizes an imported transaction through the same tool', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'category_id' => $category->id,
    ])->assertOk();

    expect($transaction->fresh()->category_id)->toBe($category->id);
});

it('retires the legacy iv of every field it overwrites in the clear', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'B2l0ZXh0cGQ9',
        'description_iv' => 'MTIzNDU2Nzg5MGFi',
        'notes' => 'k5rXcipherPQ==',
        'notes_iv' => 'YWJjZGVmZ2hpamts',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'description' => 'Rewritten in the clear',
        'notes' => 'So are the notes',
    ])->assertOk();

    // A stale iv would have the browser decrypt plain text and render the
    // field as broken, so it goes along with the ciphertext it described.
    $transaction = $transaction->fresh();

    expect($transaction->description)->toBe('Rewritten in the clear')
        ->and($transaction->description_iv)->toBeNull()
        ->and($transaction->notes)->toBe('So are the notes')
        ->and($transaction->notes_iv)->toBeNull();
});

it('leaves the iv of a field it did not touch alone', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'B2l0ZXh0cGQ9',
        'description_iv' => 'MTIzNDU2Nzg5MGFi',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'notes' => 'Only the notes change',
    ])->assertOk();

    // The description is still ciphertext, so the browser still needs its iv.
    expect($transaction->fresh()->description_iv)->toBe('MTIzNDU2Nzg5MGFi');
});

it('leaves the balance alone when only the notes change', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 9_000]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'transaction_date' => '2026-01-15',
        'amount' => -1_000,
        'currency_code' => 'EUR',
    ]);

    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => $transaction->id,
        'notes' => 'Just an annotation',
        'update_balance' => true,
    ])->assertOk()->assertSee('"balance_updated":false');

    expect($account->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(9_000);
});

it('deletes a manual transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertOk();

    expect(Transaction::query()->find($transaction->id))->toBeNull();
});

it('refuses to delete an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $transaction->id,
    ])->assertHasErrors(['manually-created']);

    expect(Transaction::query()->find($transaction->id))->not->toBeNull();
});

it('categorizes an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => null,
    ]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Dining']);

    callWriteTool($user, CategorizeTransaction::class, [
        'transaction_id' => $transaction->id,
        'category_id' => $category->id,
    ])->assertOk();

    $transaction->refresh();

    expect($transaction->category_id)->toBe($category->id);
    expect($transaction->category_source)->toBe(CategorySource::Manual);
});

it('adds a label to an imported transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
    ]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Reimbursable']);

    callWriteTool($user, LabelTransaction::class, [
        'transaction_id' => $transaction->id,
        'add_label_ids' => [$label->id],
    ])->assertOk()->assertSee('Reimbursable');

    expect($transaction->fresh()->labels->pluck('id')->all())->toContain($label->id);
});

it('records a balance on a manual account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
        'balance_date' => '2026-01-31',
    ])->assertOk();

    expect($account->balances()->whereDate('balance_date', '2026-01-31')->value('balance'))->toBe(250000);
});

it('refuses to record a balance on a connected account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 250000,
    ])->assertHasErrors(['connected']);

    expect($account->balances()->count())->toBe(0);
});

it('creates, updates and deletes a category', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateCategory::class, [
        'name' => 'Travel',
        'icon' => 'Plane',
        'color' => 'blue',
        'type' => 'expense',
    ])->assertOk()->assertSee('Travel');

    $category = $user->categories()->where('name', 'Travel')->firstOrFail();

    callWriteTool($user, UpdateCategory::class, [
        'category_id' => $category->id,
        'name' => 'Holidays',
    ])->assertOk()->assertSee('Holidays');

    expect($category->fresh()->name)->toBe('Holidays');

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => Account::factory()->create(['user_id' => $user->id])->id,
        'category_id' => $category->id,
    ]);

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $category->id,
    ])->assertOk();

    expect(Category::query()->find($category->id))->toBeNull()
        ->and($transaction->fresh()->category_id)->toBeNull();
});

it('creates, updates and deletes a label', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Business',
        'color' => 'green',
    ])->assertOk()->assertSee('Business');

    $label = $user->labels()->where('name', 'Business')->firstOrFail();

    callWriteTool($user, UpdateLabel::class, [
        'label_id' => $label->id,
        'name' => 'Work',
    ])->assertOk()->assertSee('Work');

    expect($label->fresh()->name)->toBe('Work');

    callWriteTool($user, DeleteLabel::class, [
        'label_id' => $label->id,
    ])->assertOk();

    expect(Label::query()->find($label->id))->toBeNull();
});

it('creates, updates and deletes an automation rule', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Grocery rule',
        'priority' => 0,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
    ])->assertOk()->assertSee('Grocery rule');

    $rule = $user->automationRules()->where('title', 'Grocery rule')->firstOrFail();

    expect($rule->action_category_id)->toBe($category->id);

    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'title' => 'Supermarket rule',
    ])->assertOk()->assertSee('Supermarket rule');

    expect($rule->fresh()->title)->toBe('Supermarket rule');

    callWriteTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => $rule->id,
    ])->assertOk();

    expect(AutomationRule::query()->find($rule->id))->toBeNull();
});

it('moves a category back to the root when parent_id is null', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Home',
        'type' => 'expense',
        'cashflow_direction' => 'outflow',
    ]);
    $child = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Rent',
        'parent_id' => $parent->id,
        'type' => 'expense',
        'cashflow_direction' => 'outflow',
    ]);
    $grandchild = Category::factory()->create([
        'user_id' => $user->id,
        'name' => 'Garage',
        'parent_id' => $child->id,
        'type' => 'expense',
        'cashflow_direction' => 'outflow',
    ]);

    callWriteTool($user, UpdateCategory::class, [
        'category_id' => $child->id,
        'parent_id' => null,
        'type' => 'savings',
    ])->assertOk();

    // A root re-derives its own type, and the subtree follows it down.
    expect($child->fresh()->parent_id)->toBeNull()
        ->and($child->fresh()->type->value)->toBe('savings')
        ->and($child->fresh()->cashflow_direction->value)->toBe('outflow')
        ->and($grandchild->fresh()->type->value)->toBe('savings');
});

it('clears an automation rule note when action_note is null', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $category->id,
        'action_note' => 'Reimbursable',
    ]);

    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'action_note' => null,
    ])->assertOk();

    expect($rule->fresh()->action_note)->toBeNull();
});

it('clears an automation rule category only while a label action survives', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Reviewed']);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $category->id,
    ]);

    // With no labels attached, dropping the category would leave the rule with
    // nothing to do, so it is refused and the rule is left alone.
    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'action_category_id' => null,
    ])->assertHasErrors(['at least one action']);

    expect($rule->fresh()->action_category_id)->toBe($category->id);

    $rule->labels()->sync([$label->id]);

    callWriteTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'action_category_id' => null,
    ])->assertOk();

    expect($rule->fresh()->action_category_id)->toBeNull()
        ->and($rule->fresh()->labels)->toHaveCount(1);
});

it('refuses a cascading category delete that was not confirmed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $parent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Car']);
    $child = Category::factory()->create(['user_id' => $user->id, 'name' => 'Fuel', 'parent_id' => $parent->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $child->id,
    ]);

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $parent->id,
        'strategy' => 'cascade',
    ])->assertHasErrors(['confirm_cascade']);

    expect(Category::query()->find($parent->id))->not->toBeNull()
        ->and(Category::query()->find($child->id))->not->toBeNull()
        ->and($transaction->fresh()->category_id)->toBe($child->id);
});

it('reports what a confirmed cascading category delete cost', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $parent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Car']);
    $child = Category::factory()->create(['user_id' => $user->id, 'name' => 'Fuel', 'parent_id' => $parent->id]);
    $transaction = Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $child->id,
    ]);

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $parent->id,
        'strategy' => 'cascade',
        'confirm_cascade' => true,
    ])->assertOk()
        ->assertSee('"categories_deleted":2', false)
        ->assertSee('"transactions_uncategorized":1', false);

    expect(Category::query()->find($parent->id))->toBeNull()
        ->and(Category::query()->find($child->id))->toBeNull()
        ->and($transaction->fresh()->category_id)->toBeNull();
});

it('reports a reparenting category delete as a single category and no transactions', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Car']);
    $child = Category::factory()->create(['user_id' => $user->id, 'name' => 'Fuel', 'parent_id' => $parent->id]);

    callWriteTool($user, DeleteCategory::class, [
        'category_id' => $parent->id,
    ])->assertOk()
        ->assertSee('"categories_deleted":1', false)
        ->assertSee('"transactions_uncategorized":0', false);

    expect($child->fresh()->parent_id)->toBeNull();
});

it('accepts an exception rule built from and, or and a negation', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Shopping']);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Amazon except Prime',
        'priority' => 0,
        'rules_json' => ['and' => [
            ['or' => [
                ['in' => ['amazon', ['var' => 'description']]],
                ['in' => ['amazon', ['var' => 'creditor_name']]],
            ]],
            ['!' => ['in' => ['amazon prime', ['var' => 'description']]]],
            ['<' => [['var' => 'amount'], 0]],
        ]],
        'action_category_id' => $category->id,
    ])->assertOk()->assertSee('Amazon except Prime');

    expect($user->automationRules()->where('title', 'Amazon except Prime')->exists())->toBeTrue();
});

// A rule that never matches anything saves happily and then fails silently, so
// the write tools name what they accept instead of letting the agent guess.
it('rejects a rule whose variable or operator the engine cannot evaluate', function (array $rulesJson, string $expected) {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Broken rule',
        'priority' => 0,
        'rules_json' => $rulesJson,
        'action_category_id' => $category->id,
    ])->assertHasErrors([$expected]);

    expect($user->automationRules()->count())->toBe(0);
})->with([
    'unknown variable' => [['in' => ['netflix', ['var' => 'merchant']]], 'merchant'],
    'unknown operator' => [['contains' => [['var' => 'description'], 'netflix']], 'contains'],
    'nested unknown variable' => [['and' => [['!' => ['in' => ['netflix', ['var' => 'payee']]]]]], 'payee'],
]);

it('rejects a bare list of conditions that is missing its and/or wrapper', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'Listed rule',
        'priority' => 0,
        'rules_json' => [['in' => ['netflix', ['var' => 'description']]]],
        'action_category_id' => $category->id,
    ])->assertHasErrors(['non-empty JsonLogic object']);

    expect($user->automationRules()->count())->toBe(0);
});

it('requires an automation rule to have at least one action', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAutomationRule::class, [
        'title' => 'No action',
        'priority' => 0,
        'rules_json' => ['==' => [1, 1]],
    ])->assertHasErrors(['action']);

    expect($user->automationRules()->count())->toBe(0);
});

it('rejects a write tool called with a read-only token', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Should not exist',
        'color' => 'blue',
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    expect($user->labels()->count())->toBe(0);
});

it('still enforces the Pro-plan gate on write tools', function () {
    config(['subscriptions.enabled' => true]);
    $user = User::factory()->create();

    callWriteTool($user, CreateLabel::class, [
        'name' => 'Gated',
        'color' => 'blue',
    ])->assertHasErrors(['Pro']);

    expect($user->labels()->count())->toBe(0);
});

it('never lets a write tool touch another user\'s data', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id]);
    $otherTransaction = Transaction::factory()->create([
        'user_id' => $other->id,
        'account_id' => $otherAccount->id,
    ]);

    callWriteTool($user, DeleteTransaction::class, [
        'transaction_id' => $otherTransaction->id,
    ])->assertHasErrors();

    expect(Transaction::query()->find($otherTransaction->id))->not->toBeNull();
});

it('tells the agent which id was missing and where to find valid ones', function () {
    $user = User::factory()->create();

    // Records that have a listing tool point the agent at it.
    callWriteTool($user, UpdateTransaction::class, [
        'transaction_id' => 'no-such-transaction',
        'description' => 'Nope',
    ])->assertHasErrors([
        'No transaction with id no-such-transaction in space '.$user->personalSpace->id.'. Call search_transactions to find ids.',
    ]);

    // Those without one end at the sentence, with no dangling hint.
    callWriteTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => 'no-such-rule',
    ])->assertHasErrors([
        'No automation rule with id no-such-rule in space '.$user->personalSpace->id.'.',
    ]);
});

it('refuses to edit an archived budget', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->archived()->create([
        'user_id' => $user->id,
        'name' => 'Food Budget',
    ]);

    callWriteTool($user, UpdateBudget::class, [
        'budget_id' => $budget->id,
        'name' => 'Groceries Budget',
    ])->assertHasErrors();

    expect($budget->fresh()->name)->toBe('Food Budget');
});

it('still deletes an archived budget', function () {
    $user = User::factory()->create();
    $budget = Budget::factory()->archived()->create(['user_id' => $user->id]);

    callWriteTool($user, DeleteBudget::class, ['budget_id' => $budget->id])->assertOk();

    expect($user->budgets()->count())->toBe(0);
});

it('lets an archived catch-all budget be replaced', function () {
    $user = User::factory()->create();
    Budget::factory()->archived()->catchAll()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateBudget::class, [
        'name' => 'Everything else',
        'period_type' => 'monthly',
        // This fork's create tool requires a start day for a monthly cadence.
        'period_start_day' => 1,
        'rollover_type' => 'reset',
        'allocated_amount' => 50_000,
        'is_catch_all' => true,
    ])->assertOk();

    expect($user->budgets()->notArchived()->where('is_catch_all', true)->count())->toBe(1);
});

it('creates a manual account with a balance for today', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Rainy day savings',
        'type' => 'savings',
        'currency_code' => 'EUR',
        'balance' => 250_000,
    ])->assertOk()->assertSee('Rainy day savings');

    $account = $user->accounts()->sole();

    expect($account->type)->toBe(AccountType::Savings)
        ->and($account->currency_code)->toBe('EUR')
        ->and($account->isConnected())->toBeFalse()
        ->and($account->space_id)->toBe($user->personalSpace->id)
        ->and($account->balances()->where('balance_date', now()->toDateString())->value('balance'))->toBe(250_000);

    // The first account is also what the user's own currency is read from.
    expect($user->fresh()->currency_code)->toBe('EUR');
});

it('creates a loan with its details and backfills the balance history', function () {
    Queue::fake();

    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Mortgage',
        'type' => 'loan',
        'currency_code' => 'EUR',
        'balance' => 15_000_000,
        'annual_interest_rate' => 2.5,
        'loan_term_months' => 360,
        'original_amount' => 20_000_000,
        'loan_start_date' => now()->subYears(3)->toDateString(),
    ])->assertOk()->assertSee('Mortgage');

    $loan = $user->accounts()->where('type', 'loan')->sole();

    expect($loan->loanDetail)->not->toBeNull()
        ->and((int) $loan->loanDetail->loan_term_months)->toBe(360)
        ->and((int) $loan->loanDetail->original_amount)->toBe(20_000_000);

    // The last twelve months are generated inline so the chart is populated on
    // the next render, and the three years behind them go on the queue.
    expect($loan->balances()->where('balance_date', '>=', now()->subMonths(12)->startOfMonth()->toDateString())->count())
        ->toBeGreaterThan(1);
    Queue::assertPushed(GenerateHistoricalLoanBalancesJob::class);
});

it('creates a property with its details and backfills the value history', function () {
    Queue::fake();

    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);

    callWriteTool($user, CreateAccount::class, [
        'name' => 'The flat',
        'type' => 'real_estate',
        'currency_code' => 'EUR',
        'balance' => 30_000_000,
        'property_type' => 'residential',
        'purchase_price' => 24_000_000,
        'purchase_date' => now()->subYears(4)->toDateString(),
        'area_value' => 85.5,
        'area_unit' => 'sqm',
    ])->assertOk()->assertSee('The flat');

    $property = $user->accounts()->where('type', 'real_estate')->sole();

    expect($property->realEstateDetail)->not->toBeNull()
        ->and($property->realEstateDetail->property_type->value)->toBe('residential')
        ->and((int) $property->realEstateDetail->purchase_price)->toBe(24_000_000);

    expect($property->balances()->count())->toBeGreaterThan(1);
    Queue::assertPushed(GenerateHistoricalRealEstateBalancesJob::class);
});

it('links a new loan to the property it is the mortgage of', function () {
    $user = User::factory()->create();
    $property = Account::factory()->create(['user_id' => $user->id, 'type' => 'real_estate']);
    $property->realEstateDetail()->create(['property_type' => 'residential']);

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Mortgage on the flat',
        'type' => 'loan',
        'currency_code' => 'EUR',
        'annual_interest_rate' => 3.1,
        'loan_term_months' => 240,
        'original_amount' => 18_000_000,
        'linked_real_estate_account_id' => $property->id,
    ])->assertOk();

    $loan = $user->accounts()->where('type', 'loan')->sole();

    expect($property->realEstateDetail->fresh()->linked_loan_account_id)->toBe($loan->id);
});

it('refuses to link a property that is already answering to another loan', function () {
    $user = User::factory()->create();
    $firstLoan = Account::factory()->create(['user_id' => $user->id, 'type' => 'loan']);
    $property = Account::factory()->create(['user_id' => $user->id, 'type' => 'real_estate']);
    $property->realEstateDetail()->create([
        'property_type' => 'residential',
        'linked_loan_account_id' => $firstLoan->id,
    ]);

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Second mortgage',
        'type' => 'loan',
        'currency_code' => 'EUR',
        'annual_interest_rate' => 3.1,
        'loan_term_months' => 240,
        'original_amount' => 18_000_000,
        'linked_real_estate_account_id' => $property->id,
    ])->assertHasErrors();

    expect($user->accounts()->where('name', 'Second mortgage')->exists())->toBeFalse();
});

it('never creates a bank-connected account, pointing at the app instead', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Looks like a bank account',
        'type' => 'checking',
        'currency_code' => 'EUR',
        'banking_connection_id' => 'whatever-the-agent-made-up',
    ])->assertHasErrors(['Whisper Money app']);

    expect($user->accounts()->count())->toBe(0);
});

it('restricts the very first account to a primary currency', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Cold wallet',
        'type' => 'investment',
        'currency_code' => 'BTC',
        'balance' => 100_000_000,
    ])->assertHasErrors();

    expect($user->accounts()->count())->toBe(0);
});

it('rejects create_account for a read-only token', function () {
    $user = User::factory()->create();

    callWriteTool($user, CreateAccount::class, [
        'name' => 'Should not exist',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    expect($user->accounts()->count())->toBe(0);
});

it('changes only the fields update_account is passed', function () {
    $user = User::factory()->create();
    $bank = Bank::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'Old name',
        'type' => 'checking',
        'currency_code' => 'EUR',
        'bank_id' => $bank->id,
        'ownership_percentage' => 100,
    ]);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $account->id,
        'name' => 'Joint current account',
        'ownership_percentage' => 50,
    ])->assertOk()->assertSee('Joint current account');

    $account->refresh();

    expect($account->name)->toBe('Joint current account')
        ->and($account->ownership_percentage)->toBe(50)
        // Untouched by the call.
        ->and($account->type)->toBe(AccountType::Checking)
        ->and($account->currency_code)->toBe('EUR')
        ->and($account->bank_id)->toBe($bank->id);
});

it('renames a bank-connected account, which the sync never rewrites', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'name' => 'ES91 **** 1234',
        'type' => 'checking',
    ]);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $account->id,
        'name' => 'Everyday account',
        'ownership_percentage' => 50,
        'ownership_applies_to_balance' => true,
    ])->assertOk()->assertSee('Everyday account');

    $account->refresh();

    expect($account->name)->toBe('Everyday account')
        ->and($account->ownership_percentage)->toBe(50)
        ->and($account->ownership_applies_to_balance)->toBeTrue()
        ->and($account->isConnected())->toBeTrue();
});

it('refuses to change what the bank sync owns on a connected account', function (string $field, mixed $value, string $reason) {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create([
        'user_id' => $user->id,
        'name' => 'Untouched',
        'type' => 'checking',
        'currency_code' => 'EUR',
    ]);

    $bankBefore = $account->bank_id;

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $account->id,
        $field => is_callable($value) ? $value() : $value,
    ])->assertHasErrors([$reason]);

    $account->refresh();

    expect($account->currency_code)->toBe('EUR')
        ->and($account->type)->toBe(AccountType::Checking)
        ->and($account->bank_id)->toBe($bankBefore);
})->with([
    'currency comes from the bank' => ['currency_code', 'USD', 'reinterpret the whole history'],
    'bank comes from the connection' => ['bank_id', fn (): string => Bank::factory()->create()->id, 'its bank comes from the connection'],
    'a type with no ledger breaks the sync' => ['type', 'investment', 'nowhere to write'],
]);

it('lets a connected account move between the types the sync can write into', function () {
    $user = User::factory()->create();
    $account = Account::factory()->connected()->create(['user_id' => $user->id, 'type' => 'checking']);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $account->id,
        'type' => 'savings',
    ])->assertOk();

    expect($account->fresh()->type)->toBe(AccountType::Savings);
});

it('refuses to edit an account owned by another member of the space', function () {
    $owner = User::factory()->create();
    $housemate = User::factory()->create();

    $shared = Space::factory()->create(['owner_id' => $owner->id, 'name' => 'Household']);
    $shared->members()->attach($housemate->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $account = Account::factory()->create([
        'user_id' => $owner->id,
        'space_id' => $shared->id,
        'name' => 'Their account',
    ]);

    callWriteTool($housemate, UpdateAccount::class, [
        'account_id' => $account->id,
        'space' => $shared->id,
        'name' => 'Mine now',
    ])->assertHasErrors(['another member of the space']);

    expect($account->fresh()->name)->toBe('Their account');
});

it('edits the loan details of an account that already has them', function () {
    $user = User::factory()->create();
    $loan = Account::factory()->create(['user_id' => $user->id, 'type' => 'loan']);
    $loan->loanDetail()->create([
        'annual_interest_rate' => 3.0,
        'loan_term_months' => 240,
        'original_amount' => 18_000_000,
        'start_date' => now()->subYear()->toDateString(),
    ]);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $loan->id,
        'annual_interest_rate' => 1.75,
    ])->assertOk();

    expect((float) $loan->loanDetail->fresh()->annual_interest_rate)->toBe(1.75)
        // The rest of the detail is left alone.
        ->and((int) $loan->loanDetail->fresh()->loan_term_months)->toBe(240);
});

it('names every loan field it needs rather than half-creating a loan detail', function () {
    $user = User::factory()->create();
    $loan = Account::factory()->create(['user_id' => $user->id, 'type' => 'loan']);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $loan->id,
        'annual_interest_rate' => 2.25,
    ])->assertHasErrors(['loan_term_months', 'original_amount']);

    expect($loan->loanDetail)->toBeNull();
});

it('edits one field of a property without being asked for its type again', function () {
    $user = User::factory()->create();
    $property = Account::factory()->create(['user_id' => $user->id, 'type' => 'real_estate']);
    $property->realEstateDetail()->create([
        'property_type' => 'residential',
        'purchase_price' => 24_000_000,
    ]);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $property->id,
        'purchase_price' => 26_000_000,
    ])->assertOk();

    $detail = $property->realEstateDetail->fresh();

    expect((int) $detail->purchase_price)->toBe(26_000_000)
        ->and($detail->property_type->value)->toBe('residential');
});

it('demands a property type only while the property is being created', function () {
    $user = User::factory()->create();
    Account::factory()->create(['user_id' => $user->id]);

    callWriteTool($user, CreateAccount::class, [
        'name' => 'A flat with no type',
        'type' => 'real_estate',
        'currency_code' => 'EUR',
    ])->assertHasErrors();

    expect($user->accounts()->where('name', 'A flat with no type')->exists())->toBeFalse();
});

it('rejects update_account for a read-only token', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Untouched']);

    callWriteTool($user, UpdateAccount::class, [
        'account_id' => $account->id,
        'name' => 'Should not stick',
    ], ['mcp:read'])->assertHasErrors(['read-only']);

    expect($account->fresh()->name)->toBe('Untouched');
});
