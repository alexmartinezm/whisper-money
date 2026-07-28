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

    expect($pageCount)->toBeGreaterThan(1)
        ->and($tools->pluck('name'))->toContain('list_budgets', 'create_budget', 'update_budget', 'delete_budget');
});
