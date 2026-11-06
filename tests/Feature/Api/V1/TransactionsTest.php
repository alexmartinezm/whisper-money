<?php

use App\Contracts\BankingProviderInterface;
use App\Enums\AccountType;
use App\Enums\CategorySource;
use App\Enums\CategoryType;
use App\Enums\TransactionSource;
use App\Events\TransactionCreated;
use App\Events\TransactionDeleted;
use App\Events\TransactionUpdated;
use App\Features\TransactionSplitting;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\SavingsGoal;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\Banking\TransactionDescriptionFormatter;
use App\Services\Banking\TransactionSyncService;
use App\Services\BudgetTransactionService;
use App\Services\Recurring\DetectRecurringSeries;
use App\Services\Transactions\TransactionWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;

use function Pest\Laravel\withHeaders;

/**
 * @return array<string, string>
 */
function transactionsWriter(User $user): array
{
    return apiV1Bearer($user, ['api:read', 'api:write']);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function transactionOn(Account $account, array $attributes = []): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $account->user_id,
        'account_id' => $account->id,
        'category_id' => null,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function encryptedTransactionOn(Account $account, array $attributes = []): Transaction
{
    return transactionOn($account, [
        'description' => 'ZGVzY3JpcHRpb24=',
        'description_iv' => 'abcdefghijklmnop',
        'notes' => 'bm90ZXM=',
        'notes_iv' => 'ponmlkjihgfedcba',
        ...$attributes,
    ]);
}

/**
 * @param  array<int, array{0: Category, 1: int}>  $lines  each line's category and amount
 */
function splitInto(Transaction $transaction, array $lines): Transaction
{
    foreach (array_values($lines) as $position => [$category, $amount]) {
        TransactionSplit::factory()->create([
            'transaction_id' => $transaction->id,
            'category_id' => $category->id,
            'amount' => $amount,
            'position' => $position,
        ]);
    }

    return $transaction;
}

function sharedTransactionSpace(User $member, User $owner): Space
{
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($member->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    return $space;
}

function budgetPeriodCovering(Budget $budget, string $day): BudgetPeriod
{
    return BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => $day,
        'end_date' => $day,
    ]);
}

describe('list', function () {
    it('lists the transactions of the personal space, most recent day first', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'name' => 'Main checking', 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
        $label = Label::factory()->create(['user_id' => $user->id, 'name' => 'Holidays']);
        $older = transactionOn($account, ['transaction_date' => '2026-01-10']);
        $newer = transactionOn($account, [
            'transaction_date' => '2026-01-20',
            'description' => 'Market',
            'amount' => -4250,
            'currency_code' => 'EUR',
            'category_id' => $category->id,
            'category_source' => CategorySource::Manual,
            'creditor_name' => 'Fresh Market',
            'debtor_name' => null,
            'notes' => null,
        ]);
        $newer->labels()->attach($label);
        transactionOn(Account::factory()->create());

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions')
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $older->id)
            ->assertJsonPath('data.0', [
                'id' => $newer->id,
                'space_id' => $user->personalSpace->id,
                'account' => ['id' => $account->id, 'name' => 'Main checking'],
                'transaction_date' => '2026-01-20',
                'description' => 'Market',
                'amount' => -4250,
                'currency' => 'EUR',
                'category' => ['id' => $category->id, 'name' => 'Groceries'],
                'category_source' => 'manual',
                'labels' => [['id' => $label->id, 'name' => 'Holidays']],
                'source' => 'manually_created',
                'creditor_name' => 'Fresh Market',
                'debtor_name' => null,
                'notes' => null,
                'splits' => [],
                'created_at' => $newer->created_at->toIso8601String(),
            ]);
    });

    it('narrows by text, account, dates and amount together', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $match = transactionOn($account, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05']);
        transactionOn($account, ['description' => 'Coffee beans', 'amount' => -350, 'transaction_date' => '2026-03-05']);
        transactionOn($account, ['description' => 'Coffee machine', 'amount' => -90_000, 'transaction_date' => '2026-02-05']);
        transactionOn($other, ['description' => 'Coffee shop', 'amount' => -350, 'transaction_date' => '2026-02-05']);
        transactionOn($account, ['description' => 'Bakery', 'amount' => -350, 'transaction_date' => '2026-02-05']);

        $query = http_build_query([
            'query' => 'coffee',
            'account_id' => $account->id,
            'from' => '2026-02-01',
            'to' => '2026-02-28',
            'min_amount' => -1000,
            'max_amount' => 0,
        ]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?'.$query)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$match->id]);
    });

    it('filters on exact minor units, which the list\'s major units would miss', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $exact = transactionOn($account, ['amount' => 1999]);
        transactionOn($account, ['amount' => 1998]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?min_amount=1999&max_amount=1999')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$exact->id]);
    });

    it('matches the list on a category: its whole subtree, and any id spelling', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $parent = Category::factory()->create(['user_id' => $user->id]);
        $child = Category::factory()->create(['user_id' => $user->id, 'parent_id' => $parent->id]);
        $inParent = transactionOn($account, ['category_id' => $parent->id, 'transaction_date' => '2026-01-02']);
        $inChild = transactionOn($account, ['category_id' => $child->id, 'transaction_date' => '2026-01-01']);
        transactionOn($account, ['category_id' => Category::factory()->create(['user_id' => $user->id])->id]);
        transactionOn($account);

        foreach ([$parent->id, Str::upper($parent->id)] as $id) {
            withHeaders(apiV1Bearer($user))
                ->getJson('/api/v1/transactions?category_id='.$id)
                ->assertOk()
                ->assertJsonPath('data.*.id', [$inParent->id, $inChild->id]);
        }
    });

    it('treats a category and labels as one filter, as the list does', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $categorized = transactionOn($account, ['category_id' => $category->id, 'transaction_date' => '2026-01-02']);
        $labelled = transactionOn($account, ['transaction_date' => '2026-01-01']);
        $labelled->labels()->attach($label);
        transactionOn($account);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?'.http_build_query(['category_id' => $category->id, 'label_ids' => [$label->id]]))
            ->assertOk()
            ->assertJsonPath('data.*.id', [$categorized->id, $labelled->id]);
    });

    it('returns a split transaction as one whole row, whichever line the filter matches', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $elsewhere = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $split = splitInto(transactionOn($account, ['amount' => -1000]), [[$food, -600], [$home, -400]]);
        $split->labels()->attach($label);

        foreach (["category_id={$food->id}", "category_id={$home->id}", 'label_ids[]='.$label->id] as $filter) {
            withHeaders(apiV1Bearer($user))
                ->getJson('/api/v1/transactions?'.$filter)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.id', $split->id)
                ->assertJsonPath('data.0.amount', -1000)
                ->assertJsonPath('data.0.category', null)
                ->assertJsonPath('data.0.splits.*.amount', [-600, -400])
                ->assertJsonPath('data.0.splits.*.category.id', [$food->id, $home->id]);
        }

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?category_id='.$elsewhere->id)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    });

    it('never searches ciphertext, and still finds an encrypted row by what is readable', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $encrypted = encryptedTransactionOn($account, ['creditor_name' => 'Fresh Market']);
        $plain = transactionOn($account, ['description' => 'bm90ZXM= pasted by hand', 'notes' => null]);
        $noted = transactionOn($account, ['description' => 'Lunch', 'notes' => 'with the team']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=ZGVzY3JpcHRpb24')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=bm90ZXM')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$plain->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=team')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$noted->id]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?query=fresh')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$encrypted->id])
            ->assertJsonPath('data.0.description', '')
            ->assertJsonPath('data.0.notes', '');
    });

    it('refuses an id of another space and an invalid date', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?from=yesterday-ish&account_id=checking&category_id='.Category::factory()->create()->id.'&label_ids[]='.Label::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'account_id', 'category_id', 'label_ids.0']);
    });

    it('refuses a date whose year cannot be stored, an oversized query and too many labels', function () {
        $user = User::factory()->create();

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions?from=262004-08-10&to=0001-01-01&query='.str_repeat('a', 201).'&'.http_build_query(['label_ids' => array_fill(0, 101, (string) Str::uuid())]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from', 'to', 'query', 'label_ids']);
    });

    it('lists only your own transactions of a shared space when asked, and refuses one the user cannot access', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]));
        transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.$space->id)
            ->assertOk()
            ->assertJsonPath('data.*.id', [$mine->id]);

        withHeaders($headers)
            ->getJson('/api/v1/transactions?space='.Space::factory()->create(['owner_id' => $owner->id, 'personal' => false])->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('space');
    });

    it('pages through transactions sharing a day by id', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $sameDay = collect(range(1, 3))->map(fn (): Transaction => transactionOn($account, ['transaction_date' => '2026-01-20']))
            ->sortByDesc('id')->values();
        $older = transactionOn($account, ['transaction_date' => '2026-01-01']);
        $headers = apiV1Bearer($user);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2')
            ->assertOk()
            ->assertJsonPath('has_more', true)
            ->assertJsonPath('data.*.id', [$sameDay[0]->id, $sameDay[1]->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?limit=2&starting_after='.$sameDay[1]->id)
            ->assertOk()
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('data.*.id', [$sameDay[2]->id, $older->id]);

        withHeaders($headers)->getJson('/api/v1/transactions?starting_after='.transactionOn(Account::factory()->create())->id)
            ->assertBadRequest()
            ->assertJsonPath('code', 'invalid_cursor');
    });

    it('reads a page in the same number of queries however many rows it holds', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $seed = function () use ($account, $category, $label): void {
            splitInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]])->labels()->attach($label);
        };
        $headers = apiV1Bearer($user);
        $seed();

        $queriesFor = fn (): int => countQueries(fn () => withHeaders($headers)->getJson('/api/v1/transactions')->assertOk())['count'];
        $queriesFor();
        $few = $queriesFor();
        collect(range(1, 5))->each(fn () => $seed());

        expect($queriesFor())->toBe($few);
    });
});

