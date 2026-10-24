<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->user = User::factory()->onboarded()->create();
    $this->account = Account::factory()->create(['user_id' => $this->user->id]);
});

test('index returns paginated transactions as Inertia props', function () {
    Transaction::factory()->plaintext()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('transactions/index')
        ->has('transactions.data', 3)
        ->has('appliedFilters')
        ->where('appliedFilters.sort', '-transaction_date')
    );
});

test('transactions include eager-loaded relationships', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);
    $transaction->labels()->attach($label->id);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data.0.account')
        ->has('transactions.data.0.category')
        ->has('transactions.data.0.labels', 1)
    );
});

test('filter by date range', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('date range includes its first and last day', function () {
    foreach (['2025-05-31', '2025-06-01', '2025-06-30', '2025-07-01'] as $date) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'transaction_date' => $date,
        ]);
    }

    actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
    ]))->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', '2025-06-30')
        ->where('transactions.data.1.transaction_date', '2025-06-01')
    );
});

test('date range scope keeps the whole boundary day when a bound carries a time', function () {
    foreach (['2025-05-31', '2025-06-01', '2025-06-30', '2025-07-01'] as $date) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'transaction_date' => $date,
        ]);
    }

    // Saved filters and queued re-evaluations carry whatever was stored when
    // they were written, so the scope cannot rely on the request validator.
    $dates = Transaction::query()
        ->applyFilters(['date_from' => '2025-06-01 18:30:00', 'date_to' => '2025-06-30T09:15:00'])
        ->orderBy('transaction_date')
        ->pluck('transaction_date')
        ->map->toDateString()
        ->all();

    expect($dates)->toBe(['2025-06-01', '2025-06-30']);
});

test('date range scope matches nothing for an unparseable bound instead of throwing or dropping it', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    expect(Transaction::query()->applyFilters(['date_from' => 'not-a-date'])->count())->toBe(0);
});

test('a date range lets transactions be read by index instead of scanning the user\'s rows', function () {
    // A handful of rows makes any plan a full scan, so this checks what the
    // index can do for the query rather than which one MySQL picks. The index
    // leads with user_id, so it is on offer either way; what the date filter
    // decides is whether the range narrows the lookup past user_id. Forcing
    // the index makes the plan show which of its parts the lookup reaches.
    Transaction::factory()->plaintext()->count(3)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    $query = Transaction::query()
        ->where('user_id', $this->user->id)
        ->applyFilters(['date_from' => '2025-06-01', 'date_to' => '2025-06-30']);

    $plan = collect(DB::select('explain '.$query->toSql(), $query->getBindings()))
        ->firstWhere('table', 'transactions');

    $forced = (clone $query)->forceIndex('idx_transactions_budget_lookup');
    $access = json_decode(
        DB::selectOne('explain format=json '.$forced->toSql(), $forced->getBindings())->EXPLAIN,
        true,
    )['query_block']['table'];

    expect(explode(',', (string) $plan->possible_keys))->toContain('idx_transactions_budget_lookup')
        ->and($access['access_type'])->toBe('range')
        ->and($access['used_key_parts'])->toBe(['user_id', 'transaction_date']);
});

test('a split transaction on a range boundary day is filtered by its lines', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id]);
    $household = Category::factory()->create(['user_id' => $this->user->id]);

    $splitOn = function (string $date) use ($groceries, $household): Transaction {
        $transaction = Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => null,
            'amount' => -5000,
            'transaction_date' => $date,
        ]);

        foreach ([[$groceries, -3000], [$household, -2000]] as $position => [$category, $amount]) {
            TransactionSplit::factory()->create([
                'transaction_id' => $transaction->id,
                'category_id' => $category->id,
                'amount' => $amount,
                'position' => $position,
            ]);
        }

        return $transaction;
    };

    $firstDay = $splitOn('2025-06-01');
    $lastDay = $splitOn('2025-06-30');
    $splitOn('2025-05-31');
    $splitOn('2025-07-01');

    $ids = Transaction::query()
        ->applyFilters([
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-30',
            'category_ids' => [$groceries->id],
            'user_id' => $this->user->id,
        ])
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($ids)->toBe(collect([$firstDay->id, $lastDay->id])->sort()->values()->all());
});

