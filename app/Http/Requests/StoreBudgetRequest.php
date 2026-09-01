<?php

namespace App\Http\Requests;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Http\Requests\Concerns\ValidatesUserOwnedResources;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBudgetRequest extends FormRequest
{
    use ValidatesUserOwnedResources;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'period_start_day' => ['nullable', 'integer', ...$this->periodType()->startDayRules()],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => [$this->userOwned('categories')],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => [$this->userOwned('labels')],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'allocated_amount' => ['required', 'integer', 'min:0'],
            'is_catch_all' => ['sometimes', 'boolean'],
        ];
    }

    private function periodType(): BudgetPeriodType
    {
        return BudgetPeriodType::tryFrom((string) $this->input('period_type')) ?? BudgetPeriodType::Monthly;
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateStartDay($validator);
            $this->validateSelection($validator);
            $this->validateCatchAll($validator);
        });
    }

    private function validateStartDay(Validator $validator): void
    {
        $periodType = $this->enum('period_type', BudgetPeriodType::class);

        if ($periodType === null || $this->isStartDayValid($periodType, $this->input('period_start_day'))) {
            return;
        }

        $validator->errors()->add('period_start_day', 'The start day is invalid for the selected cadence.');
    }

    /**
     * Each cadence reads the start day differently: a monthly budget takes a day
     * of the month or none, a weekly or biweekly one requires a weekday, and a
     * yearly one only ever starts on the first.
     */
    private function isStartDayValid(BudgetPeriodType $periodType, mixed $startDay): bool
    {
        return match ($periodType) {
            BudgetPeriodType::Monthly => $startDay === null || ((int) $startDay >= 1 && (int) $startDay <= 31),
            BudgetPeriodType::Weekly, BudgetPeriodType::Biweekly => $startDay !== null && (int) $startDay >= 0 && (int) $startDay <= 6,
            BudgetPeriodType::Yearly => $startDay === null || (int) $startDay === 1,
        };
    }

    private function validateSelection(Validator $validator): void
    {
        if ($this->boolean('is_catch_all') || ! empty($this->category_ids) || ! empty($this->label_ids)) {
            return;
        }

        $validator->errors()->add('selection', 'You must select at least one category or label.');
    }

    private function validateCatchAll(Validator $validator): void
    {
        if (! $this->boolean('is_catch_all')) {
            return;
        }

        // An archived catch-all no longer counts anything, so it does not stand
        // in the way of the one that replaces it.
        if ($this->user()->budgets()->notArchived()->where('is_catch_all', true)->exists()) {
            $validator->errors()->add('is_catch_all', 'You already have a catch-all budget.');
        }
    }
}
