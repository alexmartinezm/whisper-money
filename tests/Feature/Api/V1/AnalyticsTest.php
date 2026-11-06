<?php

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\SpendingByCategory;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withHeaders;

/**
 * A EUR user with October 2026's income, expenses (one of them a refund), an
 * uncategorized outflow and a fixed rent budget with 50.00 left.
 *
 * @return array{user: User, account: Account, food: Category, groceries: Category, rent: Category}
 */
function analyticsBook(): array
{
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'name' => 'Main']);
    $salary = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Income, 'name' => 'Salary']);
    $food = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Food']);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Groceries', 'parent_id' => $food->id]);
    $rent = Category::factory()->create(['user_id' => $user->id, 'type' => CategoryType::Expense, 'name' => 'Rent']);

    foreach ([
        ['2026-09-01', $salary, 250_000], ['2026-09-03', $rent, -90_000],
        ['2026-10-01', $salary, 260_000], ['2026-10-02', $rent, -90_000], ['2026-10-04', $groceries, -12_345],
        ['2026-10-05', $food, -5_000], ['2026-10-06', $groceries, 2_000], ['2026-10-07', null, -777],
    ] as [$date, $category, $amount]) {
        Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => $category?->id,
            'amount' => $amount,
            'currency_code' => 'EUR',
            'transaction_date' => $date,
        ]);
    }

    $budget = Budget::factory()->monthly()->fixed()->forCategories($rent)->create(['user_id' => $user->id, 'name' => 'Rent', 'period_start_day' => 1]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 95_000, 'processing_historical' => false]);
    BudgetTransaction::factory()->create(['budget_period_id' => $period->id, 'amount' => 90_000]);

    return compact('user', 'account', 'food', 'groceries', 'rent');
}

const OCTOBER = '?from=2026-10-01&to=2026-10-31';

/**
 * A member of a shared space, whose housemate has money and a budget in it.
 *
 * @return array{user: User, owner: User, space: Space}
 */
function analyticsHousemates(): array
{
    $user = User::factory()->create(['currency_code' => 'EUR']);
    $owner = User::factory()->create(['currency_code' => 'EUR']);
    $space = Space::factory()->create(['owner_id' => $owner->id, 'personal' => false]);
    $space->members()->attach($user->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    $theirAccount = Account::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'name' => 'Their account']);
    AccountBalance::factory()->create(['account_id' => $theirAccount->id, 'balance_date' => '2026-10-01', 'balance' => 777_000]);
    $theirFood = Category::factory()->create(['user_id' => $owner->id, 'space_id' => $space->id, 'type' => CategoryType::Expense, 'name' => 'Their food']);
    Transaction::factory()->create([
        'user_id' => $owner->id,
        'account_id' => $theirAccount->id,
        'category_id' => $theirFood->id,
        'amount' => -55_000,
        'currency_code' => 'EUR',
        'transaction_date' => '2026-10-03',
    ]);
    $budget = Budget::factory()->monthly()->forCategories($theirFood)->create(['user_id' => $owner->id, 'space_id' => $space->id, 'name' => 'Their budget', 'period_start_day' => 1]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31', 'allocated_amount' => 95_000]);

    return compact('user', 'owner', 'space');
}

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-10-15 12:00:00'));
});

