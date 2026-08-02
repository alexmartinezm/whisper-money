<?php

use App\Mcp\Servers\WhisperMoneyServer;
use App\Models\User;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

$rpc = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];

it('rejects the MCP endpoint without a token', function () use ($rpc) {
    postJson('/mcp', $rpc)->assertUnauthorized();
});

it('rejects a token without the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('not-mcp', ['other'])->plainTextToken;

    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertForbidden();
});

it('accepts a token carrying the mcp:read ability', function () use ($rpc) {
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    // Auth + ability middleware pass, so the request reaches the MCP transport
    // (which answers the JSON-RPC envelope) rather than being rejected.
    withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk();
});

it('serves the whole tool catalogue on one page', function () use ($rpc) {
    // A client that does not follow nextCursor must still see every tool, so
    // the page size has to stay ahead of the catalogue as it grows.
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    $result = withHeaders(['Authorization' => "Bearer {$plain}"])
        ->postJson('/mcp', $rpc)
        ->assertOk()
        ->json('result');

    $registered = (new ReflectionClass(WhisperMoneyServer::class))
        ->getDefaultProperties()['tools'];

    expect($result['nextCursor'] ?? null)->toBeNull()
        ->and($result['tools'])->toHaveCount(count($registered));
});

it('lists the transaction split write tool with its required schema', function () {
    $user = User::factory()->create();
    $plain = $user->createToken('mcp', ['mcp:read'])->plainTextToken;

    // tools/list is paginated, so walk the cursor rather than assuming the
    // tool under test happens to land on the first page — adding any tool
    // ahead of it used to break this.
    $tools = [];
    $cursor = null;

    do {
        $result = withHeaders(['Authorization' => "Bearer {$plain}"])
            ->postJson('/mcp', array_filter([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => $cursor === null ? null : ['cursor' => $cursor],
            ]))
            ->assertOk()
            ->json('result');

        $tools = array_merge($tools, $result['tools'] ?? []);
        $cursor = $result['nextCursor'] ?? null;
    } while ($cursor !== null);

    $splitTool = collect($tools)->firstWhere('name', 'split_transaction');

    expect($splitTool)->not->toBeNull()
        ->and($splitTool['inputSchema']['required'])->toContain('transaction_id', 'splits')
        ->and($splitTool['inputSchema']['properties']['splits']['type'])->toBe('array')
        ->and($splitTool['annotations']['destructiveHint'] ?? null)->toBeTrue();
});
