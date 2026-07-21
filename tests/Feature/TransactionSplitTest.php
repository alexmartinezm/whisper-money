<?php

use App\Enums\CategoryType;
use App\Events\TransactionUpdated;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function splitCategory(Transaction $transaction, CategoryType $type = CategoryType::Expense): Category
{
    return Category::factory()->create([
        'user_id' => $transaction->user_id,
        'space_id' => $transaction->space_id,
        'type' => $type,
    ]);
}

it('persists ordered splits and cascades them with the parent', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);

    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $second->id, 'amount' => -4000, 'position' => 1]);
    TransactionSplit::factory()->create(['transaction_id' => $transaction->id, 'category_id' => $first->id, 'amount' => -6000, 'position' => 0]);

    expect($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000])
        ->and($transaction->splits->first()->amount)->toBeInt();

    Transaction::withoutEvents(fn () => $transaction->forceDelete());
    expect(TransactionSplit::query()->count())->toBe(0);
});

it('atomically replaces valid splits and clears parent classification provenance', function () {
    $transaction = Transaction::factory()->create([
        'amount' => -10000,
        'category_source' => 'ai',
        'ai_confidence' => .9,
        'ai_suggested_category_id' => null,
    ]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);

    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);

    $transaction->refresh()->load('splits.category');
    expect($transaction->category_id)->toBeNull()
        ->and($transaction->category_source)->toBeNull()
        ->and($transaction->ai_confidence)->toBeNull()
        ->and($transaction->splits)->toHaveCount(2)
        ->and($transaction->is_split)->toBeTrue()
        ->and($transaction->split_count)->toBe(2);
});

it('rejects invalid split invariants without replacing existing lines', function (array $amounts, string $message) {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $categories = [splitCategory($transaction), splitCategory($transaction)];
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $categories[0]->id, 'amount' => -6000],
        ['category_id' => $categories[1]->id, 'amount' => -4000],
    ]);

    try {
        app(ReplaceTransactionSplits::class)->replace($transaction, collect($amounts)->map(fn (int $amount, int $index) => [
            'category_id' => $categories[$index % 2]->id,
            'amount' => $amount,
        ])->all());
        $this->fail('Expected validation exception.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['splits'][0])->toContain($message);
    }

    expect($transaction->splits()->pluck('amount')->all())->toBe([-6000, -4000]);
})->with([
    'one line' => [[-10000], 'at least two'],
    'wrong sum' => [[-5000, -4000], 'sum'],
    'zero' => [[-10000, 0], 'non-zero'],
    'mixed signs' => [[-11000, 1000], 'sign'],
]);

it('rejects categories outside owner space or with incompatible types', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $expense = splitCategory($transaction);
    $foreign = Category::factory()->create(['type' => CategoryType::Expense]);
    $income = splitCategory($transaction, CategoryType::Income);

    foreach ([[$expense, $foreign], [$expense, $income]] as $categories) {
        expect(fn () => app(ReplaceTransactionSplits::class)->replace($transaction, [
            ['category_id' => $categories[0]->id, 'amount' => -6000],
            ['category_id' => $categories[1]->id, 'amount' => -4000],
        ]))->toThrow(ValidationException::class);
    }
});

it('allows positive refunds split across expense categories', function () {
    $transaction = Transaction::factory()->create(['amount' => 2000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);

    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => 1200],
        ['category_id' => $second->id, 'amount' => 800],
    ]);

    expect((int) $transaction->splits()->sum('amount'))->toBe(2000);
});

it('requires an explicit fallback category when removing splits', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);

    expect(fn () => app(ReplaceTransactionSplits::class)->replace($transaction, []))
        ->toThrow(ValidationException::class);

    app(ReplaceTransactionSplits::class)->replace($transaction, [], $first->id);
    expect($transaction->refresh()->category_id)->toBe($first->id)
        ->and($transaction->splits()->count())->toBe(0);
});

it('rejects fallback categories outside the owner space or soft deleted', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);
    $foreignOwner = Category::factory()->create(['space_id' => $transaction->space_id, 'type' => CategoryType::Expense]);
    $foreignSpace = Category::factory()->create(['user_id' => $transaction->user_id, 'type' => CategoryType::Expense]);
    $deleted = splitCategory($transaction);
    $deleted->delete();

    foreach ([$foreignOwner, $foreignSpace, $deleted] as $fallback) {
        expect(fn () => app(ReplaceTransactionSplits::class)->replace($transaction, [], $fallback->id))
            ->toThrow(ValidationException::class);
        expect($transaction->splits()->count())->toBe(2);
    }
});

it('protects split categories from deletion and type changes', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $first = splitCategory($transaction);
    $second = splitCategory($transaction);
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $first->id, 'amount' => -6000],
        ['category_id' => $second->id, 'amount' => -4000],
    ]);

    expect(fn () => $first->delete())->toThrow(ValidationException::class)
        ->and(fn () => $first->update(['type' => CategoryType::Income]))->toThrow(ValidationException::class);

    expect($first->fresh())->not->toBeNull()
        ->and($first->fresh()->type)->toBe(CategoryType::Expense)
        ->and($transaction->splits()->count())->toBe(2);
});

it('touches the parent once after replacing lines and the event sees final lines', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $categories = [splitCategory($transaction), splitCategory($transaction)];
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $categories[0]->id, 'amount' => -6000],
        ['category_id' => $categories[1]->id, 'amount' => -4000],
    ]);
    $oldTimestamp = now()->subDay();
    Transaction::withoutEvents(fn () => $transaction->forceFill(['updated_at' => $oldTimestamp])->saveQuietly());
    $observed = [];
    Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) use (&$observed): void {
        $observed[] = $event->transaction->fresh('splits')->splits->pluck('amount')->all();
    });

    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $categories[0]->id, 'amount' => -2500],
        ['category_id' => $categories[1]->id, 'amount' => -7500],
    ]);

    expect($transaction->fresh()->updated_at->gt($oldTimestamp))->toBeTrue()
        ->and($observed)->toBe([[-2500, -7500]]);
});

it('touches the parent once after removing lines and the event sees no lines', function () {
    $transaction = Transaction::factory()->create(['amount' => -10000]);
    $categories = [splitCategory($transaction), splitCategory($transaction)];
    app(ReplaceTransactionSplits::class)->replace($transaction, [
        ['category_id' => $categories[0]->id, 'amount' => -6000],
        ['category_id' => $categories[1]->id, 'amount' => -4000],
    ]);
    $oldTimestamp = now()->subDay();
    Transaction::withoutEvents(fn () => $transaction->forceFill(['updated_at' => $oldTimestamp])->saveQuietly());
    $observed = [];
    Event::listen(TransactionUpdated::class, function (TransactionUpdated $event) use (&$observed): void {
        $observed[] = $event->transaction->fresh('splits')->splits->count();
    });

    app(ReplaceTransactionSplits::class)->replace($transaction, [], $categories[0]->id);

    expect($transaction->fresh()->updated_at->gt($oldTimestamp))->toBeTrue()
        ->and($observed)->toBe([0]);
});