describe('cashflow', function () {
    it('reports the summary with the budget split, the sankey and the trend', function () {
        ['user' => $user, 'food' => $food, 'rent' => $rent] = analyticsBook();

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.summary.current.income', 260_000)
            ->assertJsonPath('data.summary.current.expense', 106_122)
            ->assertJsonPath('data.summary.current.fixed_expense', 90_000)
            ->assertJsonPath('data.summary.current.variable_expense', 0)
            ->assertJsonPath('data.summary.current.general_expense', 16_122)
            ->assertJsonPath('data.summary.current.reserved', 5_000)
            ->assertJsonPath('data.summary.current.budgets.0.name', 'Rent')
            ->assertJsonPath('data.summary.current.budgets.0.expense_type', 'fixed')
            ->assertJsonPath('data.summary.current.budgets.0.margin', 5_000)
            ->assertJsonPath('data.summary.previous.income', 250_000)
            ->assertJsonPath('data.summary.previous.fixed_expense', 0)
            ->assertJsonMissingPath('data.summary.previous.reserved')
            ->assertJsonMissingPath('data.summary.previous.budgets')
            ->assertJsonPath('data.sankey.total_income', 260_000)
            ->assertJsonPath('data.sankey.total_expense', 106_122)
            ->assertJsonPath('data.trend', [['month' => '2026-10', 'income' => 260_000, 'expense' => 106_122, 'net' => 153_878]]);

        expect(collect($response->json('data.sankey.expense_categories'))->keyBy('name')->all())
            ->toMatchArray([
                'Food' => ['category_id' => $food->id, 'name' => 'Food', 'type' => 'expense', 'color' => $food->color, 'icon' => $food->icon, 'amount' => 15_345, 'has_children' => true, 'is_direct' => false],
                'Rent' => ['category_id' => $rent->id, 'name' => 'Rent', 'type' => 'expense', 'color' => $rent->color, 'icon' => $rent->icon, 'amount' => 90_000, 'has_children' => false, 'is_direct' => false],
                'Unknown Expense' => ['category_id' => null, 'name' => 'Unknown Expense', 'type' => 'expense', 'color' => 'gray', 'icon' => 'HelpCircle', 'amount' => 777, 'has_children' => false, 'is_direct' => false],
            ]);
    });

    it('reads the same figures as the cashflow screen', function () {
        ['user' => $user] = analyticsBook();

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/cashflow'.OCTOBER)->json('data');
        app('auth')->forgetGuards();
        actingAs($user);

        expect($api['summary'])->toEqual(getJson('/api/cashflow/summary'.OCTOBER)->json())
            ->and($api['sankey']['total_expense'])->toBe(getJson('/api/cashflow/sankey'.OCTOBER)->json('total_expense'))
            ->and($api['trend'])->toBe(getJson('/api/cashflow/trend'.OCTOBER)->json('data'));
    });

    it('counts a split transaction line by line', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'rent' => $rent] = analyticsBook();
        $split = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => null,
            'amount' => -30_000,
            'currency_code' => 'EUR',
            'transaction_date' => '2026-10-08',
        ]);
        TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $food->id, 'amount' => -10_000, 'position' => 0]);
        TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $rent->id, 'amount' => -20_000, 'position' => 1]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.summary.current.expense', 136_122)
            ->assertJsonPath('data.summary.current.fixed_expense', 110_000)
            ->assertJsonPath('data.summary.current.general_expense', 26_122);

        expect(collect($response->json('data.sankey.expense_categories'))->pluck('amount', 'name')->all())
            ->toMatchArray(['Food' => 25_345, 'Rent' => 110_000]);
    });

    it('leaves another member\'s figures out, in a shared space too, and adds up the caller\'s in every space', function () {
        ['user' => $user, 'space' => $space] = analyticsHousemates();
        $inTheSpace = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
        $personal = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);

        foreach ([[$inTheSpace, -1_000], [$personal, -2_000]] as [$account, $amount]) {
            Transaction::factory()->create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'category_id' => Category::factory()->create(['user_id' => $user->id, 'space_id' => $account->space_id, 'type' => CategoryType::Expense])->id,
                'amount' => $amount,
                'currency_code' => 'EUR',
                'transaction_date' => '2026-10-04',
            ]);
        }

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.summary.current.expense', 3_000)
            ->assertJsonPath('data.summary.current.budgets', [])
            ->assertJsonPath('data.summary.current.reserved', 0)
            ->assertDontSee('Their food')
            ->assertDontSee('Their budget');
    });

    it('leaves out someone else\'s money', function () {
        analyticsBook();

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/cashflow'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.summary.current.income', 0)
            ->assertJsonPath('data.summary.current.budgets', [])
            ->assertJsonPath('data.sankey.expense_categories', []);
    });
});