describe('show', function () {
    it('retrieves a transaction of the space', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id);
    });

    it('answers 404 for a transaction of another user, another space, another member or no transaction at all', function (string $case) {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $id = match ($case) {
            'other user' => transactionOn(Account::factory()->create())->id,
            'other space' => transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]))->id,
            'missing' => (string) Str::uuid(),
        };

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$id)
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    })->with(['other user', 'other space', 'missing']);

    it('answers 404 for another member\'s transaction of a shared space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/transactions/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();
    });
});

describe('create', function () {
    it('creates a manual transaction, defaulting the currency to the account', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        $response = withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Cash at the market',
                'amount' => -1250,
                'transaction_date' => '2026-01-15',
                'category_id' => $category->id,
                'label_ids' => [$label->id],
                'creditor_name' => 'Market',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.source', 'manually_created')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.category_source', 'manual')
            ->assertJsonPath('data.labels.0.id', $label->id)
            ->assertJsonPath('balance_updated', false);

        $transaction = Transaction::query()->findOrFail($response->json('data.id'));

        expect($transaction->amount)->toBe(-1250)
            ->and($transaction->space_id)->toBe($user->personalSpace->id)
            ->and($transaction->creditor_name)->toBe('Market')
            ->and($transaction->description_iv)->toBeNull()
            ->and($transaction->notes_iv)->toBeNull();
    });

    it('keeps a currency picked apart from the account, in capitals', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => 'Hotel',
                'amount' => -4000,
                'transaction_date' => '2026-01-15',
                'currency_code' => 'usd',
            ])
            ->assertCreated()
            ->assertJsonPath('data.currency', 'USD');

        expect(Transaction::query()->sole()->getRawOriginal('currency_code'))->toBe('USD');
    });

    it('stores the day of a date sent with a time, and the ids as they are stored', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => Str::upper($account->id),
                'description' => 'Hotel',
                'amount' => -4000,
                'transaction_date' => '2026-01-15T23:30:00+05:00',
                'category_id' => Str::upper($category->id),
                'label_ids' => [Str::upper($label->id)],
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction_date', '2026-01-15');

        $transaction = Transaction::query()->sole();

        expect($transaction->getRawOriginal('account_id'))->toBe($account->id)
            ->and($transaction->getRawOriginal('category_id'))->toBe($category->id)
            ->and($transaction->getRawOriginal('transaction_date'))->toBe('2026-01-15')
            ->and(DB::table('label_transaction')->pluck('label_id')->all())->toBe([$label->id]);
    });

    it('moves a manual balance when asked, and leaves a connected one alone', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $connected = Account::factory()->connected()->create(['user_id' => $user->id]);
        $connected->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $body = ['description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15', 'update_balance' => true];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $manual->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', true);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'account_id' => $connected->id])
            ->assertCreated()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(9_900)
            ->and($connected->balances()->where('balance_date', '2026-01-15')->value('balance'))->toBe(10_000);
    });

    it('locks the account before anything reads a snapshot, and only when the balance moves', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $body = ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15'];
        $headers = transactionsWriter($user);

        $moving = statementsOfFirstTransaction(fn () => withHeaders($headers)->postJson('/api/v1/transactions', [...$body, 'update_balance' => true])->assertCreated());

        expect($moving[0])->toContain('`accounts`')->toContain('for update');

        $still = statementsOfFirstTransaction(fn () => withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated());

        expect($still[0] ?? '')->not->toContain('`accounts`');
    });

    it('leaves the balance alone unless asked', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $manual->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15'])
            ->assertCreated()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->sole()->balance)->toBe(10_000);
    });

    it('validates the transaction', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['amount' => 'a lot', 'currency_code' => 'EURO', 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'description', 'amount', 'transaction_date', 'currency_code', 'colour']);
    });

    it('refuses an amount that is not in minor units', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -12.5, 'transaction_date' => '2026-01-15'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');
    });

    it('refuses oversized text and a currency the app does not support', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => $account->id,
                'description' => str_repeat('a', 1001),
                'notes' => str_repeat('a', 10001),
                'amount' => -100,
                'transaction_date' => '2026-01-15',
                'currency_code' => 'XYZ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description', 'notes', 'currency_code']);

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses an account, category or label of another user', function () {
        $user = User::factory()->create();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'account_id' => Account::factory()->create()->id,
                'description' => 'Lunch',
                'amount' => -1000,
                'transaction_date' => '2026-01-15',
                'category_id' => Category::factory()->create()->id,
                'label_ids' => [Label::factory()->create()->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'category_id', 'label_ids.0']);

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses another member\'s account, category and labels in a shared space', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [
                'space' => $space->id,
                'account_id' => Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
                'description' => 'Lunch',
                'amount' => -1000,
                'transaction_date' => '2026-01-15',
                'category_id' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
                'label_ids' => [Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['account_id', 'category_id', 'label_ids.0']);
    });

    it('refuses more labels than a transaction can take, and a repeated one', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $body = ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'];

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'label_ids' => array_fill(0, 101, $label->id)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids');

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions', [...$body, 'label_ids' => [$label->id, Str::upper($label->id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids.0');
    });

    it('needs a write token', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        expect(Transaction::query()->count())->toBe(0);
    });

    it('creates one transaction for a retried idempotency key', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'retry-1'];
        $body = ['account_id' => $account->id, 'description' => 'Cash', 'amount' => -100, 'transaction_date' => '2026-01-15', 'update_balance' => true];

        $first = withHeaders($headers)->postJson('/api/v1/transactions', $body)->assertCreated();

        withHeaders($headers)->postJson('/api/v1/transactions', $body)
            ->assertCreated()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.id', $first->json('data.id'));

        expect(Transaction::query()->count())->toBe(1)
            ->and($account->balances()->sole()->balance)->toBe(9_900);
    });

    describe('side effects', function () {
        it('fires the created event', function () {
            Event::fake([TransactionCreated::class]);
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', ['account_id' => $account->id, 'description' => 'Lunch', 'amount' => -1000, 'transaction_date' => '2026-01-15'])
                ->assertCreated();

            Event::assertDispatched(TransactionCreated::class);
        });

        it('runs the automation rules on one with no category, and answers with what they did', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $category = Category::factory()->create(['user_id' => $user->id]);
            $ruleLabel = Label::factory()->create(['user_id' => $user->id]);
            $sentLabel = Label::factory()->create(['user_id' => $user->id]);
            $rule = AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
                'action_category_id' => $category->id,
            ]);
            $rule->labels()->attach($ruleLabel->id);

            $response = withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Mercadona Alcorcon',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$sentLabel->id],
                ])
                ->assertCreated()
                ->assertJsonPath('data.category.id', $category->id)
                ->assertJsonPath('data.category_source', 'rule');

            expect(collect($response->json('data.labels'))->pluck('id')->sort()->values()->all())
                ->toBe(collect([$ruleLabel->id, $sentLabel->id])->sort()->values()->all());
        });

        it('leaves a category sent by hand to the caller, as the app does', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $chosen = Category::factory()->create(['user_id' => $user->id]);
            AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['mercadona', ['var' => 'description']]],
                'action_category_id' => Category::factory()->create(['user_id' => $user->id])->id,
            ]);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Mercadona Alcorcon',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'category_id' => $chosen->id,
                ])
                ->assertCreated()
                ->assertJsonPath('data.category.id', $chosen->id)
                ->assertJsonPath('data.category_source', 'manual');
        });

        it('counts towards the goal a rule tags it for, even with labels of its own', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings]);
            $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
            $rule = AutomationRule::factory()->create([
                'user_id' => $user->id,
                'priority' => 1,
                'rules_json' => ['in' => ['hucha', ['var' => 'description']]],
                'action_category_id' => null,
            ]);
            $rule->labels()->attach($goal->label_id);

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Hucha enero',
                    'amount' => 5000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [Label::factory()->create(['user_id' => $user->id])->id],
                ])
                ->assertCreated()
                ->assertJsonCount(2, 'data.labels');

            expect($goal->fresh()->savedAmountInCents())->toBe(5000);
        });

        it('lands in the budget that tracks its category or its labels', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
            $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
            $label = Label::factory()->create(['user_id' => $user->id]);
            $byCategory = budgetPeriodCovering(Budget::factory()->forCategories($category)->create(['user_id' => $user->id]), '2026-01-15');
            $byLabel = budgetPeriodCovering(Budget::factory()->forLabels($label)->create(['user_id' => $user->id]), '2026-01-15');

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Lunch',
                    'amount' => -1000,
                    'transaction_date' => '2026-01-15',
                    'category_id' => $category->id,
                ])
                ->assertCreated();

            withHeaders(transactionsWriter($user))
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Trip',
                    'amount' => -2000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$label->id],
                ])
                ->assertCreated();

            expect((int) $byCategory->budgetTransactions()->sole()->amount)->toBe(1000)
                ->and((int) $byLabel->budgetTransactions()->sole()->amount)->toBe(2000);
        });

        it('gives a labelled one its second budget pass silently, so no limit is announced twice', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id]);
            $label = Label::factory()->create(['user_id' => $user->id]);
            $headers = transactionsWriter($user);
            Queue::fake();

            $response = withHeaders($headers)
                ->postJson('/api/v1/transactions', [
                    'account_id' => $account->id,
                    'description' => 'Trip',
                    'amount' => -2000,
                    'transaction_date' => '2026-01-15',
                    'label_ids' => [$label->id],
                ])
                ->assertCreated();

            Queue::assertPushed(
                ReassignTransactionsToBudgets::class,
                fn (ReassignTransactionsToBudgets $job): bool => $job->transactionIds === [$response->json('data.id')] && $job->notify === false,
            );
        });

        it('feeds recurring detection like any other row', function () {
            $user = User::factory()->create(['currency_code' => 'EUR']);
            $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

            foreach (range(4, 1) as $monthsAgo) {
                withHeaders(transactionsWriter($user))
                    ->postJson('/api/v1/transactions', [
                        'account_id' => $account->id,
                        'description' => 'Netflix',
                        'creditor_name' => 'Netflix',
                        'amount' => -1299,
                        'transaction_date' => now()->subMonthsNoOverflow($monthsAgo)->toDateString(),
                    ])
                    ->assertCreated();
            }

            expect(app(DetectRecurringSeries::class)->forUserEverywhere($user))->toBe(1);
        });
    });
});

