<?php

use App\Http\Controllers\TransactionExportController;
use App\Models\Account;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;

/**
 * The export as rows of cells, header included, without the byte order mark.
 *
 * @param  array<string, mixed>  $payload
 * @return list<list<string>>
 */
function exportedRows(User $user, array $payload = []): array
{
    $response = test()->actingAs($user)->post(route('transactions.export'), $payload)->assertOk();

    $lines = explode("\n", trim(ltrim($response->streamedContent(), "\u{FEFF}")));

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
}

/**
 * Splits a transaction into lines, in the order given, and leaves it without
 * a category of its own the way splitting one does.
 *
 * @param  list<array{0: Category, 1: int}>  $lines  category and amount in cents
 */
function splitForExport(Transaction $transaction, array $lines): Transaction
{
    $transaction->update(['category_id' => null]);

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
 * What the export dialog reads as the number of rows "all matching" writes.
 *
 * @param  array<string, mixed>  $query
 */
function exportRowCount(User $user, array $query = []): int
{
    return test()->actingAs($user)->getJson(route('api.transactions.count', $query))->assertOk()->json('count');
}

test('a user downloads their transactions as a csv file', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $bank = Bank::factory()->create(['name' => 'Acme Bank']);
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Checking', 'bank_id' => $bank->id]);
    $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'category_id' => $category->id,
        'description' => 'Corner shop, "the good one"',
        'transaction_date' => '2026-03-14',
        'amount' => -1250,
        'currency_code' => 'EUR',
        'notes' => 'Weekly shop',
        'creditor_name' => 'Corner Shop SL',
        'debtor_name' => null,
    ]);
    $transaction->labels()->attach([
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Home'])->id,
        Label::factory()->create(['user_id' => $user->id, 'name' => 'Shared'])->id,
    ]);

    $response = $this->actingAs($user)->post(route('transactions.export'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('transactions-'.today()->format('Y-m-d').'.csv');

    expect($response->streamedContent())->toStartWith("\u{FEFF}");

    [$header, $row] = exportedRows($user);

    expect($header)->toBe(['Date', 'Description', 'Amount', 'Currency', 'Account', 'Bank', 'Category', 'Labels', 'Notes', 'Creditor', 'Debtor'])
        ->and($row)->toBe(['2026-03-14', 'Corner shop, "the good one"', '-12.50', 'EUR', 'Checking', 'Acme Bank', 'Groceries', 'Home, Shared', 'Weekly shop', 'Corner Shop SL', '']);
});

test('the export lists the newest transaction first', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Older', 'transaction_date' => '2026-01-01']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Newer', 'transaction_date' => '2026-02-01']);

    expect(array_column(exportedRows($user), 1))->toBe(['Description', 'Newer', 'Older']);
});

test('the export follows the sort of the transactions page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Big', 'amount' => -9000]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Small', 'amount' => -100]);

    expect(array_column(exportedRows($user, ['sort' => 'amount']), 1))->toBe(['Description', 'Big', 'Small'])
        ->and(array_column(exportedRows($user, ['sort' => '-amount']), 1))->toBe(['Description', 'Small', 'Big']);
});

test('the export never includes another user\'s transactions', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Mine']);
    $theirs = Transaction::factory()->plaintext()->create(['description' => 'Theirs']);

    expect(array_column(exportedRows($user), 1))->toBe(['Description', 'Mine'])
        ->and(array_column(exportedRows($user, ['ids' => [$theirs->id]]), 1))->toBe(['Description']);
});

test('the export honours the filters and search of the transactions page', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $groceries = Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Market in range', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Bakery in range', 'transaction_date' => '2026-03-12']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => $groceries->id, 'description' => 'Market too old', 'transaction_date' => '2026-01-10']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Market other category', 'transaction_date' => '2026-03-11']);

    $rows = exportedRows($user, [
        'date_from' => '2026-03-01',
        'date_to' => '2026-03-31',
        'category_ids' => $groceries->id,
        'search' => 'Market',
    ]);

    expect(array_column($rows, 1))->toBe(['Description', 'Market in range']);
});

test('only the loaded transactions are exported when their ids are given', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $loaded = Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Loaded', 'transaction_date' => '2026-03-02']);
    $alsoLoaded = Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Also loaded', 'transaction_date' => '2026-03-01']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Not loaded yet', 'transaction_date' => '2026-02-01']);

    expect(array_column(exportedRows($user, ['ids' => [$alsoLoaded->id, $loaded->id]]), 1))
        ->toBe(['Description', 'Loaded', 'Also loaded']);
});

test('amounts are written at the scale of their own currency', function (string $currency, int $minorUnits, string $expected) {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'amount' => $minorUnits, 'currency_code' => $currency]);

    expect(exportedRows($user)[1][2])->toBe($expected);
})->with([
    'two decimals' => ['USD', 123456, '1234.56'],
    'yen, stored in cents like every currency' => ['JPY', 1500, '15.00'],
    'negative' => ['EUR', -5, '-0.05'],
]);

