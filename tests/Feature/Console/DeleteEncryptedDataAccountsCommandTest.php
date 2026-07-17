<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

use function Pest\Laravel\artisan;

test('it soft-deletes users with encrypted data who did not sign in within the grace window', function () {
    $inactive = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($inactive)->create();

    $neverActive = User::factory()->create(['last_active_at' => null]);
    Account::factory()->for($neverActive)->create(['name_iv' => 'abcdef0123456789']);

    $recentlyActive = User::factory()->create(['last_active_at' => now()->subDay()]);
    Transaction::factory()->for($recentlyActive)->create();

    $clean = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($clean)->plaintext()->create();

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    // The default soft-delete scope hides trashed users, so a missing row means it was deleted.
    expect(User::whereKey($inactive->id)->exists())->toBeFalse();
    expect(User::whereKey($neverActive->id)->exists())->toBeFalse();
    expect(User::whereKey($recentlyActive->id)->exists())->toBeTrue();
    expect(User::whereKey($clean->id)->exists())->toBeTrue();
});

test('it never deletes a subscribed user even when the subscriptions flag is off', function () {
    config(['subscriptions.enabled' => false]);

    $subscribed = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($subscribed)->create();
    $subscribed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($subscribed->id)->exists())->toBeTrue();
});

test('it never deletes a trialing subscriber', function () {
    config(['subscriptions.enabled' => false]);

    $trialing = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($trialing)->create();
    $trialing->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_trial123',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_test123',
        'trial_ends_at' => now()->addDays(5),
    ]);

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($trialing->id)->exists())->toBeTrue();
});

test('it never deletes a past-due subscriber', function () {
    config(['subscriptions.enabled' => false]);

    $pastDue = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($pastDue)->create();
    $pastDue->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_pastdue123',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_test123',
    ]);

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($pastDue->id)->exists())->toBeTrue();
});

test('it deletes a subscriber who is only in the cancellation grace period', function () {
    config(['subscriptions.enabled' => false]);

    $grace = User::factory()->create(['last_active_at' => now()->subDays(30)]);
    Transaction::factory()->for($grace)->create();
    $grace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_grace123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->addDays(5),
    ]);

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($grace->id)->exists())->toBeFalse();
});

test('it never deletes the demo account', function () {
    $demo = User::factory()->create([
        'email' => config('app.demo.email'),
        'last_active_at' => now()->subDays(30),
    ]);
    Transaction::factory()->for($demo)->create();

    artisan('encryption:delete-accounts', ['--force' => true])->assertSuccessful();

    expect(User::whereKey($demo->id)->exists())->toBeTrue();
});

test('dry run deletes nothing', function () {
    $user = User::factory()->create(['last_active_at' => null]);
    Transaction::factory()->for($user)->create();

    artisan('encryption:delete-accounts', ['--dry-run' => true])->assertSuccessful();

    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
