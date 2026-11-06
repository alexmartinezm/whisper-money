<?php

use App\Enums\LabelSource;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Testing\TestResponse;

/*
 * The MCP keeps scoping by space, unlike `/api/v1`, which goes no further than
 * the web app: a member's agent reaches what every member keeps in a shared
 * space. The owner's live connector relies on it.
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
    $account = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $category = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $label = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);

    $accounts = mcpPayload(callAsMember($member, ListAccounts::class, ['space' => $space->id])->assertOk());
    $categories = mcpPayload(callAsMember($member, ListCategories::class, ['space' => $space->id])->assertOk());
    $labels = mcpPayload(callAsMember($member, ListLabels::class, ['space' => $space->id])->assertOk());

    expect(collect($accounts['accounts'])->pluck('id')->all())->toBe([$account->id])
        ->and(collect($categories['categories'])->pluck('id')->all())->toBe([$category->id])
        ->and(collect($labels['labels'])->pluck('id')->all())->toBe([$label->id]);
});

it('lets a member edit, nest under and delete a housemate\'s category and label', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $space = householdWith($member, $owner);
    $category = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);
    $label = Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Theirs']);

    callAsMember($member, UpdateCategory::class, ['category_id' => $category->id, 'space' => $space->id, 'name' => 'Renamed'])->assertOk();
    callAsMember($member, UpdateLabel::class, ['label_id' => $label->id, 'space' => $space->id, 'name' => 'Renamed'])->assertOk();
    callAsMember($member, CreateCategory::class, ['space' => $space->id, 'name' => 'Child', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense', 'parent_id' => $category->id])->assertOk();

    expect($category->fresh()->name)->toBe('Renamed')
        ->and($label->fresh()->name)->toBe('Renamed');

    callAsMember($member, DeleteLabel::class, ['label_id' => $label->id, 'space' => $space->id])->assertOk();
    callAsMember($member, DeleteCategory::class, ['category_id' => $category->id, 'space' => $space->id])->assertOk();

    expect(Label::query()->find($label->id))->toBeNull()
        ->and(Category::query()->find($category->id))->toBeNull();
});

it('keeps a name a housemate took refused in the space', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $space = householdWith($member, $owner);
    Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Food', 'parent_id' => null]);
    Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Trip']);

    callAsMember($member, CreateCategory::class, ['space' => $space->id, 'name' => 'Food', 'icon' => 'Home', 'color' => 'red', 'type' => 'expense'])
        ->assertHasErrors(['A category with that name already exists at this level.']);
    callAsMember($member, CreateLabel::class, ['space' => $space->id, 'name' => 'Trip', 'color' => 'red'])
        ->assertHasErrors(['A label with that name already exists.']);
});

it('refuses to rename or delete the label of a savings goal, like the API', function () {
    $user = User::factory()->create();
    $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'New car', 'color' => 'blue', 'source' => LabelSource::SavingsGoal]);

    callAsMember($user, UpdateLabel::class, ['label_id' => $label->id, 'name' => 'Old car'])
        ->assertHasErrors(['renamed with the goal']);
    callAsMember($user, DeleteLabel::class, ['label_id' => $label->id])
        ->assertHasErrors(['it can only go away with the goal']);
    callAsMember($user, UpdateLabel::class, ['label_id' => $label->id, 'color' => 'red'])
        ->assertOk();

    expect($label->fresh())->name->toBe('New car')->color->toBe('red');
});

it('lets a member change a housemate\'s automation rule, and lists it', function () {
    $member = User::factory()->create();
    $owner = User::factory()->create();
    $space = householdWith($member, $owner);
    $category = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]);
    $rule = AutomationRule::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'action_category_id' => $category->id, 'title' => 'Theirs']);

    $listed = mcpPayload(callAsMember($member, ListAutomationRules::class, ['space' => $space->id])->assertOk());

    expect(collect($listed['automation_rules'])->pluck('id')->all())->toBe([$rule->id]);

    callAsMember($member, UpdateAutomationRule::class, ['automation_rule_id' => $rule->id, 'space' => $space->id, 'title' => 'Renamed'])->assertOk();

    expect($rule->fresh()->title)->toBe('Renamed');
});

it('caps a category icon at its column length', function () {
    callAsMember(User::factory()->create(), CreateCategory::class, ['name' => 'Food', 'icon' => str_repeat('x', 256), 'color' => 'red', 'type' => 'expense'])
        ->assertHasErrors(['icon']);
});
