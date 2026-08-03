<?php

use App\Features\RecurringTransactions;
use App\Mail\UpcomingRecurringChargesEmail;
use App\Models\RecurringSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

beforeEach(function () {
    Mail::fake();
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->user->setting()->updateOrCreate(
        ['user_id' => $this->user->id],
        ['notify_upcoming_recurring' => true],
    );
});

it('sends in the language the user reads', function () {
    // Laravel reads HasLocalePreference off the recipient model, so addressing
    // the mail to a plain string renders the body in the app default.
    $this->user->forceFill(['locale' => 'es'])->saveQuietly();
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertQueued(UpcomingRecurringChargesEmail::class, fn ($mail): bool => $mail->locale === 'es');
});

it('emails one digest covering everything due soon', function () {
    RecurringSeries::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    // One message, not one per charge.
    Mail::assertQueuedCount(1);
    Mail::assertQueued(UpcomingRecurringChargesEmail::class, fn ($mail): bool => $mail->series->count() === 3);
});

it('says nothing when the user has not opted in', function () {
    $this->user->setting()->update(['notify_upcoming_recurring' => false]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('ignores charges beyond the lead time', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDays(20),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not remind twice about the same charge', function () {
    // The job runs daily and the lead time is several days, so a naive
    // implementation would re-announce the same charge every morning.
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDays(2),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();
    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertQueuedCount(1);
});

it('speaks again for the following occurrence', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDays(2),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    // Detection moves the series on to its next charge.
    $series->forceFill([
        'next_expected_on' => CarbonImmutable::today()->addDays(3),
    ])->saveQuietly();

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertQueuedCount(2);
});

it('leaves out dismissed and lapsed series', function () {
    RecurringSeries::factory()->ignored()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);
    RecurringSeries::factory()->lapsed()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('stays quiet while the feature is switched off', function () {
    config()->set('recurring.enabled', false);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('skips a user whose flag is off', function () {
    Feature::for($this->user)->deactivate(RecurringTransactions::class);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDay(),
    ]);

    $this->artisan('recurring:remind')->assertSuccessful();

    Mail::assertNothingQueued();
});
