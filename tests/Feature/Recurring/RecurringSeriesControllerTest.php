<?php

use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesUserState;
use App\Features\RecurringTransactions;
use App\Models\RecurringSeries;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarded_at' => now(), 'currency_code' => 'EUR']);
});

it('renders the recurring page with its series', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'display_name' => 'Netflix',
    ]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('recurring/index')
            ->has('series', 1)
            ->where('series.0.id', $series->id)
            ->where('series.0.display_name', 'Netflix')
            ->has('summary')
            ->has('upcoming'));
});

it('sums monthly cost across cadences in the summary', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1200,
    ]);
    RecurringSeries::factory()->cadence(RecurringCadence::Yearly)->create([
        'user_id' => $this->user->id,
        'expected_amount' => -12000,
    ]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page
            ->has('summary', 1)
            ->where('summary.0.currency_code', 'EUR')
            ->where('summary.0.monthly_expense', -2200)
            ->where('summary.0.yearly_expense', -26400)
            ->where('summary.0.active_count', 2));
});

it('leaves dismissed series out of the totals', function () {
    RecurringSeries::factory()->create(['user_id' => $this->user->id, 'expected_amount' => -1000]);
    RecurringSeries::factory()->ignored()->create(['user_id' => $this->user->id, 'expected_amount' => -9900]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page
            ->where('summary.0.monthly_expense', -1000)
            ->where('summary.0.active_count', 1)
            ->has('series', 2));
});

it('keeps each currency on its own total', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
        'expected_amount' => -5000,
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
        'expected_amount' => -5000,
    ]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page
            ->has('summary', 2)
            // The user's own currency leads.
            ->where('summary.0.currency_code', 'EUR')
            ->where('summary.0.monthly_expense', -5000)
            ->where('summary.1.currency_code', 'USD')
            ->where('summary.1.monthly_expense', -5000));
});

it('leaves lapsed series out of the current totals but keeps them listed', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1000,
    ]);
    RecurringSeries::factory()->lapsed()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -8000,
    ]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page
            ->where('summary.0.monthly_expense', -1000)
            ->where('summary.0.active_count', 1)
            ->has('series', 2));
});

it('lists only charges expected within the next 30 days', function () {
    $soon = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDays(9),
    ]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'next_expected_on' => CarbonImmutable::today()->addDays(70),
    ]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page
            ->has('upcoming', 1)
            ->where('upcoming.0.id', $soon->id));
});

it('returns 404 when the feature is disabled', function () {
    Feature::for($this->user)->deactivate(RecurringTransactions::class);

    $this->actingAs($this->user)->get('/recurring')->assertNotFound();
});

it('lets the user rename a series', function () {
    $series = RecurringSeries::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['display_name' => 'Streaming'])
        ->assertRedirect();

    expect($series->fresh()->display_name)->toBe('Streaming');
});

it('lets the user dismiss and confirm a series', function () {
    $series = RecurringSeries::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['user_state' => 'ignored'])
        ->assertRedirect();
    expect($series->fresh()->user_state)->toBe(RecurringSeriesUserState::Ignored);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['user_state' => 'confirmed']);
    expect($series->fresh()->user_state)->toBe(RecurringSeriesUserState::Confirmed);
});

it('rejects an unknown user state', function () {
    $series = RecurringSeries::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['user_state' => 'archived'])
        ->assertSessionHasErrors('user_state');
});

it('refuses to write derived fields', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'expected_amount' => -1299,
    ]);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['expected_amount' => -1]);

    expect($series->fresh()->expected_amount)->toBe(-1299);
});

it('lets the user delete a series', function () {
    $series = RecurringSeries::factory()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user)
        ->delete("/recurring/{$series->id}")
        ->assertRedirect();

    $this->assertSoftDeleted('recurring_series', ['id' => $series->id]);
});

it('does not let a user touch another user series', function () {
    $other = User::factory()->create(['onboarded_at' => now()]);
    $series = RecurringSeries::factory()->create(['user_id' => $other->id]);

    $this->actingAs($this->user)
        ->patch("/recurring/{$series->id}", ['display_name' => 'Mine now'])
        ->assertForbidden();

    $this->actingAs($this->user)
        ->delete("/recurring/{$series->id}")
        ->assertForbidden();
});

it('does not show another user series on the page', function () {
    $other = User::factory()->create(['onboarded_at' => now()]);
    RecurringSeries::factory()->create(['user_id' => $other->id]);

    $this->actingAs($this->user)
        ->get('/recurring')
        ->assertInertia(fn ($page) => $page->has('series', 0));
});

it('requires authentication', function () {
    // The guest entry point is chosen by AuthEntryPointService, so assert only
    // that an anonymous visitor is bounced rather than pinning the target.
    $this->get('/recurring')->assertRedirect();
});
