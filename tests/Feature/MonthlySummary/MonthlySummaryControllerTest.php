<?php

use App\Enums\CategoryType;
use App\Models\MonthlySummary;
use App\Models\User;

use function Pest\Laravel\actingAs;

it('shows nothing for a month that has never been asked for', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    actingAs($user)
        ->get('/monthly-summary')
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->component('monthly-summary/index')
            ->where('summary', null)
            ->where('period', now()->subMonth()->format('Y-m')));
});

it('freezes the month it is asked for and then serves it back', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $month = now()->subMonth()->startOfMonth();
    transactionIn($user, CategoryType::Expense, -50000, $month->copy()->addDays(4));

    actingAs($user)
        ->post('/monthly-summary', ['period' => $month->format('Y-m')])
        ->assertRedirect();

    expect(MonthlySummary::query()->count())->toBe(1);

    actingAs($user)
        ->get('/monthly-summary?period='.$month->format('Y-m'))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page
            ->where('summary.payload.cashflow.expense', 50000));
});

it('refuses a month that has not ended', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    actingAs($user)
        ->post('/monthly-summary', ['period' => now()->format('Y-m')])
        ->assertSessionHasErrors('period');

    expect(MonthlySummary::query()->count())->toBe(0);
});

it('says so rather than freezing an empty month', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);

    actingAs($user)
        ->from('/monthly-summary')
        ->post('/monthly-summary', ['period' => now()->subMonth()->format('Y-m')])
        ->assertRedirect('/monthly-summary')
        ->assertSessionHas('error');

    expect(MonthlySummary::query()->count())->toBe(0);
});

it('will not let one user analyse another user\'s month', function () {
    $owner = User::factory()->onboarded()->create();
    $stranger = User::factory()->onboarded()->create();
    $summary = MonthlySummary::factory()->create(['user_id' => $owner->id]);

    actingAs($stranger)
        ->post("/monthly-summary/{$summary->id}/analysis")
        ->assertForbidden();
});