describe('update', function () {
    it('changes only what is sent on a manual transaction', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $transaction = transactionOn($account, ['description' => 'Before', 'amount' => -100, 'currency_code' => 'EUR', 'notes' => 'keep']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['description' => 'After', 'amount' => -200, 'creditor_name' => 'Shop'])
            ->assertOk()
            ->assertJsonPath('data.description', 'After')
            ->assertJsonPath('data.amount', -200)
            ->assertJsonPath('data.creditor_name', 'Shop')
            ->assertJsonPath('data.notes', 'keep')
            ->assertJsonPath('balance_updated', false);
    });

    it('marks a new category as manual and resets its provenance, and a null clears it', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $before = Category::factory()->create(['user_id' => $user->id]);
        $after = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['category_id' => $before->id, 'category_source' => CategorySource::Ai, 'ai_confidence' => 0.9]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $after->id])
            ->assertOk()
            ->assertJsonPath('data.category.id', $after->id)
            ->assertJsonPath('data.category_source', 'manual');

        expect($transaction->fresh()->ai_confidence)->toBeNull();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => null])
            ->assertOk()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.category_source', null);
    });

    it('leaves the provenance of a category sent unchanged', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['category_id' => $category->id, 'category_source' => CategorySource::Ai, 'ai_confidence' => 0.9]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => Str::upper($category->id)])
            ->assertOk()
            ->assertJsonPath('data.category_source', 'ai');

        expect($transaction->fresh()->ai_confidence)->toBe(0.9);
    });

    it('stores a date sent with a time as its day', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-03-02T23:30:00-05:00'])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-03-02');

        expect($transaction->fresh()->getRawOriginal('transaction_date'))->toBe('2026-03-02');
    });

    it('moves a manual balance off the old day and onto the new one when asked', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $account->balances()->create(['balance_date' => '2026-01-20', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-20', 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', true);

        expect($account->balances()->where('balance_date', '2026-01-10')->value('balance'))->toBe(10_100)
            ->and($account->balances()->where('balance_date', '2026-01-20')->value('balance'))->toBe(10_000);
    });

    it('locks both accounts of a move before anything reads a snapshot', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $to = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $transaction = transactionOn($from, ['currency_code' => 'EUR']);
        $headers = transactionsWriter($user);

        $statements = statementsOfFirstTransaction(fn () => withHeaders($headers)
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => $to->id, 'update_balance' => true])
            ->assertOk());

        expect($statements[0])->toContain('`transactions`')->toContain('for update')
            ->and($statements[1])->toContain('`accounts`')->toContain('for update')->toContain('`id` in (?, ?)');
    });

    it('takes the new account\'s currency with the move, and off the old balance', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $from->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $to = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'USD']);
        $to->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $transaction = transactionOn($from, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['account_id' => Str::upper($to->id), 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('data.account.id', $to->id)
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('balance_updated', true);

        expect($transaction->fresh()->getRawOriginal('account_id'))->toBe($to->id)
            ->and($from->balances()->sole()->balance)->toBe(10_100)
            ->and($to->balances()->sole()->balance)->toBe(9_900);
    });

    it('keeps a currency picked in the same edit, and one the account did not change', function () {
        $user = User::factory()->create();
        $from = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $to = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD']);
        $picked = transactionOn($from, ['currency_code' => 'EUR']);
        $foreign = transactionOn($from, ['currency_code' => 'GBP']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$picked->id, ['account_id' => $to->id, 'currency_code' => 'JPY'])
            ->assertOk()
            ->assertJsonPath('data.currency', 'JPY');

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$foreign->id, ['account_id' => $from->id])
            ->assertOk()
            ->assertJsonPath('data.currency', 'GBP');
    });

    it('moves a bank transaction to another date, keeping the source\'s, and edits its notes and category', function () {
        $user = User::factory()->create();
        $account = Account::factory()->connected()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['source' => TransactionSource::EnableBanking, 'transaction_date' => '2026-01-27']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-02-01', 'notes' => 'Next month', 'category_id' => $category->id])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-02-01')
            ->assertJsonPath('data.notes', 'Next month')
            ->assertJsonPath('data.category.id', $category->id);

        expect($transaction->fresh()->source_date->toDateString())->toBe('2026-01-27');
    });

    it('does not take sending the same day for moving a bank transaction', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->connected()->create(['user_id' => $user->id]), ['source' => TransactionSource::EnableBanking, 'transaction_date' => '2026-01-27']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-27 15:00:00'])
            ->assertOk();

        expect($transaction->fresh()->source_date)->toBeNull();
    });

    it('locks what the source owns on one from a bank or an import, in the body or the query string', function (TransactionSource $source) {
        $user = User::factory()->create();
        $account = Account::factory()->connected()->create(['user_id' => $user->id]);
        $other = Account::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['source' => $source, 'description' => 'Bank said', 'amount' => -100, 'currency_code' => 'EUR', 'notes' => 'keep']);
        $locked = ['description' => 'Mine', 'amount' => -1, 'currency_code' => 'USD', 'account_id' => $other->id, 'creditor_name' => 'Me', 'debtor_name' => 'You'];

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, [...$locked, 'notes' => 'changed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(array_keys($locked));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id.'?amount=-1', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount');

        expect($transaction->fresh())
            ->description->toBe('Bank said')
            ->amount->toBe(-100)
            ->notes->toBe('keep');
    })->with([TransactionSource::EnableBanking, TransactionSource::Imported]);

    it('never moves the balance for one from a bank or an import, as the app\'s dialog', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-10', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['source' => TransactionSource::Imported, 'amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-10']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-01-20', 'update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        expect($account->balances()->sole()->balance)->toBe(10_000);
    });

    it('leaves the category and amount of a split transaction to its lines', function (array $body) {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits');

        expect($transaction->fresh()->amount)->toBe(-1000)
            ->and($transaction->fresh()->category_id)->toBeNull()
            ->and($transaction->splits()->count())->toBe(2);
    })->with([
        'category' => [['category_id' => null]],
        'amount' => [['amount' => -1]],
    ]);

    it('still moves a split transaction to another date and edits its notes', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['transaction_date' => '2026-02-01', 'notes' => 'Shared'])
            ->assertOk()
            ->assertJsonPath('data.transaction_date', '2026-02-01')
            ->assertJsonPath('data.notes', 'Shared')
            ->assertJsonCount(2, 'data.splits');
    });

    it('checks for a split again under the lock', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id]);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn($account, ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        expect(fn () => app(TransactionWriter::class)->update($transaction, ['amount' => -1], false))
            ->toThrow(ValidationException::class);

        expect($transaction->fresh()->amount)->toBe(-1000);
    });

    it('refuses unknown fields, labels included, and a category of another user', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, [
                'label_ids' => [],
                'description_iv' => 'abcdefghijklmnop',
                'source' => 'imported',
                'category_id' => Category::factory()->create()->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids', 'description_iv', 'source', 'category_id']);
    });

    it('answers 404 for another user\'s and another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]), ['description' => 'Theirs']);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.transactionOn(Account::factory()->create())->id, ['notes' => 'Mine'])
            ->assertNotFound();

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$theirs->id, ['space' => $space->id, 'description' => 'Mine'])
            ->assertNotFound();

        expect($theirs->fresh()->description)->toBe('Theirs');
    });

    it('fires the updated event and moves the transaction between budgets', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $before = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $after = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $beforePeriod = budgetPeriodCovering(Budget::factory()->forCategories($before)->create(['user_id' => $user->id]), '2026-01-15');
        $afterPeriod = budgetPeriodCovering(Budget::factory()->forCategories($after)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'category_id' => $before->id, 'transaction_date' => '2026-01-15']);

        expect($beforePeriod->budgetTransactions()->count())->toBe(1);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $after->id])
            ->assertOk();

        expect($beforePeriod->budgetTransactions()->count())->toBe(0)
            ->and((int) $afterPeriod->budgetTransactions()->sole()->amount)->toBe(1000);

        Event::fake([TransactionUpdated::class]);

        withHeaders(transactionsWriter($user))
            ->patchJson('/api/v1/transactions/'.$transaction->id, ['notes' => 'Noted'])
            ->assertOk();

        Event::assertDispatched(TransactionUpdated::class);
    });

    describe('encrypted rows', function () {
        it('clears only the iv of the field it writes', function (string $field, string $iv, string $otherField, string $otherIv) {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
            $other = $transaction->getRawOriginal($otherField);
            $otherIvValue = $transaction->getRawOriginal($otherIv);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, [$field => 'Plain text'])
                ->assertOk()
                ->assertJsonPath('data.'.$field, 'Plain text')
                ->assertJsonPath('data.'.$otherField, '');

            $fresh = $transaction->fresh();

            expect($fresh->{$field})->toBe('Plain text')
                ->and($fresh->{$iv})->toBeNull()
                ->and($fresh->{$otherField})->toBe($other)
                ->and($fresh->{$otherIv})->toBe($otherIvValue);
        })->with([
            'description' => ['description', 'description_iv', 'notes', 'notes_iv'],
            'notes' => ['notes', 'notes_iv', 'description', 'description_iv'],
        ]);

        it('touches neither when another field changes', function () {
            $user = User::factory()->create();
            $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
            $category = Category::factory()->create(['user_id' => $user->id]);
            $transaction = encryptedTransactionOn($account);
            $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
            $before = $stored($transaction);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, ['category_id' => $category->id, 'amount' => -1, 'creditor_name' => 'Shop', 'transaction_date' => '2026-02-01'])
                ->assertOk()
                ->assertJsonPath('data.description', '')
                ->assertJsonPath('data.notes', '');

            expect($stored($transaction->fresh()))->toBe($before);
        });

        it('refuses a blank value, so writing back what it read erases nothing', function (array $body, string $field) {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
            $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
            $before = $stored($transaction);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, $body)
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);

            expect($stored($transaction->fresh()))->toBe($before);
        })->with([
            'blank notes' => [['notes' => ''], 'notes'],
            'null notes' => [['notes' => null], 'notes'],
            'spaces in notes' => [['notes' => '   '], 'notes'],
            'blank description' => [['description' => ''], 'description'],
            'null description' => [['description' => null], 'description'],
        ]);

        it('still clears a plain note on a row with an encrypted description', function () {
            $user = User::factory()->create();
            $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]), ['notes' => 'Plain', 'notes_iv' => null]);

            withHeaders(transactionsWriter($user))
                ->patchJson('/api/v1/transactions/'.$transaction->id, ['notes' => null])
                ->assertOk()
                ->assertJsonPath('data.notes', null);

            expect($transaction->fresh()->description_iv)->toBe('abcdefghijklmnop');
        });
    });
});

