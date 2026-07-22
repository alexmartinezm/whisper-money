<?php

use App\Enums\CategoryCashflowDirection;
use App\Enums\CategoryType;
use App\Enums\TransactionSource;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorySpendingService;
use App\Services\Transactions\EffectiveTransactionPostings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @return array{result: mixed, queries: int, milliseconds: float, peak_memory_mb: float}
 */
function measureSplitVolumePath(Closure $callback): array
{
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    gc_collect_cycles();
    if (function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $memoryBefore = memory_get_usage(true);
    $startedAt = hrtime(true);
    $result = $callback();
    $milliseconds = (hrtime(true) - $startedAt) / 1_000_000;
    $peakMemory = max(0, memory_get_peak_usage(true) - $memoryBefore) / 1024 / 1024;

    return [
        'result' => $result,
        'queries' => $queries,
        'milliseconds' => round($milliseconds, 2),
        'peak_memory_mb' => round($peakMemory, 2),
    ];
}

/**
 * @return array{result: mixed, queries: int, median_milliseconds: float, worst_milliseconds: float, peak_memory_mb: float}
 */
function measureRepeatedSplitVolumePath(Closure $callback, int $runs = 3): array
{
    $measurements = collect(range(1, $runs))->map(fn () => measureSplitVolumePath($callback));
    $times = $measurements->pluck('milliseconds')->sort()->values();

    return [
        'result' => $measurements->last()['result'],
        'queries' => (int) $measurements->max('queries'),
        'median_milliseconds' => (float) $times[(int) floor($times->count() / 2)],
        'worst_milliseconds' => (float) $times->max(),
        'peak_memory_mb' => (float) $measurements->max('peak_memory_mb'),
    ];
}

it('measures representative effective split posting volume with exact accounting', function () {
    $from = Carbon::parse('2026-01-01');
    $to = Carbon::parse('2026-12-31');
    $timestamp = Carbon::parse('2026-06-15 12:00:00');

    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $otherUser = User::factory()->onboarded()->create(['currency_code' => 'EUR']);
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->current_space_id,
        'currency_code' => 'EUR',
    ]);
    $secondaryAccount = Account::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->current_space_id,
        'currency_code' => 'EUR',
    ]);
    $otherAccount = Account::factory()->create([
        'user_id' => $otherUser->id,
        'space_id' => $otherUser->current_space_id,
        'currency_code' => 'EUR',
    ]);

    $roots = collect(range(0, 3))->map(fn (int $index): Category => Category::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->current_space_id,
        'name' => "Benchmark root {$index}",
        'type' => CategoryType::Expense,
        'cashflow_direction' => CategoryCashflowDirection::Outflow,
    ]));
    $categories = $roots->flatMap(fn (Category $root, int $rootIndex): array => [
        $root,
        Category::factory()->childOf($root)->create([
            'name' => "Benchmark child {$rootIndex}-1",
            'space_id' => $user->current_space_id,
        ]),
        Category::factory()->childOf($root)->create([
            'name' => "Benchmark child {$rootIndex}-2",
            'space_id' => $user->current_space_id,
        ]),
    ])->values();

    $transactionRows = [];
    $splitRows = [];
    $expectedEffectiveAmount = 0;
    $expectedPostingCount = 10_000;
    $expectedByCategory = $categories->mapWithKeys(fn (Category $category): array => [$category->id => 0])->all();
    $rootByCategory = $categories->mapWithKeys(fn (Category $category): array => [
        $category->id => $category->parent_id ?? $category->id,
    ])->all();

    $flushTransactions = function () use (&$transactionRows): void {
        if ($transactionRows !== []) {
            DB::table('transactions')->insert($transactionRows);
            $transactionRows = [];
        }
    };
    $flushSplits = function () use (&$splitRows, $flushTransactions): void {
        $flushTransactions();

        if ($splitRows !== []) {
            DB::table('transaction_splits')->insert($splitRows);
            $splitRows = [];
        }
    };

    for ($index = 0; $index < 10_000; $index++) {
        $amount = $index % 20 === 0 ? 500 : -(1_000 + ($index % 400));
        $categoryId = $categories[$index % $categories->count()]->id;
        $expectedEffectiveAmount += $amount;
        $expectedByCategory[$categoryId] += $amount;
        $transactionRows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'space_id' => $user->current_space_id,
            'account_id' => $index % 5 === 0 ? $secondaryAccount->id : $account->id,
            'category_id' => $categoryId,
            'description' => "Benchmark unsplit {$index}",
            'description_iv' => null,
            'transaction_date' => $timestamp->toDateString(),
            'amount' => $amount,
            'currency_code' => 'EUR',
            'source' => TransactionSource::ManuallyCreated->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        if (count($transactionRows) === 500) {
            $flushTransactions();
        }
    }
    $flushTransactions();

    for ($index = 0; $index < 2_000; $index++) {
        $transactionId = (string) Str::uuid();
        $lineCount = 2 + ($index % 4);
        $amount = $index % 20 === 0 ? 700 : -(2_000 + ($index % 500));
        $expectedEffectiveAmount += $amount;
        $expectedPostingCount += $lineCount;
        $transactionRows[] = [
            'id' => $transactionId,
            'user_id' => $user->id,
            'space_id' => $user->current_space_id,
            'account_id' => $index % 5 === 0 ? $secondaryAccount->id : $account->id,
            'category_id' => null,
            'description' => "Benchmark split {$index}",
            'description_iv' => null,
            'transaction_date' => $timestamp->toDateString(),
            'amount' => $amount,
            'currency_code' => 'EUR',
            'source' => TransactionSource::ManuallyCreated->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];

        $baseAmount = intdiv($amount, $lineCount);
        $allocated = 0;
        for ($position = 0; $position < $lineCount; $position++) {
            $lineAmount = $position === $lineCount - 1
                ? $amount - $allocated
                : $baseAmount;
            $categoryId = $categories[($index + $position) % $categories->count()]->id;
            $allocated += $lineAmount;
            $expectedByCategory[$categoryId] += $lineAmount;
            $splitRows[] = [
                'id' => (string) Str::uuid(),
                'transaction_id' => $transactionId,
                'category_id' => $categoryId,
                'amount' => $lineAmount,
                'position' => $position,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }

        if (count($transactionRows) === 500) {
            $flushTransactions();
        }
        if (count($splitRows) >= 500) {
            $flushSplits();
        }
    }
    $flushTransactions();
    $flushSplits();

    Transaction::factory()->plaintext()->create([
        'user_id' => $user->id,
        'space_id' => $user->current_space_id,
        'account_id' => $account->id,
        'category_id' => $categories->first()->id,
        'transaction_date' => '2025-12-31',
        'amount' => -999_999,
        'currency_code' => 'EUR',
    ]);
    Transaction::factory()->plaintext()->create([
        'user_id' => $otherUser->id,
        'space_id' => $otherUser->current_space_id,
        'account_id' => $otherAccount->id,
        'transaction_date' => $timestamp,
        'amount' => -999_999,
        'currency_code' => 'EUR',
    ]);

    $expectedByRoot = collect($expectedByCategory)
        ->groupBy(fn (int $amount, string $categoryId): string => $rootByCategory[$categoryId])
        ->map(fn ($amounts): int => -$amounts->sum())
        ->sortKeys()
        ->all();

    $categorySpending = measureRepeatedSplitVolumePath(fn () => app(CategorySpendingService::class)
        ->forPeriod($user->id, $from, $to));
    $dashboardPostings = measureRepeatedSplitVolumePath(function () use ($user, $from, $to) {
        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [$from, $to])
            ->with(['category', 'splits.category'])
            ->get();

        return app(EffectiveTransactionPostings::class)->forTransactions($transactions);
    });

    $actualByCategory = $dashboardPostings['result']
        ->groupBy('categoryId')
        ->map(fn ($postings): int => $postings->sum('amount'))
        ->sortKeys()
        ->all();
    $actualByRoot = $categorySpending['result']
        ->mapWithKeys(fn (array $item): array => [$item['category_id'] => $item['amount']])
        ->sortKeys()
        ->all();

    expect(Transaction::query()->where('user_id', $user->id)->whereBetween('transaction_date', [$from, $to])->count())->toBe(12_000)
        ->and($dashboardPostings['result'])->toHaveCount($expectedPostingCount)
        ->and($dashboardPostings['result']->sum('amount'))->toBe($expectedEffectiveAmount)
        ->and($actualByCategory)->toBe(collect($expectedByCategory)->sortKeys()->all())
        ->and($categorySpending['result']->sum('amount'))->toBe(-$expectedEffectiveAmount)
        ->and($actualByRoot)->toBe($expectedByRoot)
        ->and($categorySpending['queries'])->toBeLessThanOrEqual(6)
        ->and($dashboardPostings['queries'])->toBeLessThanOrEqual(4);

    fwrite(STDERR, json_encode([
        'transaction_split_volume' => [
            'transactions' => 12_000,
            'split_parents' => 2_000,
            'effective_postings' => $expectedPostingCount,
            'category_spending' => Arr::except($categorySpending, ['result']),
            'dashboard_postings' => Arr::except($dashboardPostings, ['result']),
        ],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
});
