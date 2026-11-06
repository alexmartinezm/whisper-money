<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Models\Account;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\TransactionFilterSummary;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
    $this->groceries = Category::factory()->create(['user_id' => $this->user->id]);
});

function summaryTransaction(string $date, int $amount, string $currency = 'EUR', array $attributes = []): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => test()->user->id,
        'account_id' => test()->account->id,
        'category_id' => test()->groceries->id,
        'transaction_date' => $date,
        'amount' => $amount,
        'currency_code' => $currency,
        ...$attributes,
    ]);
}

/**
 * @return array<string, mixed>
 */
function filterSummaryFor(array $query): array
{
    return app(TransactionFilterSummary::class)->summarize([...$query, 'user_id' => test()->user->id], test()->user->currency_code);
}

/**
 * @param  list<array{0: Category, 1: int}>  $lines
 */
function summarySplitTransaction(string $date, array $lines, string $currency = 'EUR'): Transaction
{
    $transaction = summaryTransaction($date, array_sum(array_column($lines, 1)), $currency, ['category_id' => null]);

    foreach ($lines as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

/**
 * What the headers of the page these filters load add up to.
 *
 * @param  array<string, mixed>  $query
 */
function sumOfDayHeaders(array $query): int
{
    $dayTotals = actingAs(test()->user)
        ->get(route('transactions.index', $query))
        ->viewData('page')['props']['dayTotals'];

    return array_sum(array_column($dayTotals, 'total'));
}

test('it counts, adds up and averages every transaction the filters match', function () {
    summaryTransaction('2025-11-12', -1500);
    summaryTransaction('2025-11-11', -1000);
    summaryTransaction('2025-10-02', -500);
    summaryTransaction('2025-11-12', -9900, attributes: ['category_id' => null]);

    $summary = filterSummaryFor(['category_ids' => [$this->groceries->id]]);

    expect($summary)
        ->count->toBe(3)
        ->total->toBe(-3000)
        ->average->toBe(-1000)
        ->kind->toBe('expense')
        ->currency->toBe('EUR');
});

test('it covers every matching row, not only the first page', function () {
    foreach (range(1, 12) as $day) {
        summaryTransaction(sprintf('2025-11-%02d', $day), -100);
    }

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(12)
        ->total->toBe(-1200);
});

test('the kind says whether the matching rows are all expenses, all income or both', function (array $amounts, string $kind) {
    foreach ($amounts as $amount) {
        summaryTransaction('2025-11-12', $amount);
    }

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]])['kind'])->toBe($kind);
})->with([
    'expenses' => [[-100, -200], 'expense'],
    'income' => [[100, 200], 'income'],
    'both' => [[-100, 200], 'mixed'],
    'nothing' => [[], 'mixed'],
]);

test('a mixed set totals the net figure', function () {
    summaryTransaction('2025-11-12', -1000);
    summaryTransaction('2025-11-13', 4000);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->total->toBe(3000)
        ->average->toBe(1500);
});

test('it converts each currency once per day, as the day totals do', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 3.0],
    ]);
    // Converted one by one, each row rounds to -33 and the total to -99.
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -100, 'USD');
    summaryTransaction('2025-11-12', -500);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(4)
        ->total->toBe(-600)
        ->average->toBe(-150);
});

test('an amount with no rate leaves the total and the average out', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0],
    ]);
    summaryTransaction('2025-11-12', -500);
    summaryTransaction('2025-11-12', -100, 'GBP');

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(2)
        ->total->toBeNull()
        ->average->toBeNull();
});

test('the trend totals the same filters month by month over the six months the filter ends in', function () {
    summaryTransaction('2025-06-30', -999);
    summaryTransaction('2025-07-01', -100);
    summaryTransaction('2025-09-15', -200);
    summaryTransaction('2025-09-20', -300);
    summaryTransaction('2025-12-31', -400);
    summaryTransaction('2026-01-01', -999);
    summaryTransaction('2025-09-15', -999, attributes: ['category_id' => null]);

    $summary = filterSummaryFor([
        'category_ids' => [$this->groceries->id],
        'date_from' => '2025-12-01',
        'date_to' => '2025-12-31',
    ]);

    expect($summary['count'])->toBe(1)
        ->and($summary['trend'])->toBe([
            ['month' => '2025-07', 'total' => -100],
            ['month' => '2025-08', 'total' => 0],
            ['month' => '2025-09', 'total' => -500],
            ['month' => '2025-10', 'total' => 0],
            ['month' => '2025-11', 'total' => 0],
            ['month' => '2025-12', 'total' => -400],
        ]);
});

