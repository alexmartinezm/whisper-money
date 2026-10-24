<?php

use App\Enums\AccountType;
use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CashflowSummaryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/*
 * Characterization test: it pins the cashflow figures every consumer returns
 * today, for one invented household, so a change in how they are added up
 * (the SQL aggregation of upstream #1162) can prove it changes nothing.
 *
 * The golden file was generated from the code before that port and is only
 * read here. Every uuid in the output is replaced by the name of what it
 * points at, and lists keep the order they came back in: the order is part of
 * what must not change.
 *
 * Today's queries have no ORDER BY, so that order is the query plan's. On the
 * test database MySQL reads `accounts` first and each account's transactions
 * by id, so categories show up in the order of their first row account by
 * account, not by date (see the February sankey and the two rows recorded
 * late in March).
 */

const CASHFLOW_EQUIVALENCE_GOLDEN = __DIR__.'/../Fixtures/cashflow-equivalence.json';

beforeEach(function () {
    Http::fake();

    // Mid-March: March is the current period, February the previous one, and
    // the March budget periods are in progress.
    $this->travelTo(Carbon::parse('2026-03-18 12:00:00'));
});

/**
 * The invented household every consumer reads.
 *
 * Every row gets an id from a counter, in creation order, so the order rows
 * come back in never depends on a random uuid. Transactions are created in
 * date order except the two recorded late. `names` maps each id to a readable
 * name for the golden file.
 *
 * @return array{user: User, household: Space, personal: Space, names: array<string, string>, ids: array<string, string>}
 */
