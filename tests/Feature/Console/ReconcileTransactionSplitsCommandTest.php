<?php

use App\Enums\CategoryType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function reconciliationFixture(): array
{
    $transaction = Transaction::factory()->create(['amount' => -10000, 'category_id' => null, 'transaction_date' => now()]);
    $categories = collect([1, 2])->map(fn () => Category::factory()->create([
        'user_id' => $transaction->user_id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]));
    collect([-6000, -4000])->each(fn (int $amount, int $position) => TransactionSplit::factory()->create([
        'transaction_id' => $transaction->id,
        'category_id' => $categories[$position]->id,
        'amount' => $amount,
        'position' => $position,
    ]));
    $budget = Budget::factory()->forCategories($categories[0])->create(['user_id' => $transaction->user_id]);
    $period = BudgetPeriod::factory()->create(['budget_id' => $budget->id, 'start_date' => now()->subDay(), 'end_date' => now()->addDay()]);
    BudgetTransaction::query()->create(['transaction_id' => $transaction->id, 'budget_period_id' => $period->id, 'amount' => 999]);

    return [$transaction, $period];
}

it('defaults to a write free dry run', function () {
    [$transaction] = reconciliationFixture();
    $before = [DB::table('transactions')->where('id', $transaction->id)->first(), DB::table('transaction_splits')->orderBy('position')->get()->all(), DB::table('budget_transactions')->get()->all()];

    $this->artisan('transactions:reconcile-splits')->assertSuccessful();

    expect([DB::table('transactions')->where('id', $transaction->id)->first(), DB::table('transaction_splits')->orderBy('position')->get()->all(), DB::table('budget_transactions')->get()->all()])->toEqual($before);
});

it('reconciles budgets touches parent once and remains idempotent without changing lines', function () {
    [$transaction, $period] = reconciliationFixture();
    $oldTimestamp = $transaction->updated_at;
    $lines = DB::table('transaction_splits')->orderBy('position')->get()->all();

    $this->artisan('transactions:reconcile-splits', ['--execute' => true, '--chunk' => 1])->assertSuccessful();

    expect(BudgetTransaction::query()->where('budget_period_id', $period->id)->sole()->amount)->toBe(6000)
        ->and($transaction->fresh()->updated_at->gt($oldTimestamp))->toBeTrue()
        ->and(DB::table('transaction_splits')->orderBy('position')->get()->all())->toEqual($lines);

    $firstTimestamp = $transaction->fresh()->updated_at;
    $this->artisan('transactions:reconcile-splits', ['--execute' => true])->assertSuccessful();
    expect(BudgetTransaction::query()->where('budget_period_id', $period->id)->count())->toBe(1)
        ->and(BudgetTransaction::query()->where('budget_period_id', $period->id)->sole()->amount)->toBe(6000)
        ->and($transaction->fresh()->updated_at->gt($firstTimestamp))->toBeTrue();
});

it('blocks execute when audit finds invalid data', function () {
    [$transaction] = reconciliationFixture();
    DB::table('transaction_splits')->where('transaction_id', $transaction->id)->limit(1)->update(['amount' => 0]);

    $this->artisan('transactions:reconcile-splits', ['--execute' => true])->assertFailed();
    expect(BudgetTransaction::query()->where('transaction_id', $transaction->id)->sole()->amount)->toBe(999);
});