test('without an end date the trend ends this month', function () {
    Carbon::setTestNow('2026-03-10');
    summaryTransaction('2026-03-02', -700);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]])['trend'])
        ->toHaveCount(6)
        ->sequence(
            fn ($month) => $month->month->toBe('2025-10'),
            fn ($month) => $month->month->toBe('2025-11'),
            fn ($month) => $month->month->toBe('2025-12'),
            fn ($month) => $month->month->toBe('2026-01'),
            fn ($month) => $month->month->toBe('2026-02'),
            fn ($month) => $month->toBe(['month' => '2026-03', 'total' => -700]),
        );
});

test('under a category filter a split transaction adds only its matching lines, and counts once', function () {
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -3000], [$this->groceries, -1000], [$home, -7000]]);
    summaryTransaction('2025-11-12', -500);
    summaryTransaction('2025-11-12', -9900, attributes: ['category_id' => $home->id]);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(2)
        ->total->toBe(-4500)
        ->average->toBe(-2250);
});

test('the trend adds the same split lines month by month', function () {
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-10-12', [[$this->groceries, -3000], [$home, -7000]]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -400], [$home, -600]]);

    $trend = filterSummaryFor([
        'category_ids' => [$this->groceries->id],
        'date_from' => '2025-11-01',
        'date_to' => '2025-11-30',
    ])['trend'];

    expect(array_slice($trend, -2))->toBe([
        ['month' => '2025-10', 'total' => -3000],
        ['month' => '2025-11', 'total' => -400],
    ]);
});

test('a split transaction in another currency converts only its matching lines', function () {
    ExchangeRate::factory()->create([
        'base_currency' => 'eur',
        'date' => '2025-11-12',
        'rates' => ['eur' => 1.0, 'usd' => 2.0],
    ]);
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -4000], [$home, -6000]], 'USD');

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(1)
        ->total->toBe(-2000);
});

test('without a line filter a split transaction stands at its own amount', function () {
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -3000], [$home, -7000]]);

    expect(filterSummaryFor(['date_from' => '2025-11-01', 'date_to' => '2025-11-30']))
        ->count->toBe(1)
        ->total->toBe(-10000)
        ->kind->toBe('expense');
});

test('a label on a split transaction keeps all its lines', function () {
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    $trip = Label::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -3000], [$home, -7000]])->labels()->attach($trip);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -100], [$home, -200]]);

    expect(filterSummaryFor(['label_ids' => [$trip->id]]))
        ->count->toBe(1)
        ->total->toBe(-10000);
});

test('the kind reads the lines a split transaction keeps, not its own amount', function () {
    $refunds = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -5000], [$refunds, 1500]]);

    expect(filterSummaryFor(['category_ids' => [$refunds->id]]))
        ->total->toBe(1500)
        ->kind->toBe('income');
});

test('a split transaction listed for its own category counts but adds no line and no kind', function () {
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$home, -7000]])->update(['category_id' => $this->groceries->id]);
    summaryTransaction('2025-11-12', 500);

    expect(filterSummaryFor(['category_ids' => [$this->groceries->id]]))
        ->count->toBe(2)
        ->total->toBe(500)
        ->average->toBe(250)
        ->kind->toBe('income');
});

test('the total agrees with the day headers and the analysis drawer on split lines', function (callable $filtersFor, int $expected) {
    $this->account->update(['type' => AccountType::Checking]);
    $this->groceries->update(['type' => CategoryType::Expense]);
    $home = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -3000], [$home, -7000]]);
    summarySplitTransaction('2025-11-10', [[$this->groceries, -400], [$home, -600]]);
    summaryTransaction('2025-11-11', -500);

    $query = [...$filtersFor($this->groceries), 'date_from' => '2025-11-01', 'date_to' => '2025-11-30'];

    $summary = filterSummaryFor($query);
    $analysis = actingAs($this->user)->getJson(route('api.transactions.analysis', $query))->assertOk();

    expect($summary['total'])->toBe($expected)
        ->and(sumOfDayHeaders($query))->toBe($expected)
        ->and($analysis->json('summary.net'))->toBe($expected)
        ->and(last($summary['trend']))->toBe(['month' => '2025-11', 'total' => $expected]);
})->with([
    'a category filter' => [fn (Category $groceries): array => ['category_ids' => [$groceries->id]], -3900],
    'a cashflow block filter' => [fn (): array => ['cashflow_block' => 'general'], -3900],
]);