test('text a spreadsheet would run as a formula is neutralised', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'description' => '=HYPERLINK("http://evil.test","Click")',
        'notes' => '+1 for this',
    ]);

    $row = exportedRows($user)[1];

    expect($row[1])->toBe('\'=HYPERLINK("http://evil.test","Click")')
        ->and($row[8])->toBe("'+1 for this");
});

test('an export with no transactions still has its header', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    expect(exportedRows($user))->toHaveCount(1);
});

test('an export rejects ids that are not uuids', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    $this->actingAs($user)
        ->postJson(route('transactions.export'), ['ids' => ['not-a-uuid']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids.0');
});

test('guests cannot export transactions', function () {
    $this->post(route('transactions.export'))->assertRedirect();
});

test('the count matches the transactions the filters select', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->count(3)->create(['user_id' => $user->id, 'description' => 'Coffee', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Coffee', 'transaction_date' => '2026-01-10']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Rent', 'transaction_date' => '2026-03-10']);
    Transaction::factory()->plaintext()->count(2)->create(['description' => 'Coffee', 'transaction_date' => '2026-03-10']);

    $this->actingAs($user)
        ->getJson(route('api.transactions.count', ['search' => 'Coffee', 'date_from' => '2026-03-01', 'date_to' => '2026-03-31']))
        ->assertOk()
        ->assertExactJson(['count' => 3]);

    $this->actingAs($user)
        ->getJson(route('api.transactions.count'))
        ->assertOk()
        ->assertExactJson(['count' => 5]);
});

test('guests cannot count transactions', function () {
    $this->getJson(route('api.transactions.count'))->assertUnauthorized();
});

test('the list sort refuses a column it does not know', function () {
    Transaction::query()->sortedForList('-id; drop table users');
})->throws(InvalidArgumentException::class);

test('an export follows a payee sort with blanks first', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Named', 'creditor_name' => 'Acme']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Blank', 'creditor_name' => null]);

    expect(array_column(exportedRows($user, ['sort' => 'creditor_name']), 1))->toBe(['Description', 'Blank', 'Named']);
});

test('the export keeps its own rate limit, apart from the api routes', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);

    foreach (range(1, 12) as $ignored) {
        $this->actingAs($user)->getJson(route('api.transactions.count'))->assertOk();
    }

    $this->actingAs($user)->post(route('transactions.export'))->assertOk();

    foreach (range(1, 9) as $ignored) {
        $this->actingAs($user)->post(route('transactions.export'))->assertOk();
    }

    $this->actingAs($user)->post(route('transactions.export'))->assertTooManyRequests();
});

test('a split transaction is written as one row per line, in line order, with the transaction\'s other fields', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $bank = Bank::factory()->create(['name' => 'Acme Bank']);
    $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Checking', 'bank_id' => $bank->id]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $household = Category::factory()->create(['user_id' => $user->id, 'name' => 'Household']);
    $transaction = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'description' => 'Hypermarket',
        'transaction_date' => '2026-04-02',
        'amount' => -10000,
        'currency_code' => 'EUR',
        'notes' => 'Monthly shop',
        'creditor_name' => 'Hypermarket SA',
    ]);
    $transaction->labels()->attach(Label::factory()->create(['user_id' => $user->id, 'name' => 'Home'])->id);
    splitForExport($transaction, [[$groceries, -7000], [$household, -3000]]);

    expect(array_slice(exportedRows($user), 1))->toBe([
        ['2026-04-02', 'Hypermarket', '-70.00', 'EUR', 'Checking', 'Acme Bank', 'Groceries', 'Home', 'Monthly shop', 'Hypermarket SA', ''],
        ['2026-04-02', 'Hypermarket', '-30.00', 'EUR', 'Checking', 'Acme Bank', 'Household', 'Home', 'Monthly shop', 'Hypermarket SA', ''],
    ]);
});

test('a category filter keeps only the split lines in that category or below it', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['name' => 'Groceries']);
    $rent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Rent']);
    splitForExport(
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Mixed', 'transaction_date' => '2026-05-01']),
        [[$rent, -6000], [$groceries, -4000]],
    );
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => null, 'description' => 'Loose', 'transaction_date' => '2026-05-02']);

    expect(array_column(exportedRows($user, ['category_ids' => $food->id]), 6))->toBe(['Category', 'Groceries'])
        ->and(array_column(exportedRows($user, ['category_ids' => $rent->id]), 6))->toBe(['Category', 'Rent'])
        ->and(array_column(exportedRows($user, ['category_ids' => "uncategorized,{$food->id}"]), 1))->toBe(['Description', 'Loose', 'Mixed'])
        ->and(array_column(exportedRows($user, ['category_ids' => 'uncategorized']), 1))->toBe(['Description', 'Loose'])
        ->and(array_column(exportedRows($user), 6))->toBe(['Category', '', 'Rent', 'Groceries']);
});

