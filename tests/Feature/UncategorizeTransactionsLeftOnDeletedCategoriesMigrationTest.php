<?php

use App\Models\Account;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\BudgetTransaction;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function runUncategorizeTransactionsLeftOnDeletedCategoriesMigration(): void
{
    $migration = require database_path('migrations/2026_08_25_090000_uncategorize_transactions_left_on_deleted_categories.php');
    $migration->up();
}

function transactionUnderRepair(Category $category): Transaction
{
    return Transaction::factory()->plaintext()->create([
        'user_id' => $category->user_id,
        'account_id' => Account::factory()->create(['user_id' => $category->user_id])->id,
        'category_id' => $category->id,
    ]);
}

it('cuts loose the transactions pointing at a deleted category', function () {
    $user = User::factory()->create();
    $dead = Category::factory()->create(['user_id' => $user->id]);
    $alive = Category::factory()->create(['user_id' => $user->id]);

    $orphan = transactionUnderRepair($dead);
    $kept = transactionUnderRepair($alive);

    $dead->delete();

    runUncategorizeTransactionsLeftOnDeletedCategoriesMigration();

    expect($orphan->fresh()->category_id)->toBeNull()
        ->and($kept->fresh()->category_id)->toBe($alive->id);
});

it('reaches the deleted transactions too, so an undelete does not bring the dead id back', function () {
    $user = User::factory()->create();
    $dead = Category::factory()->create(['user_id' => $user->id]);

    $transaction = transactionUnderRepair($dead);
    $transaction->delete();
    $dead->delete();

    runUncategorizeTransactionsLeftOnDeletedCategoriesMigration();

    expect(Transaction::withTrashed()->find($transaction->id)->category_id)->toBeNull();
});

it('leaves split history and budget assignments unchanged', function () {
    $user = User::factory()->create();
    $spaceId = $user->personalSpace->id;
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
    ]);
    $food = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
    ]);
    $home = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
    ]);
    $splitParent = Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
        'account_id' => $account->id,
        'category_id' => null,
        'amount' => -10000,
        'transaction_date' => '2026-08-01',
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $splitParent->id,
        'category_id' => $food->id,
        'amount' => -6000,
        'position' => 0,
    ]);
    TransactionSplit::factory()->create([
        'transaction_id' => $splitParent->id,
        'category_id' => $home->id,
        'amount' => -4000,
        'position' => 1,
    ]);
    $budget = Budget::factory()->forCategories($food)->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
    ]);
    $period = BudgetPeriod::factory()->create([
        'budget_id' => $budget->id,
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);
    $assignment = BudgetTransaction::factory()->create([
        'transaction_id' => $splitParent->id,
        'budget_period_id' => $period->id,
        'amount' => 6000,
    ]);
    $dead = Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $spaceId,
    ]);
    $dangling = transactionUnderRepair($dead);
    $dead->delete();

    $beforeParent = (array) DB::table('transactions')->where('id', $splitParent->id)->first();
    $beforeSplits = DB::table('transaction_splits')
        ->where('transaction_id', $splitParent->id)
        ->orderBy('position')
        ->get()
        ->map(fn (object $split): array => (array) $split)
        ->all();
    $beforeAssignment = (array) DB::table('budget_transactions')->where('id', $assignment->id)->first();

    runUncategorizeTransactionsLeftOnDeletedCategoriesMigration();

    $afterParent = (array) DB::table('transactions')->where('id', $splitParent->id)->first();
    $afterSplits = DB::table('transaction_splits')
        ->where('transaction_id', $splitParent->id)
        ->orderBy('position')
        ->get()
        ->map(fn (object $split): array => (array) $split)
        ->all();
    $afterAssignment = (array) DB::table('budget_transactions')->where('id', $assignment->id)->first();

    expect($afterParent)->toBe($beforeParent)
        ->and($afterSplits)->toBe($beforeSplits)
        ->and($afterAssignment)->toBe($beforeAssignment)
        ->and($dangling->fresh()->category_id)->toBeNull();
});