describe('net worth', function () {
    it('reports the net worth against the previous period and each account\'s monthly balances', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $checking = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'name' => 'Main']);
        $broker = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'USD', 'type' => AccountType::Investment, 'name' => 'Broker']);
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => '2026-10-15', 'rates' => ['usd' => 1.25]]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-09-30', 'balance' => 100_000]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-10-10', 'balance' => 120_000]);
        AccountBalance::factory()->create(['account_id' => $broker->id, 'balance_date' => '2026-10-10', 'balance' => 50_000, 'invested_amount' => 40_000]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth?from=2026-09-01&to=2026-10-31')
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.granularity', 'monthly')
            ->assertJsonPath('data.net_worth', 160_000)
            ->assertJsonPath('data.previous_net_worth', 0)
            ->assertJsonPath('data.accounts', [
                ['id' => $checking->id, 'name' => 'Main', 'type' => 'checking', 'currency' => 'EUR', 'counts_in_net_worth' => true],
                ['id' => $broker->id, 'name' => 'Broker', 'type' => 'investment', 'currency' => 'USD', 'counts_in_net_worth' => true],
            ])
            ->assertJsonPath('data.evolution.0.period', '2026-09')
            ->assertJsonPath('data.evolution.1.period', '2026-10');

        expect($response->json('data.evolution.1.balances'))->toBe([
            ['account_id' => $checking->id, 'balance' => 120_000, 'original_balance' => null, 'original_currency' => null, 'invested_amount' => null],
            ['account_id' => $broker->id, 'balance' => 40_000, 'original_balance' => 50_000, 'original_currency' => 'USD', 'invested_amount' => 40_000],
        ]);
    });

    it('leaves out an account switched off in net worth, and flags a type that never counts', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $checking = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
        $card = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::CreditCard]);
        $loan = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Loan]);
        $leftOut = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking, 'include_in_net_worth' => false]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-10-01', 'balance' => 100_000]);
        AccountBalance::factory()->create(['account_id' => $loan->id, 'balance_date' => '2026-10-01', 'balance' => 30_000]);
        AccountBalance::factory()->create(['account_id' => $leftOut->id, 'balance_date' => '2026-10-01', 'balance' => 900_000]);

        $response = withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.net_worth', 70_000);

        expect(collect($response->json('data.accounts'))->pluck('counts_in_net_worth', 'id')->all())
            ->toBe([$checking->id => true, $card->id => false, $loan->id => true]);
    });

    it('answers an empty name for an account still encrypted on the device', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'name' => 'c2VjcmV0', 'name_iv' => 'aXZpdml2aXZpdml2', 'encrypted' => true]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.accounts.0.name', '')
            ->assertDontSee('c2VjcmV0');
    });

    it('reads what get_net_worth reads', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        $checking = Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
        AccountBalance::factory()->create(['account_id' => $checking->id, 'balance_date' => '2026-10-01', 'balance' => 100_000]);

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/net-worth'.OCTOBER)->json('data');
        $tool = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetNetWorth::class, ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk());

        expect($api['net_worth'])->toBe($tool['current']['current'])
            ->and($api['previous_net_worth'])->toBe($tool['current']['previous'])
            ->and($api['evolution'][0]['balances'][0]['balance'])->toBe($tool['evolution']['data'][0][$checking->id]);
    });

    it('leaves another member\'s accounts out, in a shared space too', function () {
        ['user' => $user, 'space' => $space] = analyticsHousemates();
        $mine = Account::factory()->create(['user_id' => $user->id, 'space_id' => $space->id, 'currency_code' => 'EUR', 'type' => AccountType::Checking]);
        AccountBalance::factory()->create(['account_id' => $mine->id, 'balance_date' => '2026-10-01', 'balance' => 1_000]);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.net_worth', 1_000)
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.id', $mine->id)
            ->assertDontSee('Their account');
    });

    it('reads one point per day with daily granularity', function () {
        $user = User::factory()->create(['currency_code' => 'EUR']);
        Account::factory()->create(['user_id' => $user->id, 'currency_code' => 'EUR']);

        withHeaders(apiV1Bearer($user))
            ->getJson('/api/v1/analytics/net-worth?from=2026-10-01&to=2026-10-03&granularity=daily')
            ->assertOk()
            ->assertJsonPath('data.granularity', 'daily')
            ->assertJsonCount(3, 'data.evolution')
            ->assertJsonPath('data.evolution.2.period', '2026-10-03');
    });

    it('never reads someone else\'s accounts', function () {
        Account::factory()->create(['user_id' => User::factory()->create()->id]);

        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/net-worth'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.net_worth', 0)
            ->assertJsonPath('data.accounts', []);
    });
});

