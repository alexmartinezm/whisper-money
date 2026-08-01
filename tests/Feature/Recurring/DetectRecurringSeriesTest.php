<?php

use App\Enums\CategoryType;
use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Account;
use App\Models\Category;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Recurring\DetectRecurringSeries;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->detector = app(DetectRecurringSeries::class);
    $this->user = User::factory()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);
    $this->category = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
});

/**
 * Create one occurrence per month, ending $monthsAgoEnd months before today.
 *
 * @param  list<int>|null  $amounts  one amount per occurrence, defaulting to a flat fee
 * @return list<Transaction>
 */
function monthlyCharges(
    User $user,
    Account $account,
    string $creditor,
    int $count = 4,
    int $amount = -1299,
    int $monthsAgoEnd = 0,
    ?array $amounts = null,
    ?string $categoryId = null,
    string $currency = 'EUR',
): array {
    $anchor = CarbonImmutable::today()->subMonths($monthsAgoEnd)->subDays(3);

    return collect(range(0, $count - 1))
        ->map(fn (int $index): Transaction => Transaction::factory()->plaintext()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $categoryId,
            'creditor_name' => $creditor,
            'transaction_date' => $anchor->subMonths($count - 1 - $index)->toDateString(),
            'amount' => $amounts[$index] ?? $amount,
            'currency_code' => $currency,
        ]))
        ->all();
}

it('detects a monthly subscription', function () {
    monthlyCharges($this->user, $this->account, 'Netflix', categoryId: $this->category->id);

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->display_name)->toBe('Netflix')
        ->and($series->cadence)->toBe(RecurringCadence::Monthly)
        ->and($series->expected_amount)->toBe(-1299)
        ->and($series->amount_is_variable)->toBeFalse()
        ->and($series->direction)->toBe('expense')
        ->and($series->occurrence_count)->toBe(4)
        ->and($series->status)->toBe(RecurringSeriesStatus::Active)
        ->and($series->user_state)->toBe(RecurringSeriesUserState::Detected)
        ->and($series->category_id)->toBe($this->category->id)
        ->and($series->account_id)->toBe($this->account->id)
        ->and($series->transactions)->toHaveCount(4);
});

it('projects the next charge from the last occurrence', function () {
    monthlyCharges($this->user, $this->account, 'Spotify');

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->next_expected_on->toDateString())
        ->toBe($series->last_occurred_on->addDays($series->interval_days)->toDateString());
});

it('detects a yearly series', function () {
    // Regression: the lookback window has to span min_occurrences of the
    // slowest cadence. At thirteen months a yearly series needed more history
    // than detection was allowed to read, so Yearly could never be reached.
    $anchor = CarbonImmutable::today()->subDays(10);

    foreach ([2, 1, 0] as $yearsAgo) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => 'Dominio Anual',
            'transaction_date' => $anchor->subYears($yearsAgo)->toDateString(),
            'amount' => -1200,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(1);

    $series = RecurringSeries::query()->sole();

    expect($series->cadence)->toBe(RecurringCadence::Yearly)
        ->and($series->occurrence_count)->toBe(3)
        ->and($series->status)->toBe(RecurringSeriesStatus::Active);
});

it('flags a series whose amount moves as variable', function () {
    monthlyCharges($this->user, $this->account, 'Endesa', amounts: [-4000, -9500, -6200, -12000]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->amount_is_variable)->toBeTrue();
});

it('keeps a steady series marked as fixed', function () {
    monthlyCharges($this->user, $this->account, 'Netflix', amounts: [-1299, -1299, -1299, -1350]);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->amount_is_variable)->toBeFalse();
});

