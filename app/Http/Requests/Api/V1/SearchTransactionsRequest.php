<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Rules\StorableDate;
use Illuminate\Foundation\Http\FormRequest;

class SearchTransactionsRequest extends FormRequest
{
    use ChecksIdsInSpace;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'query' => ['sometimes', 'string', 'max:200'],
            'account_id' => ['sometimes', 'string', $this->inSpace(Account::class, 'an account')],
            'category_id' => ['sometimes', 'string', $this->inSpace(Category::class, 'a category')],
            ...$this->idListInSpace('label_ids', 'sometimes', 100, Label::class, 'a label'),
            'from' => ['sometimes', 'date', new StorableDate],
            'to' => ['sometimes', 'date', new StorableDate],
            'min_amount' => ['sometimes', 'integer'],
            'max_amount' => ['sometimes', 'integer'],
        ];
    }

    /**
     * The validated filters as TransactionSearch takes them, the ids as
     * stored: the lookup ignores case, the category tree does not.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        $filters = $this->validated();

        foreach (['account_id' => Account::class, 'category_id' => Category::class] as $field => $model) {
            if (isset($filters[$field])) {
                $filters[$field] = $this->idInSpace($model, $field, $filters[$field]);
            }
        }

        return [...$filters, 'label_ids' => $this->idListAsStored(Label::class, 'label_ids')];
    }
}