describe('spending by category', function () {
    it('rolls expenses up into top-level categories, and drills into one', function () {
        ['user' => $user, 'food' => $food, 'groceries' => $groceries, 'rent' => $rent] = analyticsBook();
        $headers = apiV1Bearer($user);

        $top = withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.currency', 'EUR');

        expect($top->json('data.total'))->toBe(105_345)
            ->and(collect($top->json('data.categories'))->pluck('amount', 'category_id')->all())
            ->toBe([$rent->id => 90_000, $food->id => 15_345]);

        withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id=')
            ->assertOk()
            ->assertJsonPath('data.total', 105_345);

        $drilled = withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id='.$food->id)
            ->assertOk();

        expect(collect($drilled->json('data.categories'))->map(fn (array $row): array => [$row['category_id'], $row['amount'], $row['is_direct']])->all())
            ->toEqualCanonicalizing([[$groceries->id, 10_345, false], [$food->id, 5_000, true]]);
    });

    it('counts a split transaction line by line', function () {
        ['user' => $user, 'account' => $account, 'food' => $food, 'rent' => $rent] = analyticsBook();
        $split = Transaction::factory()->create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'category_id' => null,
            'amount' => -30_000,
            'currency_code' => 'EUR',
            'transaction_date' => '2026-10-08',
        ]);
        TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $food->id, 'amount' => -10_000, 'position' => 0]);
        TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $rent->id, 'amount' => -20_000, 'position' => 1]);

        $top = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/spending-by-category'.OCTOBER)->assertOk();

        expect(collect($top->json('data.categories'))->pluck('amount', 'category_id')->all())
            ->toBe([$rent->id => 110_000, $food->id => 25_345]);
    });

    it('reads what spending_by_category reads', function () {
        ['user' => $user] = analyticsBook();

        $api = withHeaders(apiV1Bearer($user))->getJson('/api/v1/analytics/spending-by-category'.OCTOBER)->json('data');
        $tool = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(SpendingByCategory::class, ['from' => '2026-10-01', 'to' => '2026-10-31'])->assertOk());

        expect(collect($api['categories'])->pluck('amount', 'category_id')->all())
            ->toEqualCanonicalizing(collect($tool['categories'])->pluck('amount', 'category_id')->all());
    });

    it('leaves another member\'s spending out, and refuses their category as a parent', function () {
        ['user' => $user, 'space' => $space] = analyticsHousemates();
        $theirs = Category::query()->where('space_id', $space->id)->sole();
        $headers = apiV1Bearer($user);

        withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER)
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        withHeaders($headers)
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id='.$theirs->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_category_id');
    });

    it('refuses a parent category that is not the user\'s', function () {
        withHeaders(apiV1Bearer(User::factory()->create()))
            ->getJson('/api/v1/analytics/spending-by-category'.OCTOBER.'&parent_category_id='.Category::factory()->create()->id)
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_category_id.0', 'The parent_category_id is not one of your categories.');
    });
});

