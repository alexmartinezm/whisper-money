<?php

use App\Models\Account;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Four monthly charges from one counterparty, the most recent a few days ago.
 */
function seedMonthlySubscription(User $user, string $creditor = 'Netflix'): void
{
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => null,
            'creditor_name' => $creditor,
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -1299,
            'currency_code' => 'EUR',
        ]);
    }
}

it('detects series for every user', function () {
    $first = User::factory()->create();
    $second = User::factory()->create();
    seedMonthlySubscription($first, 'Netflix');
    seedMonthlySubscription($second, 'Spotify');

    $this->artisan('recurring:detect')
        ->expectsOutputToContain('Scanned 2 user(s), recorded 2 recurring series.')
        ->assertSuccessful();

    expect(RecurringSeries::query()->where('user_id', $first->id)->count())->toBe(1)
        ->and(RecurringSeries::query()->where('user_id', $second->id)->count())->toBe(1);
});

it('can be restricted to a single user', function () {
    $target = User::factory()->create();
    $other = User::factory()->create();
    seedMonthlySubscription($target, 'Netflix');
    seedMonthlySubscription($other, 'Spotify');

    $this->artisan('recurring:detect', ['--user' => $target->id])->assertSuccessful();

    expect(RecurringSeries::query()->where('user_id', $target->id)->count())->toBe(1)
        ->and(RecurringSeries::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('succeeds when a user has no transactions', function () {
    User::factory()->create();

    $this->artisan('recurring:detect')->assertSuccessful();

    expect(RecurringSeries::query()->count())->toBe(0);
});