it('marks a series that stopped billing as lapsed', function () {
    monthlyCharges($this->user, $this->account, 'Gym', monthsAgoEnd: 4);

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('ignores a merchant seen fewer than three times', function () {
    monthlyCharges($this->user, $this->account, 'Rare Shop', count: 2);

    expect($this->detector->forUser($this->user))->toBe(0);
    expect(RecurringSeries::query()->count())->toBe(0);
});

it('ignores transactions whose description is client-encrypted', function () {
    // No creditor name, so grouping would fall back to the description — which
    // the server cannot read for these rows.
    $anchor = CarbonImmutable::today()->subDays(3);

    foreach (range(0, 3) as $index) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $this->category->id,
            'creditor_name' => null,
            'description' => 'ciphertext',
            'description_iv' => 'abcdefghijklmnop',
            'transaction_date' => $anchor->subMonths(3 - $index)->toDateString(),
            'amount' => -1299,
            'currency_code' => 'EUR',
        ]);
    }

    expect($this->detector->forUser($this->user))->toBe(0);
});

it('attributes a split charge to its largest line', function () {
    $food = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);

    $charges = monthlyCharges($this->user, $this->account, 'Mercadona', amount: -10000);

    foreach ($charges as $charge) {
        $charge->splits()->createMany([
            ['category_id' => $food->id, 'amount' => -7000, 'position' => 0],
            ['category_id' => $home->id, 'amount' => -3000, 'position' => 1],
        ]);
        $charge->forceFill(['category_id' => null])->save();
    }

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->category_id)->toBe($food->id)
        ->and($series->expected_amount)->toBe(-10000);
});

it('keeps income and expense from the same counterparty apart', function () {
    monthlyCharges($this->user, $this->account, 'Acme', amount: -5000);
    monthlyCharges($this->user, $this->account, 'Acme', amount: 250000);

    expect($this->detector->forUser($this->user))->toBe(2);

    expect(RecurringSeries::query()->where('direction', 'income')->sole()->expected_amount)->toBe(250000)
        ->and(RecurringSeries::query()->where('direction', 'expense')->sole()->expected_amount)->toBe(-5000);
});

it('keeps the same merchant billed in two currencies apart', function () {
    monthlyCharges($this->user, $this->account, 'Adobe', amount: -1999, currency: 'EUR');
    monthlyCharges($this->user, $this->account, 'Adobe', amount: -2499, currency: 'USD');

    expect($this->detector->forUser($this->user))->toBe(2);
    expect(RecurringSeries::query()->pluck('currency_code')->sort()->values()->all())
        ->toBe(['EUR', 'USD']);
});

it('does not duplicate series when detection runs again', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');

    $this->detector->forUser($this->user);
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->count())->toBe(1)
        ->and(RecurringSeries::query()->sole()->transactions)->toHaveCount(4);
});

it('preserves a dismissed state and a renamed series across runs', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    RecurringSeries::query()->sole()->update([
        'user_state' => RecurringSeriesUserState::Ignored,
        'display_name' => 'Streaming',
    ]);

    $this->detector->forUser($this->user);
    $series = RecurringSeries::query()->sole();

    expect($series->user_state)->toBe(RecurringSeriesUserState::Ignored)
        ->and($series->display_name)->toBe('Streaming');
});

it('lapses a series the ledger no longer substantiates', function () {
    $charges = monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    Transaction::query()->whereIn('id', collect($charges)->pluck('id'))->forceDelete();
    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->sole()->status)->toBe(RecurringSeriesStatus::Lapsed);
});

it('does not resurrect a series the user deleted', function () {
    monthlyCharges($this->user, $this->account, 'Netflix');
    $this->detector->forUser($this->user);

    RecurringSeries::query()->sole()->delete();

    expect($this->detector->forUser($this->user))->toBe(0);
    expect(RecurringSeries::query()->count())->toBe(0)
        ->and(RecurringSeries::withTrashed()->count())->toBe(1);
});

it('does not leak series between users', function () {
    $other = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $other->id, 'currency_code' => 'EUR']);

    monthlyCharges($this->user, $this->account, 'Netflix');
    monthlyCharges($other, $otherAccount, 'Disney');

    $this->detector->forUser($this->user);

    expect(RecurringSeries::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and(RecurringSeries::query()->where('user_id', $other->id)->count())->toBe(0);
});

it('ignores transactions older than the lookback window', function () {
    config()->set('recurring.lookback_months', 3);

    monthlyCharges($this->user, $this->account, 'Netflix', count: 4, monthsAgoEnd: 10);

    expect($this->detector->forUser($this->user))->toBe(0);
});