describe('delete', function () {
    it('deletes a manual transaction, reversing a manual balance when asked', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $transaction = transactionOn($account, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['update_balance' => true])
            ->assertOk()
            ->assertExactJson(['id' => $transaction->id, 'deleted' => true, 'balance_updated' => true]);

        expect($transaction->fresh()->trashed())->toBeTrue()
            ->and($account->balances()->sole()->balance)->toBe(10_100);
    });

    it('locks the account before anything reads a snapshot', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $transaction = transactionOn($account, ['currency_code' => 'EUR']);
        $headers = transactionsWriter($user);

        $statements = statementsOfFirstTransaction(fn () => withHeaders($headers)
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['update_balance' => true])
            ->assertOk());

        expect($statements[0])->toContain('`transactions`')->toContain('for update')
            ->and($statements[1])->toContain('`accounts`')->toContain('for update');
    });

    it('leaves the balance alone unless asked, and a connected one always', function () {
        $user = User::factory()->create();
        $manual = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking', 'currency_code' => 'EUR']);
        $manual->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $connected = Account::factory()->connected()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $connected->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $unasked = transactionOn($manual, ['amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);
        $bank = transactionOn($connected, ['source' => TransactionSource::EnableBanking, 'amount' => -100, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$unasked->id)
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$bank->id, ['update_balance' => true])
            ->assertOk()
            ->assertJsonPath('balance_updated', false);

        expect($manual->balances()->sole()->balance)->toBe(10_000)
            ->and($connected->balances()->sole()->balance)->toBe(10_000);
    });

    it('deletes a bank transaction, which the next sync does not bring back', function () {
        $user = User::factory()->onboarded()->create();
        $connection = BankingConnection::factory()->create(['user_id' => $user->id]);
        $account = Account::factory()->connected()->create([
            'user_id' => $user->id,
            'banking_connection_id' => $connection->id,
            'external_account_id' => 'ext-123',
        ]);
        $transaction = Transaction::factory()->enableBanking()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'external_transaction_id' => 'txn-001',
        ]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk()
            ->assertJsonPath('deleted', true);

        $provider = Mockery::mock(BankingProviderInterface::class);
        $provider->shouldReceive('getTransactions')->once()->andReturn([
            'transactions' => [[
                'transaction_id' => 'txn-001',
                'transaction_amount' => ['amount' => '50.00', 'currency' => 'EUR'],
                'credit_debit_indicator' => 'DBIT',
                'booking_date' => '2025-01-15',
                'remittance_information' => ['Grocery Store Purchase'],
            ]],
            'continuation_key' => null,
        ]);

        $created = (new TransactionSyncService($provider, new TransactionDescriptionFormatter))->sync($account, '2025-01-01', '2025-01-31');

        expect($created)->toBe(0)
            ->and($account->transactions()->count())->toBe(0)
            ->and($account->transactions()->withTrashed()->count())->toBe(1);
    });

    it('takes a split transaction out of its budgets, and fires the deleted event', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $period = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = splitInto(transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']), [[$food, -600], [$food, -400]]);
        app(BudgetTransactionService::class)->assignTransaction($transaction->fresh());

        expect((int) $period->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id)
            ->assertOk();

        expect($period->budgetTransactions()->count())->toBe(0);

        Event::fake([TransactionDeleted::class]);

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.transactionOn($account)->id)
            ->assertOk();

        Event::assertDispatched(TransactionDeleted::class);
    });

    it('refuses unknown fields, and a second delete is a 404', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$transaction->id, ['force' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('force');

        expect($transaction->fresh()->trashed())->toBeFalse();

        withHeaders(transactionsWriter($user))->deleteJson('/api/v1/transactions/'.$transaction->id)->assertOk();
        withHeaders(transactionsWriter($user))->deleteJson('/api/v1/transactions/'.$transaction->id)->assertNotFound();
    });

    it('answers 404 for another user\'s and another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.transactionOn(Account::factory()->create())->id)
            ->assertNotFound();

        withHeaders(transactionsWriter($user))
            ->deleteJson('/api/v1/transactions/'.$theirs->id.'?space='.$space->id)
            ->assertNotFound();

        expect($theirs->fresh()->trashed())->toBeFalse();
    });
});

