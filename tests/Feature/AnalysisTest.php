<?php

use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();

    $this->user = User::factory()->create(['currency_code' => 'USD']);
    $this->actingAs($this->user);

    $this->account = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'USD',
    ]);
});

function makeTransaction(array $attributes = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'currency_code' => 'USD',
        'transaction_date' => now(),
    ], $attributes));
}

test('analysis endpoint requires authentication', function () {
    auth()->logout();

    $this->getJson('/api/analysis?group_by=category')->assertUnauthorized();
});

test('analysis responses are not cached between users', function () {
    $this->getJson('/api/analysis?group_by=category')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('group_by is required and constrained', function () {
    $this->getJson('/api/analysis')->assertStatus(422);
    $this->getJson('/api/analysis?group_by=bogus')->assertStatus(422);
});

test('summary splits income, expense, net and count by sign', function () {
    makeTransaction(['amount' => 100000]);
    makeTransaction(['amount' => 30000]);
    makeTransaction(['amount' => -40000]);

    $this->getJson('/api/analysis?group_by=category')
        ->assertOk()
        ->assertJson([
            'summary' => [
                'income' => 130000,
                'expense' => 40000,
                'net' => 90000,
                'count' => 3,
            ],
        ]);
});

test('groups by category sum signed amounts and sort by magnitude', function () {
    $food = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);
    $rent = Category::factory()->create([
        'user_id' => $this->user->id,
        'type' => CategoryType::Expense,
    ]);

    makeTransaction(['category_id' => $food->id, 'amount' => -2000]);
    makeTransaction(['category_id' => $food->id, 'amount' => -3000]);
    makeTransaction(['category_id' => $rent->id, 'amount' => -90000]);

    $groups = $this->getJson('/api/analysis?group_by=category')
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(2);
    expect($groups[0])->toMatchArray(['key' => $rent->id, 'amount' => -90000, 'count' => 1]);
    expect($groups[1])->toMatchArray(['key' => $food->id, 'amount' => -5000, 'count' => 2]);
});

test('uncategorized transactions group under a null key', function () {
    makeTransaction(['category_id' => null, 'amount' => -1500]);

    $groups = $this->getJson('/api/analysis?group_by=category')
        ->assertOk()
        ->json('groups');

    expect($groups)->toHaveCount(1);
    expect($groups[0]['key'])->toBeNull();
    expect($groups[0]['amount'])->toBe(-1500);
});

test('groups by month bucket transactions by calendar month across years', function () {
    makeTransaction(['amount' => -1000, 'transaction_date' => '2026-01-15']);
    makeTransaction(['amount' => -2000, 'transaction_date' => '2026-01-20']);
    makeTransaction(['amount' => -4000, 'transaction_date' => '2025-01-10']); // different year, same month
    makeTransaction(['amount' => -5000, 'transaction_date' => '2026-02-10']);

    $groups = collect($this->getJson('/api/analysis?group_by=month')
        ->assertOk()
        ->json('groups'))
        ->keyBy('key');

    // All January transactions collapse together regardless of year.
    expect($groups['01']['amount'])->toBe(-7000);
    expect($groups['01']['count'])->toBe(3);
    expect($groups['02']['amount'])->toBe(-5000);
});

test('groups by label count a transaction once per label and bucket unlabeled separately', function () {
    $trip = Label::factory()->create(['user_id' => $this->user->id]);
    $work = Label::factory()->create(['user_id' => $this->user->id]);

    $multi = makeTransaction(['amount' => -6000]);
    $multi->labels()->sync([$trip->id, $work->id]);

    makeTransaction(['amount' => -1000]); // no label

    $groups = collect($this->getJson('/api/analysis?group_by=label')
        ->assertOk()
        ->json('groups'))
        ->keyBy(fn (array $group): string => $group['key'] ?? '__null__');

    expect($groups[$trip->id]['amount'])->toBe(-6000);
    expect($groups[$work->id]['amount'])->toBe(-6000);
    expect($groups['__null__']['amount'])->toBe(-1000);
});

test('filters restrict the analyzed transactions', function () {
    $keep = Category::factory()->create(['user_id' => $this->user->id]);
    $drop = Category::factory()->create(['user_id' => $this->user->id]);

    makeTransaction(['category_id' => $keep->id, 'amount' => -1000]);
    makeTransaction(['category_id' => $drop->id, 'amount' => -9000]);

    $response = $this->getJson('/api/analysis?'.http_build_query([
        'group_by' => 'category',
        'category_ids' => $keep->id,
    ]))->assertOk();

    expect($response->json('summary.expense'))->toBe(1000);
    expect($response->json('groups'))->toHaveCount(1);
    expect($response->json('groups.0.key'))->toBe($keep->id);
});

test('category and label filters combine with OR', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    makeTransaction(['category_id' => $category->id, 'amount' => -1000]);

    $labelled = makeTransaction(['category_id' => null, 'amount' => -2000]);
    $labelled->labels()->sync([$label->id]);

    makeTransaction(['category_id' => null, 'amount' => -9000]); // matches neither

    $response = $this->getJson('/api/analysis?'.http_build_query([
        'group_by' => 'category',
        'category_ids' => $category->id,
        'label_ids' => $label->id,
    ]))->assertOk();

    expect($response->json('summary.expense'))->toBe(3000);
    expect($response->json('summary.count'))->toBe(2);
});

test('foreign currency amounts are converted to the user currency', function () {
    $date = '2026-03-10';

    $eurAccount = Account::factory()->create([
        'user_id' => $this->user->id,
        'currency_code' => 'EUR',
    ]);

    ExchangeRate::factory()->create([
        'base_currency' => 'usd',
        'date' => $date,
        'rates' => ['eur' => 0.80],
    ]);

    // 8000 EUR cents / 0.80 = 10000 USD cents
    makeTransaction([
        'account_id' => $eurAccount->id,
        'currency_code' => 'EUR',
        'amount' => -8000,
        'transaction_date' => $date,
    ]);

    $this->getJson('/api/analysis?group_by=category')
        ->assertOk()
        ->assertJson(['summary' => ['expense' => 10000]]);
});
