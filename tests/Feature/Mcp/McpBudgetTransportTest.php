<?php

use App\Models\User;

use function Pest\Laravel\withHeaders;

it('exposes budget tools through the JSON-RPC transport', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp-budget-schema', ['mcp:read'])->plainTextToken;

    $tools = withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])
        ->assertOk()
        ->json('result.tools');

    $names = collect($tools)->pluck('name');
    expect($names)->toContain('list_budgets', 'create_budget', 'update_budget', 'delete_budget');
});
