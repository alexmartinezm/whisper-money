<?php

namespace App\Services\Transactions;

use App\Enums\CategoryType;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReplaceTransactionSplits
{
    /**
     * @param  array<int, array{category_id: string, amount: int}>  $splits
     * @param  array<string, mixed>  $parentAttributes
     */
    public function replace(Transaction $transaction, array $splits, ?string $fallbackCategoryId = null, array $parentAttributes = []): Transaction
    {
        return DB::transaction(function () use ($transaction, $splits, $fallbackCategoryId, $parentAttributes): Transaction {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $locked->fill($parentAttributes);

            if ($splits === []) {
                $this->remove($locked, $fallbackCategoryId);
            } else {
                $this->validate($locked, $splits);

                $locked->splits()->delete();
                $locked->splits()->createMany(array_map(
                    fn (array $split, int $position): array => [
                        'category_id' => $split['category_id'],
                        'amount' => (int) $split['amount'],
                        'position' => $position,
                    ],
                    $splits,
                    array_keys($splits),
                ));

                $locked->forceFill([
                    'category_id' => null,
                    'category_source' => null,
                    'ai_confidence' => null,
                    'categorized_by_rule_id' => null,
                    'ai_suggested_category_id' => null,
                    'ai_suggested_category_at' => null,
                    'ai_model' => null,
                ]);
            }

            $locked->setUpdatedAt($locked->freshTimestamp());
            $locked->save();

            return $locked->fresh(['splits.category']);
        });
    }

    /** @param array<int, array{category_id: string, amount: int}> $splits */
    private function validate(Transaction $transaction, array $splits): void
    {
        if (count($splits) < 2) {
            $this->fail('A split transaction requires at least two lines.');
        }

        $amounts = array_map(fn (array $split): int => (int) $split['amount'], $splits);
        if (in_array(0, $amounts, true)) {
            $this->fail('Every split amount must be non-zero.');
        }

        $sign = $transaction->amount <=> 0;
        if ($sign === 0 || collect($amounts)->contains(fn (int $amount): bool => ($amount <=> 0) !== $sign)) {
            $this->fail('Every split amount must have the same sign as the transaction.');
        }

        if (array_sum($amounts) !== $transaction->amount) {
            $this->fail('Split amounts must sum exactly to the transaction amount.');
        }

        $ids = array_values(array_unique(array_column($splits, 'category_id')));
        $categories = Category::query()
            ->whereIn('id', $ids)
            ->where('user_id', $transaction->user_id)
            ->where('space_id', $transaction->space_id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($categories->count() !== count($ids)) {
            $this->fail('Every split category must belong to the transaction owner and space.');
        }

        $types = $categories->pluck('type')->map(fn (CategoryType $type): string => $type->value)->unique();
        if ($types->count() !== 1 || ! in_array($types->first(), [CategoryType::Expense->value, CategoryType::Income->value], true)) {
            $this->fail('Split categories must share an expense or income type.');
        }
    }

    private function remove(Transaction $transaction, ?string $fallbackCategoryId): void
    {
        if ($fallbackCategoryId === null) {
            $this->fail('A fallback category is required when removing splits.');
        }

        $category = Category::query()
            ->whereKey($fallbackCategoryId)
            ->lockForUpdate()
            ->first();

        if ($category === null || $category->user_id !== $transaction->user_id || $category->space_id !== $transaction->space_id) {
            $this->fail('The fallback category must belong to the transaction owner and space.');
        }

        if (! in_array($category->type, [CategoryType::Expense, CategoryType::Income], true)) {
            $this->fail('The fallback category must have an expense or income type.');
        }

        $transaction->splits()->delete();
        $transaction->forceFill([
            'category_id' => $category->id,
            'category_source' => 'manual',
            'ai_confidence' => null,
            'categorized_by_rule_id' => null,
        ]);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['splits' => $message]);
    }
}