test('rejects a date filter that is not a plain Y-m-d date', function (string $date) {
    // `date` accepts every one of these - it only asks strtotime() to parse the
    // string and checkdate() to like the pieces, so "275752-07-04" is a date in
    // year 275752 and "01/06/2025" is whichever of June and January PHP feels
    // like. The raw string then reached both the SQL and the Inertia props, where
    // the client handed it to date-fns `format()` and the page died mid-render.
    // The UI only ever sends the output of a `date` input, so nothing legitimate
    // is turned away.
    actingAs($this->user)
        ->get(route('transactions.index', ['date_from' => $date]))
        ->assertSessionHasErrors('date_from');
})->with([
    '275752-07-04',
    '01/06/2025',
    '15-06-2026',
    '2026-6-5',
    '2025-06-15T00:00:00Z',
]);

test('filter by amount range', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -5000, // -$50.00
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -20000, // -$200.00
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'amount_min' => -100,
        'amount_max' => -10,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filter by category', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filtering by a parent category includes transactions from its descendants', function () {
    $parent = Category::factory()->create(['user_id' => $this->user->id]);
    $child = Category::factory()->childOf($parent)->create(['user_id' => $this->user->id]);
    $grandchild = Category::factory()->childOf($child)->create(['user_id' => $this->user->id]);
    $unrelated = Category::factory()->create(['user_id' => $this->user->id]);

    foreach ([$parent, $child, $grandchild, $unrelated] as $category) {
        Transaction::factory()->plaintext()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'category_id' => $category->id,
        ]);
    }

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $parent->id,
    ]));

    // Parent + child + grandchild, but not the unrelated category.
    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 3)
    );
});

test('filter by uncategorized', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => 'uncategorized',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.category_id', null)
    );
});

test('filter by account', function () {
    $otherAccount = Account::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $otherAccount->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'account_ids' => $this->account->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('filter by label', function () {
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $txWithLabel = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $txWithLabel->labels()->attach($label->id);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'label_ids' => $label->id,
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('category and label filters combine with OR', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $label = Label::factory()->create(['user_id' => $this->user->id]);

    $txWithCategory = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    $txWithLabel = Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);
    $txWithLabel->labels()->attach($label->id);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id,
        'label_ids' => $label->id,
    ]));

    $ids = collect($response->viewData('page')['props']['transactions']['data'])->pluck('id');

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($txWithCategory->id)
        ->and($ids)->toContain($txWithLabel->id);
});

test('search matches description', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Grocery Store Purchase',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Gas Station',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'Grocery',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.description', 'Grocery Store Purchase')
    );
});

test('filter by creditor name', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'Amazon EU',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'creditor_name' => 'Amazon',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.creditor_name', 'Amazon EU')
        ->where('appliedFilters.creditor_name', 'Amazon')
    );
});

test('filter by debtor name', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'debtor_name' => 'Payroll GmbH',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'debtor_name' => 'Other Sender',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'debtor_name' => 'Payroll',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.debtor_name', 'Payroll GmbH')
        ->where('appliedFilters.debtor_name', 'Payroll')
    );
});

test('search matches counterparty names', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Card payment',
        'creditor_name' => 'Amazon EU',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Card payment',
        'creditor_name' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'Amazon',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->where('transactions.data.0.creditor_name', 'Amazon EU')
    );
});

test('search matches notes', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Some purchase',
        'notes' => 'weekly groceries for the family',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Another purchase',
        'notes' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'groceries',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('combined filters work', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    // Matches all filters
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-06-15',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong date
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
        'transaction_date' => '2025-01-01',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    // Wrong category
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
        'transaction_date' => '2025-06-15',
        'amount' => -5000,
        'description' => 'Target purchase',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'date_from' => '2025-06-01',
        'date_to' => '2025-06-30',
        'category_ids' => $category->id,
        'search' => 'Target',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('sort by date ascending', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'transaction_date',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', fn ($date) => str_contains($date, '2025-01-01'))
    );
});

