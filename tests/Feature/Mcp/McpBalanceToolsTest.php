<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\DeleteBalance;
use App\Mcp\Tools\ListBalances;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\Space;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call a balance tool as $user with a real personal access token, so the
 * read/write ability gate is exercised exactly as it is over HTTP.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callBalanceTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * The snapshots list_balances returned, decoded.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<int, array<string, mixed>>
 */
function listedBalances(User $user, array $arguments = []): array
{
    $response = callBalanceTool($user, ListBalances::class, $arguments, ['mcp:read']);

    $response->assertOk();

    return mcpPayload($response)['balances'];
}

it('lists the snapshots of a space newest first', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    foreach (['2026-01-31', '2026-03-31', '2026-02-28'] as $date) {
        AccountBalance::factory()->create(['account_id' => $account->id, 'balance_date' => $date]);
    }

    expect(array_column(listedBalances($user), 'balance_date'))
        ->toBe(['2026-03-31', '2026-02-28', '2026-01-31']);
});

it('filters snapshots by account and by date range', function () {
    $user = User::factory()->create();
    $current = Account::factory()->create(['user_id' => $user->id]);
    $savings = Account::factory()->create(['user_id' => $user->id]);

    AccountBalance::factory()->create(['account_id' => $current->id, 'balance_date' => '2026-01-31']);
    AccountBalance::factory()->create(['account_id' => $current->id, 'balance_date' => '2026-03-31']);
    AccountBalance::factory()->create(['account_id' => $savings->id, 'balance_date' => '2026-02-28']);

    expect(listedBalances($user, ['account_id' => $savings->id]))->toHaveCount(1)
        ->and(array_column(listedBalances($user, ['from' => '2026-02-01', 'to' => '2026-03-01']), 'balance_date'))
        ->toBe(['2026-02-28'])
        ->and(listedBalances($user, ['limit' => 1]))->toHaveCount(1);
});

it('reads the snapshots of a connected account it may not write to', function () {
    $user = User::factory()->create();
    $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'banking_connection_id' => $connection->id,
    ]);
    $balance = AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-04-30',
    ]);

    expect(array_column(listedBalances($user), 'id'))->toBe([$balance->id]);

    callBalanceTool($user, DeleteBalance::class, ['balance_id' => $balance->id])
        ->assertHasErrors(['connected to a bank']);

    expect(AccountBalance::query()->find($balance->id))->not->toBeNull();
});

it('never shows a snapshot from another space or another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $shared = Space::factory()->create(['owner_id' => $user->id]);

    $personal = Account::factory()->create(['user_id' => $user->id]);
    $sharedAccount = Account::factory()->create(['user_id' => $user->id, 'space_id' => $shared->id]);
    $foreign = Account::factory()->create(['user_id' => $other->id]);

    $mine = AccountBalance::factory()->create(['account_id' => $personal->id]);
    $theirs = AccountBalance::factory()->create(['account_id' => $sharedAccount->id]);
    AccountBalance::factory()->create(['account_id' => $foreign->id]);

    expect(array_column(listedBalances($user), 'id'))->toBe([$mine->id])
        ->and(array_column(listedBalances($user, ['space' => $shared->id]), 'id'))->toBe([$theirs->id]);
});

it('deletes a snapshot identified by its id', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $balance = AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => '2026-05-31',
    ]);

    callBalanceTool($user, DeleteBalance::class, ['balance_id' => $balance->id])
        ->assertOk()
        ->assertSee('2026-05-31');

    expect(AccountBalance::query()->find($balance->id))->toBeNull();
});

it('deletes a snapshot identified by its account and date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    callBalanceTool($user, CreateBalance::class, [
        'account_id' => $account->id,
        'balance' => 125000,
        'balance_date' => '2026-06-30',
    ])->assertOk();

    callBalanceTool($user, DeleteBalance::class, [
        'account_id' => $account->id,
        'balance_date' => '2026-06-30',
    ])->assertOk();

    expect(AccountBalance::query()->where('account_id', $account->id)->count())->toBe(0);
});

it('refuses to guess which snapshot to delete', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $balance = AccountBalance::factory()->create(['account_id' => $account->id]);

    callBalanceTool($user, DeleteBalance::class, ['account_id' => $account->id])
        ->assertHasErrors(['balance_id']);

    expect(AccountBalance::query()->find($balance->id))->not->toBeNull();
});

it('reports a snapshot that does not exist on that date', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    callBalanceTool($user, DeleteBalance::class, [
        'account_id' => $account->id,
        'balance_date' => '2026-07-31',
    ])->assertHasErrors(['No balance snapshot']);
});

it('rejects deleting a snapshot with a read-only token', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $balance = AccountBalance::factory()->create(['account_id' => $account->id]);

    callBalanceTool($user, DeleteBalance::class, ['balance_id' => $balance->id], ['mcp:read'])
        ->assertHasErrors(['read-only']);

    expect(AccountBalance::query()->find($balance->id))->not->toBeNull();
});
