<?php

use App\Jobs\SendUpdateEmailJob;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;

beforeEach(fn () => Queue::fake());

test('it queues a warning email for users with encrypted transactions or accounts', function () {
    $encryptedTransaction = User::factory()->create();
    Transaction::factory()->for($encryptedTransaction)->create(); // description_iv set by default

    $encryptedAccount = User::factory()->create();
    Account::factory()->for($encryptedAccount)->create(['name_iv' => 'abcdef0123456789']);

    $clean = User::factory()->create();
    Transaction::factory()->for($clean)->plaintext()->create();

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, 2);
    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($encryptedTransaction));
    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($encryptedAccount));
    Queue::assertNotPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($clean));
});

test('it never warns a subscribed user even when the subscriptions flag is off', function () {
    config(['subscriptions.enabled' => false]);

    $subscribed = User::factory()->create();
    Transaction::factory()->for($subscribed)->create();
    $subscribed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertNotPushed(SendUpdateEmailJob::class);
});

test('it never warns a trialing or past-due subscriber even when the subscriptions flag is off', function (string $status) {
    config(['subscriptions.enabled' => false]);

    $billed = User::factory()->create();
    Transaction::factory()->for($billed)->create();
    $billed->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}",
        'stripe_status' => $status,
        'stripe_price' => 'price_test123',
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(5) : null,
    ]);

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertNotPushed(SendUpdateEmailJob::class);
})->with(['trialing', 'past_due']);

test('it warns a subscriber who is only in the cancellation grace period', function () {
    config(['subscriptions.enabled' => false]);

    $grace = User::factory()->create();
    Transaction::factory()->for($grace)->create();
    $grace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_grace123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
        'ends_at' => now()->addDays(5),
    ]);

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job) => $job->user->is($grace));
});

test('it queues on the emails queue', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create();

    artisan('encryption:notify-removal', ['--force' => true])->assertSuccessful();

    Queue::assertPushedOn('emails', SendUpdateEmailJob::class);
});

test('dry run sends nothing', function () {
    $user = User::factory()->create();
    Transaction::factory()->for($user)->create();

    artisan('encryption:notify-removal', ['--dry-run' => true])->assertSuccessful();

    Queue::assertNotPushed(SendUpdateEmailJob::class);
});