function cashflowEquivalenceWorld(): array
{
    $names = [];
    $counter = 0;
    $id = function (string $name) use (&$names, &$counter): string {
        $uuid = sprintf('00000000-0000-4000-8000-%012d', ++$counter);
        $names[$uuid] = $name;

        return $uuid;
    };

    $user = User::factory()->onboarded()->create(['id' => $id('user:Main'), 'currency_code' => 'EUR']);
    $partner = User::factory()->onboarded()->create(['id' => $id('user:Partner'), 'currency_code' => 'EUR']);
    $personal = $user->personalSpace()->firstOrFail();
    $names[$personal->id] = 'space:Main personal';
    $names[$partner->personalSpace()->firstOrFail()->id] = 'space:Partner personal';

    $household = Space::factory()->create(['id' => $id('space:Household'), 'owner_id' => $user->id, 'name' => 'Household']);
    $household->members()->attach($partner->id, ['id' => (string) Str::uuid(), 'role' => 'member']);

    // Rates are stored against the user's currency: an amount in USD is
    // divided by the USD rate. Four decimals, so conversions round.
    foreach ([
        '2026-01-15' => ['usd' => 1.0912, 'gbp' => 0.8433],
        '2026-02-03' => ['usd' => 1.0791, 'gbp' => 0.8402],
        '2026-02-20' => ['usd' => 1.0805, 'gbp' => 0.8366],
        '2026-03-04' => ['usd' => 1.0837, 'gbp' => 0.8571],
        '2026-03-06' => ['usd' => 1.0851, 'gbp' => 0.8571],
        '2026-03-10' => ['usd' => 1.0866, 'gbp' => 0.8590],
        '2026-03-11' => ['usd' => 1.0869, 'gbp' => 0.8594],
        '2026-03-12' => ['usd' => 1.0873, 'gbp' => 0.8601],
    ] as $date => $rates) {
        ExchangeRate::factory()->create(['base_currency' => 'eur', 'date' => $date, 'rates' => $rates]);
    }

    $account = fn (string $name, AccountType $type, string $currency, int $share = 100, ?User $owner = null, ?Space $space = null): Account => Account::factory()->create([
        'id' => $id("account:{$name}"),
        'user_id' => ($owner ?? $user)->id,
        'space_id' => ($space ?? $personal)->id,
        'name' => $name,
        'type' => $type,
        'currency_code' => $currency,
        'ownership_percentage' => $share,
    ]);

    $checking = $account('Checking EUR', AccountType::Checking, 'EUR');
    $dollars = $account('Checking USD', AccountType::Checking, 'USD');
    $sharedPounds = $account('Shared GBP 50%', AccountType::Checking, 'GBP', 50);
    $thirdEuros = $account('Shared EUR 33%', AccountType::Checking, 'EUR', 33);
    $savings = $account('Savings EUR', AccountType::Savings, 'EUR');
    $brokerage = $account('Brokerage USD', AccountType::Investment, 'USD');
    $archived = $account('Archived checking', AccountType::Checking, 'EUR');
    $trashed = $account('Trashed card USD 50%', AccountType::CreditCard, 'USD', 50);
    $joint = $account('Joint EUR', AccountType::Checking, 'EUR', space: $household);
    $partnerChecking = $account('Partner checking', AccountType::Checking, 'EUR', owner: $partner, space: $household);

    $category = function (string $name, CategoryType $type, ?Category $parent = null, ?CategoryCashflowDirection $direction = null, ?User $owner = null, ?Space $space = null) use ($id, $user, $personal): Category {
        // The factory draws a unique name from a list shorter than this
        // household's categories; the name is set here anyway.
        fake()->unique(true);

        return Category::factory()->create([
            'id' => $id("category:{$name}"),
            'user_id' => ($owner ?? $user)->id,
            'space_id' => ($space ?? $personal)->id,
            'name' => $name,
            'icon' => 'Tag',
            'color' => 'blue',
            'type' => $parent->type ?? $type,
            'cashflow_direction' => $parent->cashflow_direction ?? $direction ?? CategoryCashflowDirection::Hidden,
            'parent_id' => $parent?->id,
        ]);
    };

    $work = $category('Work', CategoryType::Income);
    $salary = $category('Salary', CategoryType::Income, $work);
    $bonus = $category('Bonus', CategoryType::Income, $work);
    $home = $category('Home', CategoryType::Expense);
    $rent = $category('Rent', CategoryType::Expense, $home);
    $utilities = $category('Utilities', CategoryType::Expense, $home);
    $food = $category('Food', CategoryType::Expense);
    $groceries = $category('Groceries', CategoryType::Expense, $food);
    $dining = $category('Dining', CategoryType::Expense, $food);
    $shopping = $category('Shopping', CategoryType::Expense);
    $fun = $category('Fun', CategoryType::Expense);
    $oldCategory = $category('Old category', CategoryType::Expense);
    $emergencyFund = $category('Emergency fund', CategoryType::Savings, direction: CategoryCashflowDirection::Outflow);
    $carFund = $category('Car fund', CategoryType::Savings, direction: CategoryCashflowDirection::Outflow);
    $indexFunds = $category('Index funds', CategoryType::Investment, direction: CategoryCashflowDirection::Outflow);
    $transferIn = $category('Transfer in', CategoryType::Transfer, direction: CategoryCashflowDirection::Inflow);
    $transferOut = $category('Transfer out', CategoryType::Transfer, direction: CategoryCashflowDirection::Outflow);
    $internalMove = $category('Internal move', CategoryType::Transfer, direction: CategoryCashflowDirection::Hidden);
    $gifts = $category('Gifts', CategoryType::Income);
    $pharmacy = $category('Pharmacy', CategoryType::Expense);
    $partnerSalary = $category('Partner salary', CategoryType::Income, owner: $partner, space: $household);
    $partnerGroceries = $category('Partner groceries', CategoryType::Expense, owner: $partner, space: $household);

    $label = fn (string $name): Label => Label::factory()->create([
        'id' => $id("label:{$name}"),
        'user_id' => $user->id,
        'space_id' => $personal->id,
        'name' => $name,
        'color' => 'teal',
    ]);

    $holiday = $label('Holiday');
    $subscription = $label('Subscription');
    $gone = $label('Gone');
    $untracked = $label('Untracked');

    /**
     * @param  string|null  $currency  null for the account's own; '' for none, which falls back to the account's
     * @param  list<Label>  $labels
     * @param  list<array{0: Category, 1: int}>  $lines  split lines, in position order
     */
    $transaction = function (string $name, Account $on, ?Category $in, int $amount, string $date, ?string $currency = null, array $labels = [], array $lines = []) use ($id): Transaction {
        $transaction = Transaction::factory()->plaintext()->create([
            'id' => $id("transaction:{$name}"),
            'user_id' => $on->user_id,
            'account_id' => $on->id,
            'category_id' => $in?->id,
            'description' => $name,
            'amount' => $amount,
            'currency_code' => $currency ?? $on->currency_code,
            'transaction_date' => $date,
        ]);

        foreach ($lines as $position => [$lineCategory, $lineAmount]) {
            TransactionSplit::factory()->create([
                'transaction_id' => $transaction->id,
                'category_id' => $lineCategory->id,
                'amount' => $lineAmount,
                'position' => $position,
            ]);
        }

        foreach ($labels as $attached) {
            $transaction->labels()->attach($attached);
        }

        return $transaction;
    };

    // January: only the trend, forMonths and the quarter and year summaries read it.
    $transaction('Jan salary', $checking, $salary, 250000, '2026-01-15');
    $janRent = $transaction('Jan rent', $checking, $rent, -95000, '2026-01-15');
    $transaction('Jan groceries USD', $dollars, $groceries, -4321, '2026-01-15');
    $transaction('Jan emergency fund out', $checking, $emergencyFund, -20000, '2026-01-20');
    $transaction('Jan emergency fund in', $savings, $emergencyFund, 20000, '2026-01-20');

    // February: the previous period.
    $transaction('Feb salary', $checking, $salary, 250000, '2026-02-03');
    $febRent = $transaction('Feb rent', $checking, $rent, -95000, '2026-02-03');
    $transaction('Feb fun, no currency on a USD account', $dollars, $fun, -5099, '2026-02-03', currency: '');
    $transaction('Feb archived fun', $archived, $fun, -444, '2026-02-10');
    $febSplit = $transaction('Feb food split', $checking, null, -12000, '2026-02-20', labels: [$holiday], lines: [[$groceries, -7000], [$dining, -5000]]);
    $transaction('Feb shopping refund', $checking, $shopping, 3000, '2026-02-20');
    $transaction('Feb uncategorized out', $checking, null, -1234, '2026-02-20');
    $transaction('Feb uncategorized in', $checking, null, 777, '2026-02-20');
    $transaction('Feb utilities GBP 50%', $sharedPounds, $utilities, -8765, '2026-02-20');
    $transaction('Feb partner salary', $partnerChecking, $partnerSalary, 180000, '2026-02-25');

    // March: the current period.
    $transaction('Mar partner salary', $partnerChecking, $partnerSalary, 180000, '2026-03-01');
    $transaction('Mar salary', $checking, $salary, 260000, '2026-03-02');
    $transaction('Mar bonus', $checking, $bonus, 15001, '2026-03-02');
    $marRentSplit = $transaction('Mar rent and sublet split', $checking, null, -40000, '2026-03-02', lines: [[$rent, -50000], [$salary, 10000]]);
    $marHolidaySplit = $transaction('Mar groceries and savings split', $checking, null, -30000, '2026-03-03', labels: [$holiday], lines: [[$groceries, -10000], [$emergencyFund, -20000]]);
    // Lines add up to -9999 against a -10001 parent: the last line takes the residue.
    $transaction('Mar USD split with residue', $dollars, $shopping, -10001, '2026-03-04', lines: [[$dining, -3333], [$fun, -3333], [$shopping, -3333]]);
    $transaction('Mar archived groceries before archiving', $archived, $groceries, -1111, '2026-03-04');
    $transaction('Mar archived groceries on archive day', $archived, $groceries, -2222, '2026-03-05');
    $transaction('Mar GBP 50% split', $sharedPounds, null, -5001, '2026-03-06', lines: [[$groceries, -2501], [$dining, -2500]]);
    $transaction('Mar groceries 33%', $thirdEuros, $groceries, -1001, '2026-03-07');
    $transaction('Mar fun 33%', $thirdEuros, $fun, -2999, '2026-03-07');
    $transaction('Mar 33% split', $thirdEuros, null, -1003, '2026-03-07', lines: [[$shopping, -501], [$fun, -501]]);
    $transaction('Mar shopping under a trashed label', $checking, $shopping, -2000, '2026-03-08', labels: [$gone, $untracked]);
    $transaction('Mar shopping refund', $checking, $shopping, 6500, '2026-03-08');
    $marSubscription = $transaction('Mar streaming subscription', $checking, $fun, -1299, '2026-03-08', labels: [$subscription]);
    $marDining = $transaction('Mar dining', $checking, $dining, -4550, '2026-03-09');
    $transaction('Mar utilities', $checking, $utilities, -6789, '2026-03-09');
    $transaction('Mar home direct', $checking, $home, -1500, '2026-03-09');
    $transaction('Mar uncategorized out', $checking, null, -2345, '2026-03-10');
    $transaction('Mar uncategorized in USD', $dollars, null, 1111, '2026-03-10');
    $transaction('Mar transfer in', $checking, $transferIn, 30000, '2026-03-10');
    $transaction('Mar transfer out', $checking, $transferOut, -12000, '2026-03-10');
    $transaction('Mar internal move out', $checking, $internalMove, -50000, '2026-03-10');
    $transaction('Mar internal move in', $savings, $internalMove, 50000, '2026-03-10');
    $transaction('Mar emergency fund out', $checking, $emergencyFund, -25000, '2026-03-11');
    $transaction('Mar emergency fund in', $savings, $emergencyFund, 25000, '2026-03-11');
    $transaction('Mar emergency fund withdrawal', $checking, $emergencyFund, 5000, '2026-03-11');
    $transaction('Mar car fund withdrawal', $checking, $carFund, 8000, '2026-03-11');
    $transaction('Mar index funds out', $checking, $indexFunds, -40000, '2026-03-11');
    $transaction('Mar index funds in USD', $brokerage, $indexFunds, 43474, '2026-03-11');
    $transaction('Mar archived groceries after archiving', $archived, $groceries, -3333, '2026-03-12');
    $transaction('Mar trashed account fun, no currency', $trashed, $fun, -1000, '2026-03-12', currency: '');
    $transaction('Mar trashed account fun USD', $trashed, $fun, -2000, '2026-03-12');
    $transaction('Mar trashed category', $checking, $oldCategory, -7777, '2026-03-12');
    $transaction('Mar groceries in USD on a EUR account', $checking, $groceries, -2500, '2026-03-12', currency: 'USD');
    $transaction('Mar fun in EUR on a USD account', $dollars, $fun, -1800, '2026-03-12', currency: 'EUR');
    $transaction('Mar salary, no currency on a USD account', $dollars, $salary, 1234, '2026-03-12', currency: '');
    $transaction('Mar joint groceries', $joint, $groceries, -6000, '2026-03-13');
    $transaction('Mar partner groceries', $partnerChecking, $partnerGroceries, -4567, '2026-03-13');
    // Recorded last but dated first: their ids sort after every other March
    // row while their date sorts before, so the first-seen order of the
    // categories shows which of the two the rows come back in.
    $transaction('Mar gift, recorded late', $checking, $gifts, 4321, '2026-03-01');
    $transaction('Mar pharmacy, recorded late', $checking, $pharmacy, -999, '2026-03-01');

    // Budgets come after the transactions, so nothing assigns them on the way
    // in: what each period has spent is set below, as stored.
    $budget = function (string $name, BudgetPeriodType $type, BudgetExpenseType $expenseType, array $categories = [], array $labels = [], bool $catchAll = false) use ($id, $user, $personal): Budget {
        $budget = Budget::factory()->create([
            'id' => $id("budget:{$name}"),
            'user_id' => $user->id,
            'space_id' => $personal->id,
            'name' => $name,
            'period_type' => $type,
            'period_start_day' => 1,
            'expense_type' => $expenseType,
            'is_catch_all' => $catchAll,
        ]);
        $budget->categories()->sync(array_map(fn (Category $category): string => $category->id, $categories));
        $budget->labels()->sync(array_map(fn (Label $label): string => $label->id, $labels));

        return $budget;
    };
    $period = function (Budget $budget, string $start, string $end, int $allocated, ?Transaction $spentOn = null, int $spent = 0) use ($id): BudgetPeriod {
        $period = BudgetPeriod::factory()->create([
            'id' => $id("budget period:{$budget->name} {$start}"),
            'budget_id' => $budget->id,
            'start_date' => $start,
            'end_date' => $end,
            'allocated_amount' => $allocated,
            'carried_over_amount' => 0,
        ]);

        if ($spentOn !== null) {
            BudgetTransaction::factory()->create(['transaction_id' => $spentOn->id, 'budget_period_id' => $period->id, 'amount' => $spent]);
        }

        return $period;
    };

    $housing = $budget('Housing', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, categories: [$home]);
    $period($housing, '2026-02-01', '2026-02-28', 100000, $febRent, 95000);
    $period($housing, '2026-03-01', '2026-03-31', 100000, $marRentSplit, 50000);
    $period($housing, '2026-01-01', '2026-01-31', 100000, $janRent, 95000);

    $foodBudget = $budget('Food budget', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, categories: [$food]);
    $period($foodBudget, '2026-02-01', '2026-02-28', 40000, $febSplit, 12000);
    // Over budget: a negative margin that reserves nothing.
    $period($foodBudget, '2026-03-01', '2026-03-31', 40000, $marHolidaySplit, 45000);

    $holidays = $budget('Holidays', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, labels: [$holiday]);
    $period($holidays, '2026-03-01', '2026-03-31', 30000, $marHolidaySplit, 10000);

    // Tracks a label that is trashed below: it no longer reaches anything.
    $subscriptions = $budget('Subscriptions', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, labels: [$subscription, $gone]);
    $period($subscriptions, '2026-03-01', '2026-03-31', 5000, $marSubscription, 1299);

    // Dining is tracked by this fixed budget and by the variable Food budget: fixed wins.
    $diningOut = $budget('Dining out', BudgetPeriodType::Monthly, BudgetExpenseType::Fixed, categories: [$dining]);
    $period($diningOut, '2026-03-01', '2026-03-31', 10000, $marDining, 4550);

    $catchAll = $budget('Everything else', BudgetPeriodType::Monthly, BudgetExpenseType::Variable, catchAll: true);
    $period($catchAll, '2026-03-01', '2026-03-31', 20000, $marDining, 7000);

    $yearlyFun = $budget('Yearly fun', BudgetPeriodType::Yearly, BudgetExpenseType::Variable, categories: [$fun]);
    $period($yearlyFun, '2026-01-01', '2026-12-31', 60000, $marSubscription, 9000);

    $archived->forceFill(['archived_at' => '2026-03-05 10:00:00'])->save();
    $trashed->delete();
    $oldCategory->delete();
    $gone->delete();

    return [
        'user' => $user,
        'household' => $household,
        'personal' => $personal,
        'names' => $names,
        'ids' => array_flip($names),
    ];
}

