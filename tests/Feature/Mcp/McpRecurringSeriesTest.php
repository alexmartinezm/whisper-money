<?php

use App\Enums\RecurringCadence;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\ListRecurringSeries;
use App\Models\RecurringSeries;
use App\Models\User;
use Laravel\Mcp\Server\Testing\TestResponse;

/**
 * @param  array<string, mixed>  $arguments
 */
function callRecurringTool(User $user, array $arguments = []): TestResponse
{
    $user->withAccessToken($user->createToken('mcp-recurring-test', ['mcp:read'])->accessToken);

    return WhisperMoneyServer::actingAs($user)->tool(ListRecurringSeries::class, $arguments);
}

it('lists the detected series', function () {
    $user = User::factory()->create();
    RecurringSeries::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Netflix',
        'expected_amount' => -1299,
    ]);

    callRecurringTool($user)
        ->assertOk()
        ->assertSee('Netflix')
        ->assertSee('monthly_equivalent_amount');
});

it('rescales cadences onto a monthly total', function () {
    $user = User::factory()->create();
    RecurringSeries::factory()->cadence(RecurringCadence::Yearly)->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'expected_amount' => -12000,
        'currency_code' => 'EUR',
    ]);

    callRecurringTool($user)
        ->assertOk()
        ->assertSee('"monthly_expense_totals":[{"currency_code":"EUR","monthly_expense":-1000}]', false);
});

it('reports one total per currency rather than adding them up', function () {
    $user = User::factory()->create();

    foreach (['EUR', 'USD'] as $currency) {
        RecurringSeries::factory()->create([
            'user_id' => $user->id,
            'space_id' => $user->personalSpace->id,
            'expected_amount' => -5000,
            'currency_code' => $currency,
        ]);
    }

    $response = callRecurringTool($user)->assertOk();

    // 50 EUR + 50 USD must never surface as a single 100.
    $response->assertSee('"currency_code":"EUR","monthly_expense":-5000', false);
    $response->assertSee('"currency_code":"USD","monthly_expense":-5000', false);
    $response->assertDontSee('"monthly_expense":-10000', false);
});

it('hides dismissed series unless asked for them', function () {
    $user = User::factory()->create();
    RecurringSeries::factory()->ignored()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Dismissed Thing',
    ]);

    callRecurringTool($user)->assertOk()->assertDontSee('Dismissed Thing');
    callRecurringTool($user, ['include_ignored' => true])->assertOk()->assertSee('Dismissed Thing');
});

it('filters by status', function () {
    $user = User::factory()->create();
    RecurringSeries::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Still Billing',
    ]);
    RecurringSeries::factory()->lapsed()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Cancelled Thing',
    ]);

    callRecurringTool($user, ['status' => 'active'])
        ->assertOk()
        ->assertSee('Still Billing')
        ->assertDontSee('Cancelled Thing');
});

it('does not expose another user series', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    RecurringSeries::factory()->create([
        'user_id' => $other->id,
        'space_id' => $other->personalSpace->id,
        'display_name' => 'Someone Else Subscription',
    ]);

    callRecurringTool($user)->assertOk()->assertDontSee('Someone Else Subscription');
});