it('refuses a missing or reversed range on every analytics endpoint', function (string $endpoint, string $query, string $field) {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson("/api/v1/analytics/{$endpoint}{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with(['cashflow', 'net-worth', 'spending-by-category'])->with([
    'no from' => ['?to=2026-10-31', 'from'],
    'no to' => ['?from=2026-10-01', 'to'],
    'not a date' => ['?from=yesterday-ish&to=2026-10-31', 'from'],
    'to before from' => ['?from=2026-10-31&to=2026-10-01', 'to'],
    'not a calendar date' => ['?from=2026-10-01T10:00:00&to=2026-10-31', 'from'],
    'over ten years' => ['?from=2016-10-01&to=2026-10-31', 'to'],
]);

it('refuses a range longer than the endpoint reports on', function (string $endpoint, string $query, string $message) {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson("/api/v1/analytics/{$endpoint}{$query}")
        ->assertUnprocessable()
        ->assertJsonPath('errors.to.0', $message);
})->with([
    'cashflow over 24 months' => ['cashflow', '?from=2024-10-28&to=2026-10-28', 'The range can touch at most 24 calendar months.'],
    'a daily net worth over a year' => ['net-worth', '?from=2025-01-01&to=2026-10-31&granularity=daily', 'A daily net worth can cover at most 366 days. Ask for monthly granularity for a longer range.'],
]);

it('takes the longest range each endpoint reports on', function (string $endpoint, string $query) {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson("/api/v1/analytics/{$endpoint}{$query}")
        ->assertOk();
})->with([
    'cashflow over 24 months' => ['cashflow', '?from=2024-11-01&to=2026-10-31'],
    'a daily net worth over a year' => ['net-worth', '?from=2025-10-31&to=2026-10-31&granularity=daily'],
    'a monthly net worth over ten years' => ['net-worth', '?from=2016-11-01&to=2026-10-31'],
]);

it('refuses a key the endpoint does not take, a space included, instead of answering for everything', function (string $endpoint, string $key) {
    $user = User::factory()->create();
    $value = $key === 'space' ? $user->personalSpace->id : 'x';

    withHeaders(apiV1Bearer($user))
        ->getJson("/api/v1/analytics/{$endpoint}".OCTOBER."&{$key}={$value}")
        ->assertUnprocessable()
        ->assertJsonPath("errors.{$key}.0", "This endpoint does not take a {$key} parameter.");
})->with(['cashflow', 'net-worth', 'spending-by-category'])->with([
    'space' => ['space'],
    'a misspelt filter' => ['category'],
]);

it('refuses a key sent in the body of a GET too', function () {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->json('GET', '/api/v1/analytics/cashflow'.OCTOBER, ['space' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('space');
});

it('answers a 422, not a 500, to a list sent for a single value', function (string $endpoint, string $query, string $field) {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson("/api/v1/analytics/{$endpoint}?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'from' => ['cashflow', 'from[]=2026-10-01&to=2026-10-31', 'from'],
    'to' => ['net-worth', 'from=2026-10-01&to[]=2026-10-31', 'to'],
    'granularity' => ['net-worth', 'from=2026-10-01&to=2026-10-31&granularity[]=daily', 'granularity'],
    'parent category' => ['spending-by-category', 'from=2026-10-01&to=2026-10-31&parent_category_id[]=x', 'parent_category_id'],
]);

it('refuses an unknown net worth granularity', function () {
    withHeaders(apiV1Bearer(User::factory()->create()))
        ->getJson('/api/v1/analytics/net-worth'.OCTOBER.'&granularity=weekly')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('granularity');
});

it('needs a bearer token', function (string $endpoint) {
    getJson("/api/v1/analytics/{$endpoint}".OCTOBER)->assertUnauthorized();
})->with(['cashflow', 'net-worth', 'spending-by-category']);