/**
 * Every uuid replaced by the name of what it points at, keys included; an id
 * nobody named fails the test rather than landing in the golden file. Encoded
 * and decoded once, so the service outputs and the endpoint responses compare
 * the same way, and a float stays a float.
 *
 * @param  array<string, string>  $names
 */
function cashflowEquivalenceNormalize(mixed $value, array $names): mixed
{
    $value = json_decode(json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    $rename = function (mixed $value) use (&$rename, $names): mixed {
        if (is_array($value)) {
            $renamed = [];
            foreach ($value as $key => $item) {
                $renamed[is_string($key) ? $rename($key) : $key] = $rename($item);
            }

            return $renamed;
        }

        if (is_string($value) && Str::isUuid($value)) {
            expect($names)->toHaveKey($value);

            return $names[$value];
        }

        return $value;
    };

    return $rename($value);
}

/**
 * The full output of every cashflow consumer for the household, keyed by a
 * name saying which call it is.
 *
 * @param  array{user: User, household: Space, personal: Space, names: array<string, string>, ids: array<string, string>}  $world
 * @return array<string, mixed>
 */
function cashflowEquivalenceOutputs(array $world): array
{
    $user = $world['user'];
    $ids = $world['ids'];
    test()->actingAs($user);

    $api = fn (string $endpoint, array $query): array => test()
        ->getJson("/api/cashflow/{$endpoint}?".http_build_query($query))
        ->assertOk()
        ->json();
    $march = ['from' => '2026-03-01', 'to' => '2026-03-31'];
    $february = ['from' => '2026-02-01', 'to' => '2026-02-28'];

    $outputs = [
        'summary march' => $api('summary', $march),
        'summary first days of march' => $api('summary', ['from' => '2026-03-01', 'to' => '2026-03-09']),
        'summary first quarter' => $api('summary', ['from' => '2026-01-01', 'to' => '2026-03-31']),
        'summary year' => $api('summary', ['from' => '2026-01-01', 'to' => '2026-12-31']),
        'sankey march' => $api('sankey', $march),
        'sankey february' => $api('sankey', $february),
        'sankey march drilled into Home' => $api('sankey', [...$march, 'parent' => $ids['category:Home']]),
        'sankey march drilled into Food' => $api('sankey', [...$march, 'parent' => $ids['category:Food']]),
        'sankey march drilled into Work' => $api('sankey', [...$march, 'parent' => $ids['category:Work']]),
        'trend default' => $api('trend', []),
        'trend 3 months' => $api('trend', ['months' => 3]),
        'trend january to march' => $api('trend', ['from' => '2026-01-01', 'to' => '2026-03-31']),
        'breakdown march expense' => $api('breakdown', [...$march, 'type' => 'expense']),
        'breakdown march income' => $api('breakdown', [...$march, 'type' => 'income']),
        'breakdown march expense drilled into Home' => $api('breakdown', [...$march, 'type' => 'expense', 'parent' => $ids['category:Home']]),
        'breakdown march expense drilled into Food' => $api('breakdown', [...$march, 'type' => 'expense', 'parent' => $ids['category:Food']]),
        'breakdown march income drilled into Work' => $api('breakdown', [...$march, 'type' => 'income', 'parent' => $ids['category:Work']]),
        'dashboard cashflowSummary' => test()->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'cashflowSummary',
        ])->assertOk()->json('props.cashflowSummary'),
    ];

    $summaries = app(CashflowSummaryService::class);
    $outputs['forMonths'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'));
    $outputs['forMonths household space'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $world['household']->id);
    $outputs['forMonths personal space'] = $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-01-01'), Carbon::parse('2026-03-31'), $world['personal']->id);

    $outputs['mcp get_cashflow march'] = mcpPayload(WhisperMoneyServer::actingAs($user)->tool(GetCashflow::class, $march)->assertOk());

    return cashflowEquivalenceNormalize($outputs, $world['names']);
}

