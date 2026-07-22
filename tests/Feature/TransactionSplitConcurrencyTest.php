<?php

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategoryTree;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\ExpectationFailedException;

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

/**
 * @param  list<Closure(): void>  $writers
 * @param  list<string>  $allowedFailureMessages
 */
function runSplitWriters(array $writers, ?int $minimumSuccesses = null, array $allowedFailureMessages = []): void
{
    DB::commit();
    DB::disconnect();
    $barrier = sys_get_temp_dir().'/split-writers-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);
    $children = [];

    foreach ($writers as $index => $writer) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge();
            touch("{$barrier}/ready-{$index}");
            $deadline = microtime(true) + 5;
            while (! file_exists("{$barrier}/release")) {
                if (microtime(true) >= $deadline) {
                    exit(3);
                }
                usleep(1000);
            }
            try {
                $writer();
                exit(0);
            } catch (Throwable $exception) {
                file_put_contents(
                    "{$barrier}/failure-{$index}.json",
                    json_encode([
                        'class' => $exception::class,
                        'message' => $exception->getMessage(),
                        'errors' => $exception instanceof ValidationException ? $exception->errors() : [],
                    ], JSON_THROW_ON_ERROR),
                );
                exit(2);
            }
        }
        $children[$index] = $pid;
    }

    $deadline = microtime(true) + 5;
    while (count(glob("{$barrier}/ready-*") ?: []) !== count($writers)) {
        if (microtime(true) >= $deadline) {
            break;
        }
        usleep(1000);
    }
    $allWritersReady = count(glob("{$barrier}/ready-*") ?: []) === count($writers);
    touch("{$barrier}/release");

    $statuses = [];
    foreach ($children as $index => $pid) {
        pcntl_waitpid($pid, $status);
        $statuses[$index] = pcntl_wexitstatus($status);
    }

    $failures = [];
    foreach ($statuses as $index => $status) {
        $failurePath = "{$barrier}/failure-{$index}.json";
        if ($status === 2 && file_exists($failurePath)) {
            $failures[$index] = json_decode((string) file_get_contents($failurePath), true, flags: JSON_THROW_ON_ERROR);
        }
    }
    foreach (glob("{$barrier}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($barrier);
    DB::purge();

    expect($allWritersReady)->toBeTrue();
    foreach ($statuses as $index => $status) {
        expect($status)->toBeIn([0, 2]);
        if ($status !== 2) {
            continue;
        }

        $failure = $failures[$index] ?? null;
        expect($failure)->not->toBeNull()
            ->and($failure['class'])->toBe(ValidationException::class);
        $messages = collect($failure['errors'])->flatten()->all();
        expect($messages)->not->toBeEmpty()
            ->and(collect($messages)->diff($allowedFailureMessages))->toBeEmpty();
    }

    $requiredSuccesses = $minimumSuccesses ?? count($writers);
    expect(collect($statuses)->filter(fn (int $status): bool => $status === 0)->count())
        ->toBeGreaterThanOrEqual($requiredSuccesses);
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

it('rejects a vacuous concurrency run when every writer fails', function () {
    expect(fn () => runSplitWriters([
        fn () => throw new RuntimeException('writer one failed'),
        fn () => throw new RuntimeException('writer two failed'),
    ]))->toThrow(ExpectationFailedException::class);
});

it('rejects a concurrency run when one writer fails unexpectedly', function () {
    expect(fn () => runSplitWriters([
        fn () => null,
        fn () => throw new RuntimeException('unexpected writer failure'),
    ]))->toThrow(ExpectationFailedException::class);
});

it('rejects a validation failure that mixes allowed and unexpected errors', function () {
    expect(fn () => runSplitWriters([
        fn () => null,
        fn () => throw ValidationException::withMessages([
            'category' => 'Categories used by split transactions cannot be deleted.',
            'unexpected' => 'Unexpected validation failure.',
        ]),
    ], minimumSuccesses: 1, allowedFailureMessages: [
        'Categories used by split transactions cannot be deleted.',
    ]))->toThrow(ExpectationFailedException::class);
});

it('serializes two competing replacements without combining payloads', function () {
    [$transaction, $categories] = concurrencyFixture();
    $payloadA = [['category_id' => $categories[0]->id, 'amount' => -2500], ['category_id' => $categories[1]->id, 'amount' => -7500]];
    $payloadB = [['category_id' => $categories[2]->id, 'amount' => -7000], ['category_id' => $categories[3]->id, 'amount' => -3000]];

    runSplitWriters([
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payloadA),
        fn () => app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), $payloadB),
    ], minimumSuccesses: 2);

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

it('rechecks split references with a current read before deleting a subtree', function () {
    [$transaction, $categories] = concurrencyFixture();
    DB::commit();
    DB::disconnect();
    $barrier = sys_get_temp_dir().'/split-delete-'.bin2hex(random_bytes(8));
    mkdir($barrier, 0700);

    $pid = pcntl_fork();
    if ($pid === 0) {
        DB::purge();
        DB::beginTransaction();
        Category::query()->where('user_id', $transaction->user_id)->count();
        touch("{$barrier}/snapshot-ready");
        while (! file_exists("{$barrier}/replacement-done")) {
            usleep(1000);
        }

        try {
            $category = Category::query()->findOrFail($categories[2]->id);
            $locked = app(CategoryTree::class)->lockSubtreeForMutation($category);
            app(CategoryTree::class)->deleteSubtree($locked);
            DB::commit();
            exit(0);
        } catch (ValidationException $exception) {
            DB::rollBack();
            file_put_contents("{$barrier}/message", collect($exception->errors())->flatten()->first());
            exit(2);
        }
    }

    while (! file_exists("{$barrier}/snapshot-ready")) {
        usleep(1000);
    }
    app(ReplaceTransactionSplits::class)->replace(Transaction::findOrFail($transaction->id), [
        ['category_id' => $categories[2]->id, 'amount' => -2500],
        ['category_id' => $categories[3]->id, 'amount' => -7500],
    ]);
    touch("{$barrier}/replacement-done");
    pcntl_waitpid($pid, $status);

    expect(pcntl_wexitstatus($status))->toBe(2)
        ->and((string) file_get_contents("{$barrier}/message"))->toBe('Categories used by split transactions cannot be deleted.')
        ->and(Category::withTrashed()->findOrFail($categories[2]->id)->trashed())->toBeFalse();

    foreach (glob("{$barrier}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($barrier);
    DB::purge();
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
    ], minimumSuccesses: 1, allowedFailureMessages: [
        'Categories used by split transactions cannot be deleted.',
        'Every split category must belong to the transaction owner and space.',
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
    ], minimumSuccesses: 1, allowedFailureMessages: [
        'Category types used by split transactions cannot be changed.',
        'Split categories must share an expense or income type.',
    ]);

    assertCompleteSplitState($transaction);
    $types = $transaction->fresh('splits')->splits->map(fn ($split) => $split->category?->type)->unique();
    expect($types->count())->toBeLessThanOrEqual(1);
});
