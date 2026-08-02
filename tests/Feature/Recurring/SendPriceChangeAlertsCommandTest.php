<?php

use App\Enums\AccountType;
use App\Features\RecurringTransactions;
use App\Mail\RecurringPriceChangesEmail;
use App\Models\Account;
use App\Models\User;
use App\Services\Recurring\DetectPriceChanges;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->user->setting()->updateOrCreate(
        ['user_id' => $this->user->id],
        ['notify_price_changes' => true],
    );
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'space_id' => $this->user->personalSpace->id,
        'type' => AccountType::Checking,
        'currency_code' => 'EUR',
    ]);
});

it('emails one digest covering every rise', function () {
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599], 'Netflix');
    seriesCharging($this->user, $this->account, [999, 999, 999, 1299, 1299, 1299], 'Spotify');

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    // One message, not one per subscription.
    Mail::assertQueuedCount(1);
    Mail::assertQueued(RecurringPriceChangesEmail::class, fn ($mail): bool => $mail->rises->count() === 2);
});

it('does not announce the same rise twice', function () {
    // The command runs on a schedule and the rise stays visible in the history
    // forever, so a naive implementation would repeat itself every week.
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();
    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('records the new price on the series', function () {
    $series = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    expect($series->fresh()->price_alerted_amount)->toBe(1599);
});

it('says nothing when nothing went up', function () {
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1299, 1299, 1299]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('says nothing when the user has not opted in', function () {
    $this->user->setting->update(['notify_price_changes' => false]);
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('stays quiet while the feature is switched off', function () {
    config()->set('recurring.enabled', false);
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('skips a user whose flag is off', function () {
    Feature::for($this->user)->deactivate(RecurringTransactions::class);
    seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);

    $this->artisan('recurring:alert-price-changes')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('renders the digest with both prices and the yearly impact', function () {
    // Mail::fake() never runs the template, so without this the only things
    // reading previousAmount, percentage() and yearlyImpact() would be Blade
    // expressions nothing exercises until an email goes out for real.
    $series = seriesCharging($this->user, $this->account, [1299, 1299, 1299, 1599, 1599, 1599]);
    $rises = app(DetectPriceChanges::class)->forUser($this->user);

    $html = (new RecurringPriceChangesEmail($this->user, $rises))->render();

    expect($html)->toContain($series->display_name)
        ->toContain('12.99')
        ->toContain('15.99')
        ->toContain('23%')
        // 3.00 more a month is 36.00 a year.
        ->toContain('36.00');
});
