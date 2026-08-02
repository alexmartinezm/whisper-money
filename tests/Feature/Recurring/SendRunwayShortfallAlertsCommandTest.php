<?php

use App\Enums\AccountType;
use App\Features\RecurringTransactions;
use App\Mail\RunwayShortfallEmail;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\RecurringSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Http::fake();
    Mail::fake();
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->user->setting()->updateOrCreate(
        ['user_id' => $this->user->id],
        ['notify_runway_shortfall' => true],
    );
});

function alertAccount(User $user, int $balance): Account
{
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
        'include_in_net_worth' => true,
    ]);

    AccountBalance::factory()->create([
        'account_id' => $account->id,
        'balance_date' => CarbonImmutable::today()->toDateString(),
        'balance' => $balance,
    ]);

    return $account;
}

/** A charge big enough to drive the projection under. */
function shortfallCharge(User $user, int $amount = -150000): RecurringSeries
{
    return RecurringSeries::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->personalSpace->id,
        'display_name' => 'Alquiler',
        'expected_amount' => $amount,
        'currency_code' => 'EUR',
        'next_expected_on' => CarbonImmutable::today()->addDays(5),
        'interval_days' => 30,
    ]);
}

it('warns when the projection dips below zero', function () {
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertQueued(RunwayShortfallEmail::class);
});

it('stays quiet while the balance holds', function () {
    alertAccount($this->user, 500000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not repeat the same warning every morning', function () {
    // The projection reports the same dip until the day arrives, so a naive
    // implementation would send this a dozen times and get switched off.
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();
    $this->artisan('recurring:alert-shortfall')->assertSuccessful();
    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('speaks again once the announced dip has passed', function () {
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    // Yesterday's dip came and went and the balance is still heading under.
    $this->user->setting->forceFill([
        'runway_alerted_low_on' => CarbonImmutable::today()->subDay()->toDateString(),
    ])->saveQuietly();

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertQueuedCount(2);
});

it('forgets the dip once the projection recovers', function () {
    alertAccount($this->user, 50000);
    $charge = shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();
    expect($this->user->setting->fresh()->runway_alerted_low_on)->not->toBeNull();

    $charge->forceFill(['expected_amount' => -1000])->saveQuietly();
    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    expect($this->user->setting->fresh()->runway_alerted_low_on)->toBeNull();
    Mail::assertQueuedCount(1);
});

it('says nothing when the user has not opted in', function () {
    $this->user->setting->update(['notify_runway_shortfall' => false]);
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not warn about the absence of accounts', function () {
    // With nothing counted the walk starts at zero and any charge looks fatal.
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('stays quiet while the feature is switched off', function () {
    config()->set('recurring.enabled', false);
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('skips a user whose flag is off', function () {
    Feature::for($this->user)->deactivate(RecurringTransactions::class);
    alertAccount($this->user, 50000);
    shortfallCharge($this->user);

    $this->artisan('recurring:alert-shortfall')->assertSuccessful();

    Mail::assertNothingQueued();
});
