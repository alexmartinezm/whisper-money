<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListAccounts;
use App\Models\Account;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/*
 * The MCP keeps scoping by space, unlike `/api/v1`, which goes no further than
 * the web app: a member's agent sees what every member keeps in a shared space.
 * The owner's live connector relies on it.
 */

function householdWith(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

/**
 * @param  class-string  $tool
 * @param  array<string, mixed>  $arguments
 */
function callAsMember(User $user, string $tool, array $arguments): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', ['mcp:read', 'mcp:write'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

it('lists what every member keeps in a shared space', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $space = householdWith($member, $owner);
    $theirs = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);

    $payload = mcpPayload(callAsMember($member, ListAccounts::class, ['space' => $space->id])->assertOk());

    expect(collect($payload['accounts'])->pluck('id')->all())->toBe([$theirs->id]);
});
