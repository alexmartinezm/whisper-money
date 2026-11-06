<?php

use App\Enums\RuleOrigin;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * Call an automation-rule tool as $user with a real personal access token, so
 * the read/write ability gate is exercised exactly as it is over HTTP.
 *
 * @param  array<string, mixed>  $arguments
 * @param  list<string>  $abilities
 */
function callAutomationRuleTool(User $user, string $tool, array $arguments = [], array $abilities = ['mcp:read', 'mcp:write']): TestResponse
{
    $user->withAccessToken($user->createToken('mcp', $abilities)->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool($tool, $arguments);
}

/**
 * The rules list_automation_rules returned, decoded.
 *
 * @param  array<string, mixed>  $arguments
 * @return array<int, array<string, mixed>>
 */
function listedAutomationRules(User $user, array $arguments = []): array
{
    $response = callAutomationRuleTool($user, ListAutomationRules::class, $arguments, ['mcp:read']);

    $response->assertOk();

    return mcpPayload($response)['automation_rules'];
}

it('shows a rule as soon as it is created, with the id needed to edit it', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);

    callAutomationRuleTool($user, CreateAutomationRule::class, [
        'title' => 'Grocery rule',
        'priority' => 3,
        'rules_json' => ['in' => ['grocery', ['var' => 'description']]],
        'action_category_id' => $category->id,
        'action_note' => 'Weekly shop',
    ])->assertOk();

    $rules = listedAutomationRules($user);

    expect($rules)->toHaveCount(1);

    $rule = $rules[0];

    expect($rule['title'])->toBe('Grocery rule')
        ->and($rule['priority'])->toBe(3)
        ->and($rule['action_category_id'])->toBe($category->id)
        ->and($rule['action_note'])->toBe('Weekly shop')
        ->and($rule['origin'])->toBe(RuleOrigin::User->value)
        ->and($rule['created_at'])->not->toBeNull()
        ->and($rule['updated_at'])->not->toBeNull();

    // The id it hands back has to be the one the write tools accept.
    callAutomationRuleTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule['id'],
        'title' => 'Supermarket rule',
    ])->assertOk();

    expect(listedAutomationRules($user)[0]['title'])->toBe('Supermarket rule');
});

it('reflects every edited field and drops the rule once deleted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Reviewed']);
    $rule = AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Before',
        'priority' => 1,
        'action_category_id' => $category->id,
        'action_note' => 'Before note',
    ]);

    callAutomationRuleTool($user, UpdateAutomationRule::class, [
        'automation_rule_id' => $rule->id,
        'title' => 'After',
        'priority' => 7,
        'rules_json' => ['>' => [['var' => 'amount'], 12.5]],
        'action_note' => null,
        'action_label_ids' => [$label->id],
    ])->assertOk();

    $listed = listedAutomationRules($user)[0];

    expect($listed['title'])->toBe('After')
        ->and($listed['priority'])->toBe(7)
        ->and($listed['rules_json'])->toBe(['>' => [['var' => 'amount'], 12.5]])
        ->and($listed['action_note'])->toBeNull()
        ->and($listed['labels'])->toBe([['id' => $label->id, 'name' => 'Reviewed']]);

    callAutomationRuleTool($user, DeleteAutomationRule::class, [
        'automation_rule_id' => $rule->id,
    ])->assertOk();

    expect(listedAutomationRules($user))->toBeEmpty();
});

it('returns the JsonLogic condition without transforming it', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    // Nested operators, a float in major units and a non-ASCII literal: the
    // condition has to survive the round trip exactly, or a rule the agent
    // reads back is not the rule that runs.
    $condition = [
        'and' => [
            ['>' => [['var' => 'amount'], 12.5]],
            ['in' => ['café', ['var' => 'description']]],
            ['==' => [['var' => 'account_name'], 'Nómina']],
        ],
    ];

    callAutomationRuleTool($user, CreateAutomationRule::class, [
        'title' => 'Coffee rule',
        'priority' => 0,
        'rules_json' => $condition,
        'action_category_id' => $category->id,
    ])->assertOk();

    expect(listedAutomationRules($user)[0]['rules_json'])->toBe($condition);
});

it('orders rules by priority and keeps the order stable', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    foreach ([['Third', 9], ['First', 0], ['Second', 4]] as [$title, $priority]) {
        AutomationRule::factory()->create([
            'user_id' => $user->id,
            'title' => $title,
            'priority' => $priority,
            'action_category_id' => $category->id,
        ]);
    }

    $titles = fn (): array => array_column(listedAutomationRules($user), 'title');

    expect($titles())->toBe(['First', 'Second', 'Third'])
        ->and($titles())->toBe(['First', 'Second', 'Third']);
});

it('filters by origin and honours the limit', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Written by hand',
        'priority' => 0,
        'origin' => RuleOrigin::User,
        'action_category_id' => $category->id,
    ]);
    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Learned from a correction',
        'priority' => 1,
        'origin' => RuleOrigin::Correction,
        'action_category_id' => $category->id,
    ]);

    expect(array_column(listedAutomationRules($user, ['origin' => 'correction']), 'title'))
        ->toBe(['Learned from a correction'])
        ->and(listedAutomationRules($user, ['limit' => 1]))->toHaveCount(1);
});

