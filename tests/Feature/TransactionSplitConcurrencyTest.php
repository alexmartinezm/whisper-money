<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Support\Facades\DB;

$concurrencyUserIds = [];

afterEach(function () use (&$concurrencyUserIds): void {
    DB::rollBack();
    User::withoutEvents(fn () => User::query()->whereIn('id', $concurrencyUserIds)->forceDelete());
    $concurrencyUserIds = [];
    DB::beginTransaction();
});

function concurrencyFixture(): array
{
    global $concurrencyUserIds;
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $concurrencyUserIds[] = $transaction->user_id;
    $categories = collect(range(1, 4))->map(fn () => Category::factory()->create([
        'user_id' => $transaction->user_id,
        'space_id' => $transaction->space_id,
        'type' => CategoryType::Expense,
    ]));
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $categories[0]->id, 'amount' => -6000],
        ['category_id' => $categories[1]->id, 'amount' => -4000],
    ]);

    return [$transaction, $categories];
}

/** @param list<Closure(): void> $writers */
function runSplitWriters(array $writers): void
{
    DB::commit();
    DB::disconnect();
    $children = [];

    foreach ($writers as $writer) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            usleep(25000);
            try {
                $writer();
                exit(0);
            } catch (Throwable) {
                exit(2);
            }
        }
        $children[] = $pid;
    }

    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        expect(pcntl_wexitstatus($status))->toBeIn([0, 2]);
    }
    DB::purge();
}

function mutateCategoryWithApplicationLocks(string $categoryId, Closure $mutation): void
{
    DB::transaction(function () use ($categoryId, $mutation): void {
        $category = Category::query()->findOrFail($categoryId);
        $locked = app(CategoryTree::class)->lockSubtreeForMutation($category);
        $mutation($locked);
    }, attempts: 5);
}

function assertCompleteSplitState(Transaction $transaction): void
{
    $fresh = $transaction->fresh('splits');
    expect($fresh->splits->count())->toBeIn([0, 2]);
    if ($fresh->splits->isNotEmpty()) {
        expect((int) $fresh->splits->sum('amount'))->toBe($fresh->amount)
            ->and($fresh->category_id)->toBeNull();
    } else {
        expect($fresh->category_id)->not->toBeNull();
    }
}

it('serializes two competing replacements without combining payloads', function () {
    [$transaction, $categories] = concurrencyFixture();
    $payloadA = [['category_id' => $categories[0]->id, 'amount' => -2500], ['category_id' => $categories[1]->id, 'amount' => -7500]];
    $payloadB = [['category_id' => $categories[2]->id, 'amount' => -7000], ['category_id' => $categories[3]->id, 'amount' => -3000]];

    runSplitWriters([
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payloadA),
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payloadB),
    ]);

    assertCompleteSplitState($transaction);
    expect($transaction->fresh('splits')->splits->pluck('amount')->all())->toBeIn([[-2500, -7500], [-7000, -3000]]);
});

it('keeps a complete state when replace competes with unsplit', function () {
    [$transaction, $categories] = concurrencyFixture();
    $payload = [['category_id' => $categories[0]->id, 'amount' => -2500], ['category_id' => $categories[1]->id, 'amount' => -7500]];

    runSplitWriters([
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payload),
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), [], $categories[2]->id),
    ]);

    assertCompleteSplitState($transaction);
});

it('never references a deleted category when replace competes with delete', function () {
    [$transaction, $categories] = concurrencyFixture();
    $payload = [['category_id' => $categories[2]->id, 'amount' => -2500], ['category_id' => $categories[3]->id, 'amount' => -7500]];

    runSplitWriters([
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payload),
        fn () => mutateCategoryWithApplicationLocks(
            $categories[2]->id,
            fn (Category $category) => $category->delete(),
        ),
    ]);

    assertCompleteSplitState($transaction);
    expect($transaction->fresh('splits')->splits->filter(fn ($split) => $split->category()->withTrashed()->first()?->trashed())->count())->toBe(0);
});

it('keeps category types valid when replace competes with type mutation', function () {
    [$transaction, $categories] = concurrencyFixture();
    $payload = [['category_id' => $categories[2]->id, 'amount' => -2500], ['category_id' => $categories[3]->id, 'amount' => -7500]];

    runSplitWriters([
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payload),
        fn () => mutateCategoryWithApplicationLocks(
            $categories[2]->id,
            fn (Category $category) => $category->update(['type' => CategoryType::Income]),
        ),
    ]);

    assertCompleteSplitState($transaction);
    $types = $transaction->fresh('splits')->splits->map(fn ($split) => $split->category?->type)->unique();
    expect($types->count())->toBeLessThanOrEqual(1);
});