test('a cashflow block is read over the trend\'s whole window, each month on its lines', function () {
    $this->account->update(['type' => AccountType::Checking]);
    $savings = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Savings]);
    $this->groceries->update(['type' => CategoryType::Expense]);
    summarySplitTransaction('2025-10-12', [[$savings, -3000], [$this->groceries, -7000]]);
    summarySplitTransaction('2025-11-12', [[$savings, -400], [$this->groceries, -600]]);

    $summary = filterSummaryFor(['date_from' => '2025-11-01', 'date_to' => '2025-11-30', 'cashflow_block' => 'saved']);

    expect($summary['total'])->toBe(-400)
        ->and(array_slice($summary['trend'], -2))->toBe([
            ['month' => '2025-10', 'total' => -3000],
            ['month' => '2025-11', 'total' => -400],
        ]);
});

test('a provider outage costs one bounded attempt per figure, and leaves those figures out', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    });

    summaryTransaction('2025-11-12', -1000, 'USD');
    summaryTransaction('2025-11-11', -1000, 'USD');
    summaryTransaction('2025-11-10', -1000, 'USD');
    summaryTransaction('2025-10-10', -700);

    $summary = filterSummaryFor([
        'category_ids' => [$this->groceries->id],
        'date_from' => '2025-11-01',
        'date_to' => '2025-11-30',
    ]);

    expect($summary['count'])->toBe(3)
        ->and($summary['total'])->toBeNull()
        ->and($summary['average'])->toBeNull()
        ->and(array_slice($summary['trend'], -2))->toBe([
            ['month' => '2025-10', 'total' => -700],
            ['month' => '2025-11', 'total' => null],
        ])
        ->and($calls)->toBe(4)
        ->and(ExchangeRate::query()->count())->toBe(0);
});

test('the listed count is the rows the list shows, and asks nothing of the provider', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $home = Category::factory()->create(['user_id' => $this->user->id]);
    summarySplitTransaction('2025-11-12', [[$this->groceries, -3000], [$this->groceries, -1000], [$home, -7000]]);
    summaryTransaction('2025-11-12', -1000, 'USD');
    $query = ['category_ids' => [$this->groceries->id]];

    $listed = actingAs($this->user)->get(route('transactions.index', $query))->viewData('page')['props']['transactions']['data'];
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    actingAs($this->user)
        ->getJson(route('api.transactions.listed-count', $query))
        ->assertOk()
        ->assertExactJson(['count' => count($listed)]);

    expect($listed)->toHaveCount(2);
    actingAs($this->user)->getJson(route('api.transactions.count', $query))->assertJsonPath('count', 3);
});

test('the listed count validates its filters like the list does', function () {
    actingAs($this->user)
        ->getJson(route('api.transactions.listed-count', ['date_from' => 'yesterday']))
        ->assertUnprocessable();
});

test('the listed count leaves out other users transactions', function () {
    $stranger = User::factory()->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $stranger->id,
        'account_id' => Account::factory()->create(['user_id' => $stranger->id])->id,
    ]);

    actingAs($this->user)
        ->getJson(route('api.transactions.listed-count'))
        ->assertExactJson(['count' => 0]);
});

test('it leaves out other users transactions', function () {
    summaryTransaction('2025-11-12', -100);
    $stranger = User::factory()->create();
    Transaction::factory()->plaintext()->create([
        'user_id' => $stranger->id,
        'account_id' => Account::factory()->create(['user_id' => $stranger->id])->id,
        'transaction_date' => '2025-11-12',
        'amount' => -99999,
    ]);

    expect(filterSummaryFor(['date_from' => '2025-11-01', 'date_to' => '2025-11-30']))
        ->count->toBe(1)
        ->total->toBe(-100);
});

test('the transactions page defers the summary of a filtered list', function () {
    summaryTransaction('2025-11-12', -1500);

    actingAs($this->user)
        ->get(route('transactions.index', ['category_ids' => [$this->groceries->id]]))
        ->assertInertia(fn ($page) => $page
            ->missing('filterSummary')
            ->loadDeferredProps(fn ($reload) => $reload
                ->where('filterSummary.count', 1)
                ->where('filterSummary.total', -1500)
                ->where('filterSummary.kind', 'expense')
            )
        );
});

test('the transactions page has no summary without a filter', function () {
    summaryTransaction('2025-11-12', -1500);

    actingAs($this->user)
        ->get(route('transactions.index'))
        ->assertInertia(fn ($page) => $page->where('filterSummary', null));
});

test('the AI-only toggle alone does not count as a filter to sum up', function () {
    summaryTransaction('2025-11-12', -1500, attributes: ['category_source' => 'ai']);

    actingAs($this->user)
        ->get(route('transactions.index', ['category_source' => 'ai']))
        ->assertInertia(fn ($page) => $page->where('filterSummary', null));
});
