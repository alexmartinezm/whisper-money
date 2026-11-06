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
}