test('a wanted label on a split transaction keeps every one of its lines', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $rent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Rent']);
    $utilities = Category::factory()->create(['user_id' => $user->id, 'name' => 'Utilities']);
    $shared = Label::factory()->create(['user_id' => $user->id, 'name' => 'Shared']);
    $labelled = splitForExport(
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Labelled', 'transaction_date' => '2026-05-02']),
        [[$rent, -8000], [$utilities, -2000]],
    );
    $labelled->labels()->attach($shared->id);
    splitForExport(
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Unlabelled', 'transaction_date' => '2026-05-01']),
        [[$rent, -5000], [$utilities, -1000]],
    );

    $rows = exportedRows($user, ['category_ids' => $rent->id, 'label_ids' => $shared->id]);

    expect(array_map(fn (array $row): string => "{$row[1]} {$row[6]}", array_slice($rows, 1)))
        ->toBe(['Labelled Rent', 'Labelled Utilities', 'Unlabelled Rent'])
        ->and(array_column(exportedRows($user, ['label_ids' => $shared->id]), 6))->toBe(['Category', 'Rent', 'Utilities']);
});

test('the count is the number of rows the export writes', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food']);
    $groceries = Category::factory()->childOf($food)->create(['name' => 'Groceries']);
    $rent = Category::factory()->create(['user_id' => $user->id, 'name' => 'Rent']);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => $groceries->id]);
    splitForExport(Transaction::factory()->plaintext()->create(['user_id' => $user->id]), [[$groceries, -100], [$food, -200]]);
    splitForExport(Transaction::factory()->plaintext()->create(['user_id' => $user->id]), [[$rent, -300], [$groceries, -400], [$rent, -500]]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'category_id' => $rent->id]);

    // One unsplit, both lines of the first split, one line of the second.
    expect(exportRowCount($user, ['category_ids' => $food->id]))->toBe(4)
        ->and(exportedRows($user, ['category_ids' => $food->id]))->toHaveCount(4 + 1)
        ->and(exportRowCount($user))->toBe(7)
        ->and(exportedRows($user))->toHaveCount(7 + 1);
});

test('fields still encrypted on the device are exported empty, and the response says how many descriptions', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $category = Category::factory()->create(['user_id' => $user->id]);
    $encryptedAccount = Account::factory()->create([
        'user_id' => $user->id,
        'name' => 'c2VjcmV0LWFjY291bnQ=',
        'name_iv' => 'AAAAAAAAAAAAAAAA',
        'encrypted' => true,
    ]);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'account_id' => $encryptedAccount->id,
        'description' => 'ZW5jcnlwdGVkLWRlc2M=',
        'description_iv' => 'BBBBBBBBBBBBBBBB',
        'notes' => 'ZW5jcnlwdGVkLW5vdGU=',
        'notes_iv' => 'CCCCCCCCCCCCCCCC',
        'transaction_date' => '2026-06-03',
    ]);
    splitForExport(Transaction::factory()->create([
        'user_id' => $user->id,
        'description' => 'c3BsaXQtZGVzYw==',
        'description_iv' => 'DDDDDDDDDDDDDDDD',
        'notes' => 'Readable note',
        'notes_iv' => null,
        'transaction_date' => '2026-06-02',
    ]), [[$category, -100], [$category, -200]]);
    Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Plain', 'transaction_date' => '2026-06-01']);

    $response = $this->actingAs($user)->post(route('transactions.export'))->assertOk()
        ->assertHeader(TransactionExportController::ENCRYPTED_DESCRIPTIONS_HEADER, '3');
    $rows = exportedRows($user);

    expect($response->streamedContent())->not->toContain('ZW5jcnlwdGVk')->not->toContain('c2VjcmV0')->not->toContain('c3BsaXQt')
        ->and([$rows[1][1], $rows[1][4], $rows[1][8]])->toBe(['', '', ''])
        ->and([$rows[2][1], $rows[2][8]])->toBe(['', 'Readable note'])
        ->and([$rows[3][1], $rows[3][8]])->toBe(['', 'Readable note'])
        ->and($rows[4][1])->toBe('Plain');

    $this->actingAs($user)->post(route('transactions.export'), ['search' => 'Plain'])
        ->assertHeader(TransactionExportController::ENCRYPTED_DESCRIPTIONS_HEADER, '0');
});

test('exporting the loaded rows narrows their split lines the same way', function () {
    $user = User::factory()->create(['onboarded_at' => now()]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $household = Category::factory()->create(['user_id' => $user->id, 'name' => 'Household']);
    $loaded = splitForExport(
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Loaded']),
        [[$household, -300], [$groceries, -700]],
    );
    splitForExport(
        Transaction::factory()->plaintext()->create(['user_id' => $user->id, 'description' => 'Not loaded']),
        [[$groceries, -100]],
    );

    $rows = exportedRows($user, ['ids' => [$loaded->id], 'category_ids' => $groceries->id]);

    expect(array_map(fn (array $row): array => [$row[1], $row[2], $row[6]], array_slice($rows, 1)))
        ->toBe([['Loaded', '-7.00', 'Groceries']])
        ->and(array_column(exportedRows($user, ['ids' => [$loaded->id]]), 6))->toBe(['Category', 'Household', 'Groceries']);
});