test('sort by date descending (default)', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-06-15',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'transaction_date' => '2025-01-01',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.transaction_date', fn ($date) => str_contains($date, '2025-06-15'))
    );
});

test('sort by amount', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => -10000,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'amount' => 5000,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'amount',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.amount', -10000)
    );
});

test('sort by description', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Zebra Store',
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Apple Store',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'description',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
        ->where('transactions.data.0.description', 'Apple Store')
    );
});

test('cursor pagination returns correct results', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->count(25)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'per_page' => 10,
    ]));

    $nextCursor = null;

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 10)
        ->where('transactions.next_cursor', function ($cursor) use (&$nextCursor) {
            $nextCursor = $cursor;

            return $cursor !== null;
        })
    );

    // Fetch the next page using the cursor
    $response2 = actingAs($this->user)->get(route('transactions.index', [
        'per_page' => 10,
        'cursor' => $nextCursor,
    ]));

    $response2->assertInertia(fn ($page) => $page
        ->has('transactions.data', 10)
    );
});

test('empty results when no matches', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'description' => 'Coffee Shop',
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'nonexistent_query_string',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 0)
    );
});

test('user scoping - cannot see other users transactions', function () {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->create(['user_id' => $otherUser->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $otherUser->id,
        'account_id' => $otherAccount->id,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index'));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
    );
});

test('applied filters are returned in response', function () {
    $response = actingAs($this->user)->get(route('transactions.index', [
        'search' => 'test',
        'date_from' => '2025-01-01',
        'sort' => '-amount',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->where('appliedFilters.search', 'test')
        ->where('appliedFilters.date_from', '2025-01-01')
        ->where('appliedFilters.sort', '-amount')
    );
});

test('filter by multiple categories including uncategorized', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => $category->id,
    ]);

    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'category_id' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'category_ids' => $category->id.',uncategorized',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 2)
    );
});

test('paginates across pages when sorting by a nullable column with null values', function () {
    Transaction::factory()->plaintext()->count(12)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => null,
    ]);

    Transaction::factory()->plaintext()->count(5)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => 'ACME',
    ]);

    $firstPage = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'creditor_name',
        'per_page' => 10,
    ]));

    $firstPage->assertSuccessful();

    $nextPageUrl = $firstPage->getOriginalContent()->getData()['page']['props']['transactions']['next_page_url'];

    expect($nextPageUrl)->not->toBeNull();

    actingAs($this->user)->get($nextPageUrl)->assertSuccessful();
});

test('does not expose the sort alias attribute when sorting by a nullable column', function () {
    Transaction::factory()->plaintext()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'creditor_name' => null,
    ]);

    $response = actingAs($this->user)->get(route('transactions.index', [
        'sort' => 'creditor_name',
    ]));

    $response->assertInertia(fn ($page) => $page
        ->has('transactions.data', 1)
        ->missing('transactions.data.0.creditor_name_sort')
    );
});

test('a positive amount range only matches income, so small expenses need the negative range', function () {
    foreach ([-1500, -2500, -9000, 2000] as $amount) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'account_id' => $this->account->id,
            'currency_code' => 'USD',
            'amount' => $amount,
        ]);
    }

    actingAs($this->user)->get(route('transactions.index', ['amount_min' => 0, 'amount_max' => 30]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.amount', 2000));

    actingAs($this->user)->get(route('transactions.index', ['amount_min' => -30, 'amount_max' => 0]))
        ->assertInertia(fn ($page) => $page
            ->has('transactions.data', 2)
            ->where('appliedFilters.amount_min', -30)
            ->where('appliedFilters.amount_max', 0));
});