describe('labels', function () {
    it('makes the sent labels the only ones, and an empty list clears them', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $kept = Label::factory()->create(['user_id' => $user->id]);
        $dropped = Label::factory()->create(['user_id' => $user->id]);
        $added = Label::factory()->create(['user_id' => $user->id]);
        $transaction->labels()->attach([$kept->id, $dropped->id]);

        $response = withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$kept->id, Str::upper($added->id)]])
            ->assertOk()
            ->assertJsonCount(2, 'data.labels');

        expect(collect($response->json('data.labels'))->pluck('id')->sort()->values()->all())
            ->toBe(collect([$kept->id, $added->id])->sort()->values()->all())
            ->and(DB::table('label_transaction')->where('transaction_id', $transaction->id)->pluck('label_id')->sort()->values()->all())
            ->toBe(collect([$kept->id, $added->id])->sort()->values()->all());

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.labels');

        expect($transaction->labels()->count())->toBe(0);
    });

    it('works on a bank row, a split one and an encrypted one, touching nothing else', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $bank = transactionOn(Account::factory()->connected()->create(['user_id' => $user->id]), ['source' => TransactionSource::EnableBanking]);
        $split = splitInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);
        $encrypted = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]));
        $notes = $encrypted->notes;

        foreach ([$bank, $split, $encrypted] as $transaction) {
            withHeaders(transactionsWriter($user))
                ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
                ->assertOk()
                ->assertJsonPath('data.labels.0.id', $label->id);
        }

        expect($split->splits()->count())->toBe(2)
            ->and($encrypted->fresh()->notes)->toBe($notes)
            ->and($encrypted->fresh()->notes_iv)->toBe('ponmlkjihgfedcba')
            ->and($encrypted->fresh()->description_iv)->toBe('abcdefghijklmnop');
    });

    it('moves the row in the app\'s delta sync, without firing an event or a budget email', function () {
        $user = User::factory()->create();
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $label = Label::factory()->create(['user_id' => $user->id]);
        $before = $transaction->fresh()->updated_at;
        Event::fake([TransactionUpdated::class]);
        Queue::fake();

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
            ->assertOk();

        expect($transaction->fresh()->updated_at->gt($before))->toBeTrue();

        Event::assertNotDispatched(TransactionUpdated::class);
        Queue::assertPushed(
            ReassignTransactionsToBudgets::class,
            fn (ReassignTransactionsToBudgets $job): bool => $job->transactionIds === [$transaction->id] && $job->notify === false,
        );
    });

    it('moves the transaction into the budget that tracks the label', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $label = Label::factory()->create(['user_id' => $user->id]);
        $period = budgetPeriodCovering(Budget::factory()->forLabels($label)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']);

        expect($period->budgetTransactions()->count())->toBe(0);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$label->id]])
            ->assertOk();

        expect((int) $period->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => []])
            ->assertOk();

        expect($period->budgetTransactions()->count())->toBe(0);
    });

    it('counts towards a savings goal once it carries the goal\'s label', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => AccountType::Savings, 'currency_code' => 'EUR']);
        $goal = SavingsGoal::factory()->create(['user_id' => $user->id]);
        $transaction = transactionOn($account, ['amount' => 5000, 'currency_code' => 'EUR']);

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$transaction->id.'/labels', ['label_ids' => [$goal->label_id]])
            ->assertOk();

        expect($goal->fresh()->savedAmountInCents())->toBe(5000);
    });

    it('refuses a missing list, unknown fields, repeats, too many and labels that are not yours', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]));
        $url = '/api/v1/transactions/'.$transaction->id.'/labels?space='.$space->id;
        $mine = Label::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]);

        withHeaders(transactionsWriter($user))->putJson($url, ['colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids', 'colour']);

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => [$mine->id, Str::upper($mine->id)]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids.0');

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => array_fill(0, 101, $mine->id)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label_ids')
            ->assertJsonMissingValidationErrors('label_ids.0');

        withHeaders(transactionsWriter($user))->putJson($url, ['label_ids' => [
            Label::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id])->id,
            Label::factory()->create(['user_id' => $user->id])->id,
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['label_ids.0', 'label_ids.1']);

        expect($transaction->labels()->count())->toBe(0);
    });

    it('needs a write token, and answers 404 for another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(apiV1Bearer($user))
            ->putJson('/api/v1/transactions/'.$mine->id.'/labels', ['label_ids' => []])
            ->assertForbidden()
            ->assertJsonPath('code', 'insufficient_scope');

        withHeaders(transactionsWriter($user))
            ->putJson('/api/v1/transactions/'.$theirs->id.'/labels?space='.$space->id, ['label_ids' => []])
            ->assertNotFound();
    });
});