it('rejects an origin that is not one of the known ones', function () {
    $user = User::factory()->create();

    callAutomationRuleTool($user, ListAutomationRules::class, ['origin' => 'made-up'], ['mcp:read'])
        ->assertHasErrors(['origin']);
});

it('never shows a rule from another space or another user', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $shared = Space::factory()->create(['owner_id' => $other->id]);
    // The pivot carries its own uuid primary key and no database default.
    $shared->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $category = Category::factory()->create(['user_id' => $user->id]);

    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Personal rule',
        'action_category_id' => $category->id,
    ]);
    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'space_id' => $shared->id,
        'title' => 'Shared rule',
        'action_category_id' => $category->id,
    ]);
    AutomationRule::factory()->create([
        'user_id' => $other->id,
        'title' => 'Somebody else\'s rule',
    ]);

    expect(array_column(listedAutomationRules($user), 'title'))->toBe(['Personal rule'])
        ->and(array_column(listedAutomationRules($user, ['space' => $shared->id]), 'title'))->toBe(['Shared rule']);
});

it('lets a read-only token list rules', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    AutomationRule::factory()->create([
        'user_id' => $user->id,
        'title' => 'Readable',
        'action_category_id' => $category->id,
    ]);

    callAutomationRuleTool($user, ListAutomationRules::class, [], ['mcp:read'])
        ->assertOk()
        ->assertSee('Readable');
});

it('refuses conditions larger than the engines should run on every transaction', function (mixed $conditions, string $error) {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);

    callAutomationRuleTool($user, CreateAutomationRule::class, [
        'title' => 'Refused',
        'priority' => 1,
        'rules_json' => $conditions,
        'action_category_id' => $category->id,
    ])->assertHasErrors([$error]);

    expect(AutomationRule::query()->where('user_id', $user->id)->count())->toBe(0);
})->with([
    'too many conditions' => [['or' => array_map(fn (int $index): array => ['in' => ["shop {$index}", ['var' => 'description']]], range(1, 500))], '2000 JsonLogic values'],
    'a huge list of plain values' => [['in' => [['var' => 'description'], array_fill(0, 3_000, 'x')]], '2000 JsonLogic values'],
    'a megabyte string' => [['in' => [str_repeat('x', 1_000_000), ['var' => 'description']]], '1000 characters'],
]);

it('refuses blank conditions', function (string $blank) {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'action_category_id' => $category->id, 'rules_json' => ['in' => ['x', ['var' => 'description']]]]);

    callAutomationRuleTool($user, CreateAutomationRule::class, ['title' => 'Refused', 'priority' => 1, 'rules_json' => $blank, 'action_category_id' => $category->id])
        ->assertHasErrors(['rules_json must be a non-empty JsonLogic object.']);
    callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $rule->id, 'rules_json' => $blank])
        ->assertHasErrors(['rules_json must be a non-empty JsonLogic object.']);

    expect($rule->fresh()->rules_json)->toBe(['in' => ['x', ['var' => 'description']]]);
})->with(['an empty string' => '', 'a blank string' => '   ', 'an encoded empty string' => '""']);

it('takes action_label_ids as null or a bare id, as it always has', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $label = Label::factory()->create(['user_id' => $user->id]);
    $conditions = ['in' => ['x', ['var' => 'description']]];

    $withNull = callAutomationRuleTool($user, CreateAutomationRule::class, ['title' => 'Null', 'priority' => 1, 'rules_json' => $conditions, 'action_category_id' => $category->id, 'action_label_ids' => null])->assertOk();
    $bare = callAutomationRuleTool($user, CreateAutomationRule::class, ['title' => 'Bare', 'priority' => 2, 'rules_json' => $conditions, 'action_label_ids' => $label->id])->assertOk();

    expect(mcpPayload($withNull)['automation_rule']['labels'])->toBe([])
        ->and(mcpPayload($bare)['automation_rule']['labels'])->toBe([['id' => $label->id, 'name' => $label->name]]);

    $id = mcpPayload($withNull)['automation_rule']['id'];

    $updated = callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $id, 'action_label_ids' => $label->id])->assertOk();
    expect(mcpPayload($updated)['automation_rule']['labels'])->toBe([['id' => $label->id, 'name' => $label->name]]);

    $cleared = callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $id, 'action_label_ids' => null])->assertOk();
    expect(mcpPayload($cleared)['automation_rule']['labels'])->toBe([]);
});

it('caps the note and the labels a rule applies', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'action_category_id' => $category->id]);

    callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $rule->id, 'action_note' => str_repeat('x', 10_001)])
        ->assertHasErrors(['action note']);
    callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $rule->id, 'action_label_ids' => array_map(fn (int $index): string => (string) Str::uuid(), range(1, 51))])
        ->assertHasErrors(['at most 50 labels']);
});

it('stores a new note as plain text on a rule whose note was encrypted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['user_id' => $user->id]);
    $rule = AutomationRule::factory()->create(['user_id' => $user->id, 'action_category_id' => $category->id, 'action_note' => 'bm90ZQ==', 'action_note_iv' => 'abcdefghijklmnop']);

    callAutomationRuleTool($user, UpdateAutomationRule::class, ['automation_rule_id' => $rule->id, 'action_note' => 'Plain'])->assertOk();

    expect($rule->fresh())->action_note->toBe('Plain')->action_note_iv->toBeNull();
});
