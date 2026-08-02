<?php

namespace App\Http\Requests;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'period_type' => ['sometimes', Rule::enum(BudgetPeriodType::class)],
            'period_start_day' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:31'],
            'rollover_type' => ['sometimes', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['sometimes', 'integer', 'min:0'],
            'category_ids' => ['sometimes', 'array'],
            'category_ids.*' => [$this->userOwned('categories')],
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => [$this->userOwned('labels')],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['name', 'allocated_amount', 'category_ids', 'label_ids'])) {
                $validator->errors()->add('budget', 'Provide a mutable budget field.');
            }

            $budget = $this->route('budget');
            if ($budget === null) {
                return;
            }

            if ($this->hasAny(['category_ids', 'label_ids'])) {
                if ($budget->is_catch_all) {
                    $validator->errors()->add('tracking', 'A catch-all budget cannot have categories or labels.');
                } else {
                    $categoryIds = $this->has('category_ids')
                        ? (array) $this->input('category_ids', [])
                        : $budget->categories()->pluck('categories.id')->all();
                    $labelIds = $this->has('label_ids')
                        ? (array) $this->input('label_ids', [])
                        : $budget->labels()->pluck('labels.id')->all();

                    if ($categoryIds === [] && $labelIds === []) {
                        $validator->errors()->add('selection', 'You must select at least one category or label.');
                    }
                }
            }

            foreach ([
                'period_type' => $budget->period_type->value,
                'period_start_day' => $budget->period_start_day,
                'rollover_type' => $budget->rollover_type->value,
            ] as $field => $current) {
                if ($this->has($field) && (string) $this->input($field) !== (string) $current) {
                    $validator->errors()->add($field, 'This budget setting cannot be changed.');
                }
            }
        });
    }
}
