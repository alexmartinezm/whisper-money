<?php

use App\Models\User;

use function Pest\Laravel\withHeaders;

it('exposes budget tools through the paginated JSON-RPC transport', function () {
    $user = User::factory()->create();
    $token = $user->createToken('mcp-budget-schema', ['mcp:read'])->plainTextToken;
    $headers = ['Authorization' => "Bearer {$token}"];
    $tools = collect();
    $cursor = null;
    $pageCount = 0;

    do {
        $params = $cursor === null ? [] : ['cursor' => $cursor];
        $response = withHeaders($headers)
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => ++$pageCount,
                'method' => 'tools/list',
                'params' => $params,
            ])
            ->assertOk();

        $tools = $tools->merge($response->json('result.tools'));
        $cursor = $response->json('result.nextCursor');
    } while ($cursor !== null);

    // The page count is deliberately not asserted: the catalogue now fits on a
    // single page so clients that ignore nextCursor cannot lose the write
    // tools, and needing more than one page was the bug, not the contract.
    // What matters is that walking the cursor terminates and surfaces them.
    expect($pageCount)->toBeGreaterThanOrEqual(1)
        ->and($tools->pluck('name'))->toContain('list_budgets', 'create_budget', 'update_budget', 'delete_budget');
});
