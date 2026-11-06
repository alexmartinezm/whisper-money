<?php

namespace App\Http\Requests;

use App\Enums\CashflowBlock;
use App\Enums\CategoryType;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateTransactionsRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    /**
     * The fields that change the rows.
     *
     * @var list<string>
     */
    private const array WriteFields = ['category_id', 'notes', 'notes_iv', 'label_ids', 'remove_all_labels'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'transaction_ids' => ['array', 'min:1'],
            'transaction_ids.*' => ['required', 'string', 'uuid'],
            'filters' => ['array'],
            'filters.date_from' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.date_to' => ['nullable', 'date_format:Y-m-d', 'required_with:filters.cashflow_block'],
            'filters.cashflow_block' => ['nullable', Rule::enum(CashflowBlock::class)],
            'filters.amount_min' => ['nullable', 'numeric'],
            'filters.amount_max' => ['nullable', 'numeric'],
            'filters.category_ids' => ['nullable', 'array'],
            // Besides real categories, the list's filter takes the "uncategorized"
            // pseudo id, which Transaction::scopeApplyFilters() reads.
            'filters.category_ids.*' => Rule::forEach(fn (mixed $categoryId): array => $categoryId === 'uncategorized'
                ? ['string']
                : ['string', 'uuid', $this->userOwned('categories')]),
            'filters.category_types' => ['nullable', 'array'],
            'filters.category_types.*' => [Rule::enum(CategoryType::class)],
            'filters.account_ids' => ['nullable', 'array'],
            'filters.account_ids.*' => ['string', 'uuid'],
            'filters.label_ids' => ['nullable', 'array'],
            'filters.label_ids.*' => ['string', 'uuid', $this->userOwned('labels')],
            'filters.creditor_name' => ['nullable', 'string'],
            'filters.debtor_name' => ['nullable', 'string'],
            'filters.search' => ['nullable', 'string'],
            'filters.search_text' => ['nullable', 'string'],
            'category_id' => ['nullable', $this->userOwned('categories')],
            'notes' => ['nullable', 'string'],
            'notes_iv' => ['nullable', 'string', 'size:16'],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['required', 'string', 'uuid', $this->userOwned('labels')],
            'remove_all_labels' => ['sometimes', 'accepted', 'prohibits:label_ids'],
        ];
    }

    /**
     * With neither ids nor filters the controller would edit every row of the
     * user, so a write has to name its target. The key is what counts: a
     * select-all with no filter set sends `filters: {}`, which reads as empty.
     * A request writing nothing is the controller's 400.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->hasAny(self::WriteFields) && ! $this->hasAny(['transaction_ids', 'filters'])) {
                $validator->errors()->add('transaction_ids', 'A bulk edit must name its transactions or its filters.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'transaction_ids.*.uuid' => 'Invalid transaction ID format.',
            'category_id.exists' => 'The selected category does not exist or does not belong to you.',
            'filters.category_ids.*.exists' => 'One or more filter categories do not exist or do not belong to you.',
            'filters.label_ids.*.exists' => 'One or more filter labels do not exist or do not belong to you.',
            'label_ids.*.exists' => 'One or more selected labels do not exist or do not belong to you.',
            'notes_iv.size' => 'The notes IV must be exactly 16 characters.',
        ];
    }
}
