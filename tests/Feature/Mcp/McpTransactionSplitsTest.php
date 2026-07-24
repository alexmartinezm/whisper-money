<?php

use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Features\TransactionSplitting;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\SplitTransaction;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Server\Testing\TestResponse;
use Laravel\Pennant\Feature;

/**
 * @return array{0: User, 1: Account, 2: Transaction, 3: Category, 4: Category}
 */
function splitMcpFixture(array $transactionAttributes = []): array
{
    $user = User::factory()->onboarded()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $transaction = Transaction::factory()->imported()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $account->space_id,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
        'transaction_date' => '2026-07-18',
        'currency_code' => 'EUR',
        ...$transactionAttributes,
    ]);
    $food = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'name' => 'Food',
        'type' => CategoryType::Expense,
    ]);
    $home = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'name' => 'Home',
        'type' => CategoryType::Expense,
    ]);

    return [$user, $account, $transaction, $food, $home];
}

/**
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callMcpSplitTool(User $user, array $arguments, array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool(SplitTransaction::class, $arguments);
}

it('creates splits for an imported transaction through MCP', function () {
    [$user, $account, $transaction, $food, $home] = splitMcpFixture();
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'action_category_id' => $food->id,
    ]);
    $suggested = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]);
    $transaction->forceFill([
        'category_id' => $food->id,
        'category_source' => CategorySource::Ai,
        'categorized_by_rule_id' => $rule->id,
        'ai_confidence' => 0.92,
        'ai_model' => 'test-model',
        'ai_suggested_category_id' => $suggested->id,
        'ai_suggested_category_at' => Carbon::parse('2026-07-17 12:00:00'),
    ])->saveQuietly();

    $before = $transaction->fresh();
    $response = callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ]);

    $response->assertOk()
        ->assertSee('"id":"'.$transaction->id.'"', false)
        ->assertSee('"is_split":true', false)
        ->assertSee('"category_id":null', false)
        ->assertSee('"category_id":"'.$food->id.'"', false)
        ->assertSee('"amount":-6000', false)
        ->assertSee('"position":0', false)
        ->assertSee('"category_id":"'.$home->id.'"', false)
        ->assertSee('"amount":-4000', false)
        ->assertSee('"position":1', false);

    $transaction->refresh();

    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->categorized_by_rule_id)->toBeNull()
        ->and($transaction->ai_confidence)->toBeNull()
        ->and($transaction->ai_model)->toBeNull()
        ->and($transaction->ai_suggested_category_id)->toBeNull()
        ->and($transaction->ai_suggested_category_at)->toBeNull()
        ->and($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($transaction->splits()->pluck('position')->all())->toBe([0, 1])
        ->and($transaction->amount)->toBe($before->amount)
        ->and($transaction->account_id)->toBe($before->account_id)
        ->and($transaction->transaction_date->toDateString())->toBe($before->transaction_date->toDateString())
        ->and($transaction->currency_code)->toBe($before->currency_code)
        ->and($transaction->source)->toBe($before->source)
        ->and($account->id)->toBe($before->account_id);
});

it('replaces all existing split lines through MCP', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();
    app(\App\Services\Transactions\ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);
    $before = $transaction->refresh()->updated_at;
    $oldIds = $transaction->splits()->pluck('id')->all();

    $response = callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -2500],
            ['category_id' => $home->id, 'amount' => -7500],
        ],
    ]);

    $response->assertOk();
    $transaction->refresh();
    $newIds = $transaction->splits()->pluck('id')->all();

    expect($transaction->splits)->toHaveCount(2)
        ->and($transaction->splits->pluck('amount')->all())->toBe([-2500, -7500])
        ->and($transaction->splits->pluck('position')->all())->toBe([0, 1])
        ->and($transaction->splits->pluck('id')->all())->not->toBe($oldIds)
        ->and($newIds)->toHaveCount(2)
        ->and($transaction->category_id)->toBeNull()
        ->and($transaction->updated_at->greaterThan($before))->toBeTrue();
});

it('removes a split with a fallback category through MCP', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();
    app(\App\Services\Transactions\ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [],
        'fallback_category_id' => $food->id,
    ])->assertOk()
        ->assertSee('"is_split":false', false)
        ->assertSee('"category_id":"'.$food->id.'"', false)
        ->assertSee('"category_source":"manual"', false);

    $transaction->refresh();

    expect($transaction->splits()->count())->toBe(0)
        ->and($transaction->category_id)->toBe($food->id)
        ->and($transaction->category_source)->toBe(CategorySource::Manual);
});

it('rejects removing a split without a fallback and preserves existing lines', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();
    app(\App\Services\Transactions\ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [],
    ])->assertHasErrors(['splits']);

    expect($transaction->fresh()->splits()->pluck('amount')->all())->toBe([-6000, -4000]);
});

it('allows only unsplitting while transaction splitting is disabled', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();
    Feature::for($user)->deactivate(TransactionSplitting::class);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ])->assertSee('currently disabled');

    expect($transaction->fresh()->splits()->count())->toBe(0);

    app(\App\Services\Transactions\ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [],
        'fallback_category_id' => $food->id,
    ])->assertOk();

    expect($transaction->fresh()->splits()->count())->toBe(0)
        ->and($transaction->fresh()->category_id)->toBe($food->id);
});

it('rejects read-only tokens without mutating splits', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ], ['mcp:read'])->assertSee('read-only');

    expect($transaction->fresh()->splits()->count())->toBe(0);
});

it('rejects invalid split totals and preserves the previous state', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();
    app(\App\Services\Transactions\ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $food->id, 'amount' => -6000],
        ['category_id' => $home->id, 'amount' => -4000],
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -5000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ])->assertHasErrors(['splits']);

    expect($transaction->fresh()->splits()->pluck('amount')->all())->toBe([-6000, -4000]);
});

it('rejects split categories outside the transaction space without partial writes', function () {
    [$user, , $transaction, $food] = splitMcpFixture();
    $otherUser = User::factory()->onboarded()->create();
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);
    $otherCategory = Category::factory()->create([
        'user_id' => $otherUser->id,
        'space_id' => $otherAccount->space_id,
        'type' => CategoryType::Expense,
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $otherCategory->id, 'amount' => -4000],
        ],
    ])->assertHasErrors(['splits']);

    expect($transaction->fresh()->splits()->count())->toBe(0);
});

it('rejects a transaction outside the selected space', function () {
    [$user, , , $food, $home] = splitMcpFixture();
    $otherUser = User::factory()->onboarded()->create();
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);
    $otherTransaction = Transaction::factory()->imported()->plaintext()->create([
        'user_id' => $otherUser->id,
        'space_id' => $otherAccount->space_id,
        'account_id' => $otherAccount->id,
        'amount' => -10000,
        'category_id' => null,
    ]);

    callMcpSplitTool($user, [
        'transaction_id' => $otherTransaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
    ])->assertHasErrors(['transaction_id']);

    expect($otherTransaction->fresh()->splits()->count())->toBe(0);
});

it('rejects fallback_category_id with a non-empty replacement', function () {
    [$user, , $transaction, $food, $home] = splitMcpFixture();

    callMcpSplitTool($user, [
        'transaction_id' => $transaction->id,
        'splits' => [
            ['category_id' => $food->id, 'amount' => -6000],
            ['category_id' => $home->id, 'amount' => -4000],
        ],
        'fallback_category_id' => $food->id,
    ])->assertSee('only valid when splits is empty');

    expect($transaction->fresh()->splits()->count())->toBe(0);
});