test('every cashflow consumer returns the figures pinned before the SQL port', function () {
    $outputs = cashflowEquivalenceOutputs(cashflowEquivalenceWorld());

    $golden = json_decode((string) file_get_contents(CASHFLOW_EQUIVALENCE_GOLDEN), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($outputs))->toBe(array_keys($golden));

    foreach ($golden as $call => $expected) {
        expect($outputs[$call])->toBe($expected, "The output of {$call} changed.");
    }
});

test('the pinned figures add up by hand', function () {
    $outputs = cashflowEquivalenceOutputs(cashflowEquivalenceWorld());
    $march = $outputs['summary march']['current'];
    $sankeyIncome = collect($outputs['sankey march']['income_categories'])->keyBy('category.name');

    // Income, March, in EUR (user Main only; the household partner's rows are not theirs):
    //   salary 260000 + bonus 15001 + sublet split line 10000
    //   + salary 1234 USD with no currency on the USD account: 1234 / 1.0873 = 1134.92 -> 1135
    //   + uncategorized inflow 1111 USD: 1111 / 1.0866 = 1022.46 -> 1022
    //   + gift 4321
    //   = 291479
    expect($march['income'])->toBe(260000 + 15001 + 10000 + 1135 + 1022 + 4321);

    // Expense, March, signed (refunds net out). Lines of split transactions count on their own:
    //   rent line 50000; groceries line 10000
    //   USD split -10001 at 1.0837: parent -9229, dining -3076, fun -3076, shopping (residue) -3077
    //   archived account before archiving 1111 (the archive day and after are out)
    //   GBP 50% split -5001 at 0.8571: parent -5835, groceries -2918 -> 1459, dining (residue) -2917 -> -1458.5 -> 1459
    //   33% account: groceries -1001 -> 330, fun -2999 -> 990, split lines -501 and -502 (residue) -> 165 + 166
    //   shopping 2000 - refund 6500, subscription 1299, dining 4550, utilities 6789, home 1500
    //   uncategorized outflow 2345
    //   trashed account: -1000 with no currency reads as EUR, -2000 USD at 1.0873 -> 1839, both in full
    //   (the account is not loaded, so neither its currency nor its share applies)
    //   trashed category 7777: out of every figure
    //   groceries 2500 USD on a EUR account at 1.0873 -> 2299; fun 1800 EUR on a USD account
    //   joint account groceries 6000; pharmacy 999
    $expectedExpense = 50000 + 10000 + 3076 + 3076 + 3077 + 1111 + 1459 + 1459 + 330 + 990 + 165 + 166
        + 2000 - 6500 + 1299 + 4550 + 6789 + 1500 + 2345 + 1000 + 1839 + 2299 + 1800 + 6000 + 999;
    expect($march['expense'])->toBe($expectedExpense)
        ->and($march['net'])->toBe($march['income'] - $march['expense']);

    // Fixed: what Housing (Home tree), Dining out (dining) and Subscriptions (label) track:
    //   rent line 50000 + utilities 6789 + home 1500
    //   + dining 4550 + USD split dining line 3076 + GBP split dining line 1459 (fixed beats Food's variable)
    //   + subscription 1299 (fixed label beats Yearly fun's variable category)
    // Variable: Food budget (groceries) and Yearly fun (fun), and the Holiday label:
    //   groceries line 10000 + archived 1111 + GBP line 1459 + 33% 330 + USD-on-EUR 2299 + joint 6000
    //   + fun: USD split line 3076 + 33% 990 + 33% split line 166 + trashed account 1000 + 1839 + 1800
    // General: what nothing tracks, the trashed label included:
    //   shopping: USD split residue line 3077 + 33% split line 165 + 2000 - 6500; uncategorized 2345;
    //   pharmacy 999
    expect($march['fixed_expense'])->toBe(50000 + 6789 + 1500 + 4550 + 3076 + 1459 + 1299)
        ->and($march['variable_expense'])->toBe(10000 + 1111 + 1459 + 330 + 2299 + 6000 + 3076 + 990 + 166 + 1000 + 1839 + 1800)
        ->and($march['general_expense'])->toBe(3077 + 165 + 2000 - 6500 + 2345 + 999)
        ->and($march['fixed_expense'] + $march['variable_expense'] + $march['general_expense'])->toBe($march['expense']);

    // Savings: the checking legs only (the savings-account legs are skipped):
    //   split line 20000 + 25000 - withdrawal 5000 - car fund withdrawal 8000 = 32000
    // Investments: 40000 out of checking; the brokerage leg is skipped.
    expect($march['savings'])->toBe(32000)
        ->and($march['investments'])->toBe(40000);

    // Budgets in progress on 18 March, in start-date order: Food budget is over (-5000) and
    // reserves nothing; the catch-all still reserves its margin; Yearly fun is not a month budget.
    //   Housing 50000 + Holidays 20000 + Subscriptions 3701 + Dining out 5450 + Everything else 13000
    expect($march['reserved'])->toBe(50000 + 20000 + 3701 + 5450 + 13000)
        ->and(collect($march['budgets'])->firstWhere('name', 'Food budget')['margin'])->toBe(-5000);

    // Sankey crossovers: shopping nets positive in March (-3077 - 165 - 2000 + 6500 = +1258) and
    // crosses to the income side as a refund; the car fund withdrawal crosses as a withdrawal.
    expect($sankeyIncome->get('Shopping (refund)')['amount'])->toBe(1258)
        ->and($sankeyIncome->get('Car fund (withdrawal)')['amount'])->toBe(8000)
        ->and($sankeyIncome->get('Transfer in')['amount'])->toBe(30000);

    // forMonths in the household space counts both members' rows there:
    // partner salary 180000 in; joint groceries 6000 + partner groceries 4567 out.
    expect($outputs['forMonths household space']['2026-03'])->toMatchArray([
        'income' => 180000,
        'expense' => 6000 + 4567,
    ]);
});
