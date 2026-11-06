<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Account;
use App\Models\Category;
use Illuminate\Support\Arr;

/**
 * What the transaction create and update requests share: the
 * TransactionWriteRules the MCP tools validate with too, the account and
 * category they point at, checked to be the caller's own in the request's
 * space, and the balance switch.
 */
trait ValidatesTransactionWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the TransactionWriteRules for the write
     * @return array<string, array<mixed>>
     */
    protected function transactionFields(array $rules): array
    {
        return [
            ...$rules,
            'account_id' => [...$rules['account_id'] ?? ['sometimes', 'string'], $this->inSpace(Account::class, 'an account')],
            'category_id' => ['sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
            'update_balance' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The validated fields as TransactionWriter takes them: only the ones
     * sent, the account and category as stored.
     *
     * @return array<string, mixed>
     */
    protected function transactionChanges(): array
    {
        $changes = Arr::except($this->validated(), ['update_balance', 'label_ids']);

        foreach (['account_id' => Account::class, 'category_id' => Category::class] as $field => $model) {
            if (is_string($changes[$field] ?? null)) {
                $changes[$field] = $this->idInSpace($model, $field, $changes[$field]);
            }
        }

        return $changes;
    }
}
