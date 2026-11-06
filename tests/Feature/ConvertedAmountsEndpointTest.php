<?php

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

test('it converts every amount into the user currency at its own date', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 4.0, 'gbp' => 0.5],
    ]);

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-11'],
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-12'],
            ['from' => 'GBP', 'amount' => -1000, 'date' => '2025-11-12'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [6000, 3000, -2000]]);
});

test('it answers null for an amount whose day holds no rate for its currency', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-11',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 12000, 'date' => '2025-11-11'],
            ['from' => 'GBP', 'amount' => 12000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [6000, null]]);
});

test('it hands back amounts already in the user currency untouched', function () {
    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'EUR', 'amount' => 12000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [12000]]);

    expect(ExchangeRate::query()->count())->toBe(0);
});

test('a provider outage costs one attempt, however many days are missing a rate', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-09',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-12'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-11'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-10'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-09'],
            ['from' => 'EUR', 'amount' => 1000, 'date' => '2025-11-12'],
        ]])
        ->assertOk()
        // A day with a rate and an amount in the user's currency still answer.
        ->assertExactJson(['amounts' => [null, null, null, 500, 1000]]);

    // The two sources of the first day, and nothing after.
    expect($calls)->toBe(2);
});

test('a provider answering with server errors counts as an outage too', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response('Server Error', 503);
    });

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-12'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [null, null]]);

    expect($calls)->toBe(2);
});

test('one source failing while the other has no release stops the provider too', function () {
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls) {
        $calls++;

        return str_contains($request->url(), 'jsdelivr')
            ? Http::response('Server Error', 500)
            : Http::response('Not Found', 404);
    });

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-12'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-11'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [null, null]]);

    expect($calls)->toBe(2);
});

test('a day the provider has no release for does not stop the days after it', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), '@2025-11-12') || str_contains($request->url(), 'v1/2025-11-12')
        ? Http::response(['eur' => ['usd' => 2.0]])
        : Http::response('Not Found', 404));

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 1000, 'date' => '2021-01-05'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-12'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [null, 500]]);
});

test('it stops asking a slow provider once the budget is spent', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        Carbon::setTestNow(now()->addSeconds(3));

        return Http::response(['eur' => ['usd' => 2.0]]);
    });

    actingAs(User::factory()->onboarded()->create(['currency_code' => 'EUR']))
        ->postJson('/api/converted-amounts', ['items' => [
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-12'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-11'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-10'],
            ['from' => 'USD', 'amount' => 1000, 'date' => '2025-11-09'],
        ]])
        ->assertOk()
        ->assertExactJson(['amounts' => [500, 500, null, null]]);

    expect($calls)->toBe(2);
});

test('it allows thirty requests a minute, whatever else the user calls', function () {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $body = ['items' => [['from' => 'EUR', 'amount' => 100, 'date' => '2025-11-11']]];

    // Well past the limit in other /api routes, which keep their own counter.
    foreach (range(1, 31) as $ignored) {
        actingAs($user)->getJson('/api/accounts')->assertOk();
    }

    foreach (range(1, 30) as $ignored) {
        actingAs($user)->postJson('/api/converted-amounts', $body)->assertOk();
    }

    actingAs($user)->postJson('/api/converted-amounts', $body)->assertTooManyRequests();
});

test('it rejects malformed items', function (array $item) {
    actingAs(User::factory()->onboarded()->create())
        ->postJson('/api/converted-amounts', ['items' => [$item]])
        ->assertUnprocessable();
})->with([
    'date not Y-m-d' => [['from' => 'USD', 'amount' => 1, 'date' => '11/11/2025']],
    'amount not an integer' => [['from' => 'USD', 'amount' => 'ten', 'date' => '2025-11-11']],
    'currency not a code' => [['from' => 'DOLLARS', 'amount' => 1, 'date' => '2025-11-11']],
]);

test('it caps how many amounts one request converts', function () {
    $items = array_fill(0, 501, ['from' => 'USD', 'amount' => 1, 'date' => '2025-11-11']);

    actingAs(User::factory()->onboarded()->create())
        ->postJson('/api/converted-amounts', ['items' => $items])
        ->assertUnprocessable();
});

test('it turns strangers away', function () {
    postJson('/api/converted-amounts', ['items' => []])
        ->assertUnauthorized();
});
