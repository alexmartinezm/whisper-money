<?php

namespace App\Http\Requests;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
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
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->hasAny(['name', 'allocated_amount'])) {
                $validator->errors()->add('budget', 'Provide a name or allocated amount.');
            }

            $budget = $this->route('budget');
            if ($budget === null) {
                return;
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
