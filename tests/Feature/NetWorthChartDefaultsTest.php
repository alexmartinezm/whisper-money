<?php

use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Testing\TestResponse;

/**
 * Loans and real estate start off in the net worth chart for a new user. A
 * `user_settings` row is only written the first time someone touches a
 * preference, so "no row" is also what every account that predates the change
 * looks like: the migration backfills those with an explicit true/true, which is
 * why only genuinely new users get the new default.
 */
function netWorthChartToggleProps(User $user): TestResponse
{
    return test()->actingAs($user)->get(route('appearance.edit'))->assertOk();
}

function runNetWorthChartDefaultsBackfill(): void
{
    $migration = require database_path('migrations/2026_09_16_120000_default_net_worth_chart_loans_and_real_estate_off.php');
    $migration->backfillUntouchedUsers();
}

test('a new user with no settings row gets loans and real estate off in the chart', function () {
    $user = User::factory()->create();

    expect($user->setting)->toBeNull();

    netWorthChartToggleProps($user)->assertInertia(fn ($page) => $page
        ->where('includeLoansInNetWorthChart', false)
        ->where('includeRealEstateInNetWorthChart', false));
});

test('a settings row written for another preference starts with both toggles off', function () {
    $user = User::factory()->create();

    UserSetting::query()->create(['user_id' => $user->id]);

    $setting = $user->fresh()->setting;

    expect($setting->include_loans_in_net_worth_chart)->toBeFalse()
        ->and($setting->include_real_estate_in_net_worth_chart)->toBeFalse();
});

test('the backfill keeps loans and real estate on for users who never touched the toggles', function () {
    $untouched = User::factory()->create();

    runNetWorthChartDefaultsBackfill();

    $setting = $untouched->fresh()->setting;

    expect($setting)->not->toBeNull()
        ->and($setting->include_loans_in_net_worth_chart)->toBeTrue()
        ->and($setting->include_real_estate_in_net_worth_chart)->toBeTrue();

    netWorthChartToggleProps($untouched->fresh())->assertInertia(fn ($page) => $page
        ->where('includeLoansInNetWorthChart', true)
        ->where('includeRealEstateInNetWorthChart', true));
});

test('the backfilled row changes no other preference the user had without a row', function () {
    $untouched = User::factory()->create();

    runNetWorthChartDefaultsBackfill();

    $setting = $untouched->fresh()->setting;

    // Each value is the fallback the code reads when there is no row at all.
    expect($setting->chart_color_scheme->value)->toBe('colorful')
        ->and($setting->notify_on_bank_transactions_synced)->toBeTrue()
        ->and($setting->notify_on_inactive_no_bank)->toBeTrue()
        ->and($setting->budget_notify_on_new_transaction)->toBeFalse()
        ->and($setting->budget_notify_on_close_to_limit)->toBeTrue()
        ->and($setting->budget_notify_on_over_limit)->toBeTrue()
        ->and($setting->notify_upcoming_recurring)->toBeFalse()
        ->and($setting->notify_runway_shortfall)->toBeFalse()
        ->and($setting->notify_price_changes)->toBeFalse()
        ->and($setting->savings_goal_notify_on_month_end_reminder)->toBeTrue();
});

test('the backfill leaves an existing settings row as it was', function () {
    $optedOut = User::factory()->create();
    UserSetting::factory()->for($optedOut)->create([
        'include_loans_in_net_worth_chart' => false,
        'include_real_estate_in_net_worth_chart' => false,
    ]);

    $loansOnly = User::factory()->create();
    UserSetting::factory()->for($loansOnly)->create([
        'include_loans_in_net_worth_chart' => true,
        'include_real_estate_in_net_worth_chart' => false,
    ]);

    runNetWorthChartDefaultsBackfill();

    expect(UserSetting::query()->whereIn('user_id', [$optedOut->id, $loansOnly->id])->count())->toBe(2);

    netWorthChartToggleProps($optedOut->fresh())->assertInertia(fn ($page) => $page
        ->where('includeLoansInNetWorthChart', false)
        ->where('includeRealEstateInNetWorthChart', false));

    netWorthChartToggleProps($loansOnly->fresh())->assertInertia(fn ($page) => $page
        ->where('includeLoansInNetWorthChart', true)
        ->where('includeRealEstateInNetWorthChart', false));
});