describe('split', function () {
    it('files a transaction under its lines, on the parent', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'type' => 'checking']);
        $account->balances()->create(['balance_date' => '2026-01-15', 'balance' => 10_000]);
        $before = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'name' => 'Home', 'type' => CategoryType::Expense]);
        $transaction = transactionOn($account, [
            'amount' => -1000,
            'transaction_date' => '2026-01-15',
            'category_id' => $before->id,
            'category_source' => CategorySource::Ai,
            'ai_confidence' => 0.9,
        ]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $food->id, 'amount' => -600],
                ['category_id' => Str::upper($home->id), 'amount' => -400],
            ]])
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.amount', -1000)
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.category_source', null)
            ->assertJsonPath('data.splits.*.amount', [-600, -400])
            ->assertJsonPath('data.splits.*.position', [0, 1])
            ->assertJsonPath('data.splits.*.category.name', ['Food', 'Home'])
            ->assertJsonMissingPath('data.split_parent_id');

        $fresh = $transaction->fresh();

        expect($fresh->category_id)->toBeNull()
            ->and($fresh->ai_confidence)->toBeNull()
            ->and($fresh->amount)->toBe(-1000)
            ->and($fresh->splits()->pluck('category_id')->all())->toBe([$food->id, $home->id])
            ->and(Transaction::query()->count())->toBe(1)
            ->and($account->balances()->sole()->balance)->toBe(10_000);
    });

    it('replaces the lines of a split one, and files a bank row too', function () {
        $user = User::factory()->create();
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn(Account::factory()->connected()->create(['user_id' => $user->id]), [
            'amount' => -1000,
            'source' => TransactionSource::EnableBanking,
        ]), [[$food, -500], [$home, -500]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $home->id, 'amount' => -100],
                ['category_id' => $home->id, 'amount' => -200],
                ['category_id' => $food->id, 'amount' => -700],
            ]])
            ->assertOk()
            ->assertJsonPath('data.splits.*.amount', [-100, -200, -700]);

        expect(TransactionSplit::query()->where('transaction_id', $transaction->id)->count())->toBe(3);
    });

    it('allocates the lines to their budgets, and moves them when replaced', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $foodPeriod = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $homePeriod = budgetPeriodCovering(Budget::factory()->forCategories($home)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15', 'category_id' => $food->id]);

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(1000);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $food->id, 'amount' => -600],
                ['category_id' => $home->id, 'amount' => -400],
            ]])
            ->assertOk();

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(600)
            ->and((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(400);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $food->id, 'amount' => -100],
                ['category_id' => $home->id, 'amount' => -900],
            ]])
            ->assertOk();

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(100)
            ->and((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(900);
    });

    it('leaves an encrypted row encrypted', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = encryptedTransactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);
        $stored = fn (Transaction $row): array => [$row->description, $row->description_iv, $row->notes, $row->notes_iv];
        $before = $stored($transaction);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $category->id, 'amount' => -600],
                ['category_id' => $category->id, 'amount' => -400],
            ]])
            ->assertOk()
            ->assertJsonPath('data.description', '');

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => $category->id])
            ->assertOk();

        expect($stored($transaction->fresh()))->toBe($before);
    });

    it('keeps the invariants of a split', function (Closure $lines) {
        $user = User::factory()->create();
        $expense = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $income = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income]);
        $transfer = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Transfer]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => $lines($expense, $income, $transfer)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits');

        expect($transaction->splits()->count())->toBe(0);
    })->with([
        'short of the parent by a cent' => [fn (Category $e) => [['category_id' => $e->id, 'amount' => -600], ['category_id' => $e->id, 'amount' => -399]]],
        'past the parent' => [fn (Category $e) => [['category_id' => $e->id, 'amount' => -600], ['category_id' => $e->id, 'amount' => -401]]],
        'adds up with a line the other way' => [fn (Category $e) => [['category_id' => $e->id, 'amount' => -1100], ['category_id' => $e->id, 'amount' => 100]]],
        'one line' => [fn (Category $e) => [['category_id' => $e->id, 'amount' => -1000]]],
        'no lines' => [fn () => []],
        'expense and income categories' => [fn (Category $e, Category $i) => [['category_id' => $e->id, 'amount' => -600], ['category_id' => $i->id, 'amount' => -400]]],
        'transfer categories' => [fn (Category $e, Category $i, Category $t) => [['category_id' => $t->id, 'amount' => -600], ['category_id' => $t->id, 'amount' => -400]]],
    ]);

    it('refuses a zero line, one with no category, and fields a line does not have', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $category->id, 'amount' => 0],
                ['amount' => -1000],
                ['category_id' => $category->id, 'amount' => -1000, 'label_ids' => []],
            ], 'colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['splits.0.amount', 'splits.1.category_id', 'splits.2', 'colour']);

        expect($transaction->splits()->count())->toBe(0);
    });

    it('bounds the lines before looking any of them up', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -21]);
        $headers = transactionsWriter($user);

        $queries = countQueries(fn () => withHeaders($headers)
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => array_fill(0, 21, ['category_id' => $category->id, 'amount' => -1])])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits'));

        expect(collect($queries['queries'])->filter(fn (string $sql): bool => str_contains($sql, '`categories`')))->toBeEmpty();
    });

    it('takes twenty lines', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -20]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => array_fill(0, 20, ['category_id' => $category->id, 'amount' => -1])])
            ->assertOk()
            ->assertJsonCount(20, 'data.splits');
    });

    it('refuses a category of another user or another member', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $mine = Category::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split?space='.$space->id, ['splits' => [
                ['category_id' => $mine->id, 'amount' => -600],
                ['category_id' => Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => CategoryType::Expense])->id, 'amount' => -400],
            ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits.1.category_id');

        expect($transaction->splits()->count())->toBe(0);
    });

    it('answers 403 while splitting is off, and writes nothing', function () {
        $user = User::factory()->create();
        Feature::for($user)->deactivate(TransactionSplitting::class);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/split', ['splits' => [
                ['category_id' => $category->id, 'amount' => -600],
                ['category_id' => $category->id, 'amount' => -400],
            ]])
            ->assertForbidden()
            ->assertJsonPath('code', 'splitting_disabled');

        expect($transaction->splits()->count())->toBe(0);
    });

    it('splits once for a retried idempotency key', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'split-1'];
        $body = ['splits' => [['category_id' => $category->id, 'amount' => -600], ['category_id' => $category->id, 'amount' => -400]]];

        $first = withHeaders($headers)->postJson('/api/v1/transactions/'.$transaction->id.'/split', $body)->assertOk();
        $lineIds = $first->json('data.splits.*.id');

        withHeaders($headers)->postJson('/api/v1/transactions/'.$transaction->id.'/split', $body)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true')
            ->assertJsonPath('data.splits.*.id', $lineIds);

        expect($transaction->splits()->pluck('id')->sort()->values()->all())->toBe(collect($lineIds)->sort()->values()->all());
    });

    it('needs a write token, and answers 404 for another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $mine = transactionOn(Account::factory()->create(['user_id' => $user->id]));
        $theirs = transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]));

        withHeaders(apiV1Bearer($user))
            ->postJson('/api/v1/transactions/'.$mine->id.'/split', ['splits' => []])
            ->assertForbidden();

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$theirs->id.'/split?space='.$space->id, ['splits' => []])
            ->assertNotFound();
    });
});

