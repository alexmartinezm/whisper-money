<?php

use App\Enums\BudgetExpenseType;
use App\Enums\CategoryType;
use App\Mcp\Servers\WhisperMoneyServer;
use App\Mcp\Tools\GetCashflow;
use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use App\Services\CashflowSummaryService;
use App\Services\PeriodComparator;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The cashflow figures are added up in SQL: an unsplit transaction is never
 * hydrated on the way, whatever the consumer. Only split transactions are,
 * once per read of the window, to be expanded into their lines.
 */

beforeEach(function () {
    Http::fake();
    $this->travelTo(Carbon::parse('2026-05-15 12:00:00'));

    $this->user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $this->actingAs($this->user);

    $account = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'EUR', 'ownership_percentage' => 50]);
    $dollars = Account::factory()->create(['user_id' => $this->user->id, 'currency_code' => 'USD']);
    $this->groceries = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Expense]);
    $salary = Category::factory()->create(['user_id' => $this->user->id, 'type' => CategoryType::Income]);
    $tracked = Label::factory()->create(['user_id' => $this->user->id]);

    // A budget tracking a label, so the summary groups by label too.
    $budget = Budget::factory()->monthly()->create(['user_id' => $this->user->id, 'period_start_day' => 1, 'expense_type' => BudgetExpenseType::Fixed, 'is_catch_all' => false]);
    $budget->labels()->sync([$tracked->id]);
    BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => '2026-05-01', 'end_date' => '2026-05-31', 'allocated_amount' => 10000]);

    $this->accountId = $account->id;

    foreach (['2026-04-08', '2026-05-02', '2026-05-09'] as $date) {
        foreach ([[$account, $this->groceries, -1234], [$account, $salary, 250000], [$dollars, $this->groceries, -999], [$account, null, -500]] as [$on, $category, $amount]) {
            Transaction::factory()->create([
                'user_id' => $this->user->id,
                'account_id' => $on->id,
                'category_id' => $category?->id,
                'amount' => $amount,
                'currency_code' => $on->currency_code,
                'transaction_date' => $date,
            ])->labels()->attach($tracked);
        }
    }
});

/**
 * Two split transactions in May, the month every consumer below reads.
 */
function cashflowHydrationSplits(object $test): void
{
    foreach (['2026-05-03', '2026-05-12'] as $date) {
        $split = Transaction::factory()->create([
            'user_id' => $test->user->id,
            'account_id' => $test->accountId,
            'category_id' => null,
            'amount' => -3000,
            'currency_code' => 'EUR',
            'transaction_date' => $date,
        ]);

        foreach ([-1000, -2000] as $position => $amount) {
            TransactionSplit::factory()->create(['transaction_id' => $split->id, 'category_id' => $test->groceries->id, 'amount' => $amount, 'position' => $position]);
        }
    }
}

/**
 * How many Transaction models each cashflow consumer hydrates.
 *
 * @return array<string, int>
 */
function cashflowHydratedTransactions(object $test): array
{
    $user = $test->user;
    $may = ['from' => '2026-05-01', 'to' => '2026-05-31'];
    $summaries = app(CashflowSummaryService::class);
    $period = new PeriodComparator(Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')->endOfDay());

    $consumers = [
        'summary' => fn () => $test->getJson('/api/cashflow/summary?'.http_build_query($may))->assertOk(),
        'sankey' => fn () => $test->getJson('/api/cashflow/sankey?'.http_build_query($may))->assertOk(),
        'trend' => fn () => $test->getJson('/api/cashflow/trend?'.http_build_query(['months' => 2, 'to' => '2026-05-31']))->assertOk(),
        'breakdown' => fn () => $test->getJson('/api/cashflow/breakdown?'.http_build_query([...$may, 'type' => 'expense']))->assertOk(),
        'dashboard cashflowSummary' => fn () => $test->get(route('dashboard'), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'dashboard',
            'X-Inertia-Partial-Data' => 'cashflowSummary',
        ])->assertOk(),
        'forComparedPeriods' => fn () => $summaries->forComparedPeriods($user->id, 'EUR', $period, $period->previous()),
        'forMonths' => fn () => $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-04-01'), Carbon::parse('2026-05-31')),
        'forMonths in a space' => fn () => $summaries->forMonths($user->id, 'EUR', Carbon::parse('2026-04-01'), Carbon::parse('2026-05-31'), $user->personalSpace()->firstOrFail()->id),
        'get_cashflow' => fn () => WhisperMoneyServer::actingAs($user)->tool(GetCashflow::class, $may)->assertOk(),
    ];

    $hydrated = 0;
    Event::listen('eloquent.retrieved: '.Transaction::class, function () use (&$hydrated): void {
        $hydrated++;
    });

    return array_map(function (Closure $consume) use (&$hydrated): int {
        $hydrated = 0;
        $consume();

        return $hydrated;
    }, $consumers);
}

test('no cashflow consumer hydrates an unsplit transaction', function () {
    expect(cashflowHydratedTransactions($this))->toBe([
        'summary' => 0,
        'sankey' => 0,
        'trend' => 0,
        'breakdown' => 0,
        'dashboard cashflowSummary' => 0,
        'forComparedPeriods' => 0,
        'forMonths' => 0,
        'forMonths in a space' => 0,
        'get_cashflow' => 0,
    ]);
});

test('split transactions are the only ones hydrated, once per read of the window', function () {
    cashflowHydrationSplits($this);

    // Two split parents per read. The breakdown reads the month and the one
    // before it apart, and the previous month has none; get_cashflow reads the
    // summary, the sankey and the trend.
    expect(cashflowHydratedTransactions($this))->toBe([
        'summary' => 2,
        'sankey' => 2,
        'trend' => 2,
        'breakdown' => 2,
        'dashboard cashflowSummary' => 2,
        'forComparedPeriods' => 2,
        'forMonths' => 2,
        'forMonths in a space' => 2,
        'get_cashflow' => 6,
    ]);
});