describe('merge splits', function () {
    it('returns the transaction to unsplit, filed by hand under the category sent', function () {
        $user = User::factory()->create();
        $food = Category::factory()->create(['user_id' => $user->id, 'name' => 'Food', 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$food, -600], [$food, -400]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => Str::upper($food->id)])
            ->assertOk()
            ->assertJsonPath('data.id', $transaction->id)
            ->assertJsonPath('data.amount', -1000)
            ->assertJsonPath('data.category.id', $food->id)
            ->assertJsonPath('data.category_source', 'manual')
            ->assertJsonPath('data.splits', []);

        expect($transaction->fresh()->getRawOriginal('category_id'))->toBe($food->id)
            ->and(TransactionSplit::query()->count())->toBe(0);
    });

    it('moves the budgets back to the whole amount', function () {
        $user = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);
        $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $home = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $foodPeriod = budgetPeriodCovering(Budget::factory()->forCategories($food)->create(['user_id' => $user->id]), '2026-01-15');
        $homePeriod = budgetPeriodCovering(Budget::factory()->forCategories($home)->create(['user_id' => $user->id]), '2026-01-15');
        $transaction = splitInto(transactionOn($account, ['amount' => -1000, 'currency_code' => 'EUR', 'transaction_date' => '2026-01-15']), [[$food, -600], [$home, -400]]);
        app(BudgetTransactionService::class)->assignTransaction($transaction->fresh());

        expect((int) $homePeriod->budgetTransactions()->sole()->amount)->toBe(400);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => $food->id])
            ->assertOk();

        expect((int) $foodPeriod->budgetTransactions()->sole()->amount)->toBe(1000)
            ->and($homePeriod->budgetTransactions()->count())->toBe(0);
    });

    it('stays open while splitting is off', function () {
        $user = User::factory()->create();
        Feature::for($user)->deactivate(TransactionSplitting::class);
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => $category->id])
            ->assertOk();

        expect($transaction->splits()->count())->toBe(0);
    });

    it('refuses a transaction that is not split, and does not recategorize it', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', ['category_id' => $category->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transaction');

        expect($transaction->fresh()->category_id)->toBeNull();

        expect(fn () => app(TransactionWriter::class)->mergeSplits($transaction, $category->id))
            ->toThrow(ValidationException::class);

        expect($transaction->fresh()->category_id)->toBeNull();
    });

    it('needs a category of yours of expense or income type', function () {
        $user = User::factory()->create();
        $expense = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transfer = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Transfer]);
        $transaction = splitInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$expense, -600], [$expense, -400]]);
        $url = '/api/v1/transactions/'.$transaction->id.'/merge-splits';

        withHeaders(transactionsWriter($user))->postJson($url, ['colour' => 'red'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id', 'colour']);

        withHeaders(transactionsWriter($user))->postJson($url, ['category_id' => Category::factory()->create()->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('category_id');

        withHeaders(transactionsWriter($user))->postJson($url, ['category_id' => $transfer->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('splits');

        expect($transaction->splits()->count())->toBe(2);
    });

    it('merges once for a retried idempotency key', function () {
        $user = User::factory()->create();
        $category = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense]);
        $transaction = splitInto(transactionOn(Account::factory()->create(['user_id' => $user->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);
        $headers = [...transactionsWriter($user), 'Idempotency-Key' => 'merge-1'];
        $body = ['category_id' => $category->id];

        withHeaders($headers)->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', $body)->assertOk();

        withHeaders($headers)->postJson('/api/v1/transactions/'.$transaction->id.'/merge-splits', $body)
            ->assertOk()
            ->assertHeader('Idempotent-Replayed', 'true');
    });

    it('answers 404 for another member\'s transaction', function () {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $space = sharedTransactionSpace($user, $owner);
        $category = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => CategoryType::Expense]);
        $theirs = splitInto(transactionOn(Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id]), ['amount' => -1000]), [[$category, -600], [$category, -400]]);

        withHeaders(transactionsWriter($user))
            ->postJson('/api/v1/transactions/'.$theirs->id.'/merge-splits?space='.$space->id, ['category_id' => $category->id])
            ->assertNotFound();

        expect($theirs->splits()->count())->toBe(2);
    });
});
