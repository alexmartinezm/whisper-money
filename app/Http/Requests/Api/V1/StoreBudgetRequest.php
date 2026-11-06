<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BudgetExpenseType;
use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Http\Requests\Api\V1\Concerns\ValidatesBudgetWrites;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBudgetRequest extends FormRequest
{
    use ValidatesBudgetWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'allocated_amount' => ['required', ...self::allocationRule()],
            'period_type' => ['required', Rule::enum(BudgetPeriodType::class)],
            'rollover_type' => ['required', Rule::enum(RolloverType::class)],
            'period_start_day' => ['sometimes', 'nullable', 'integer'],
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
            'is_catch_all' => ['sometimes', 'boolean'],
            ...$this->trackingRules(),
        ];
    }

    /**
     * The validated fields BudgetManagementService::create() takes.
     *
     * @return array<string, mixed>
     */
    public function budgetAttributes(): array
    {
        return [
            ...$this->safe()->only(['name', 'allocated_amount', 'period_type', 'rollover_type', 'expense_type']),
            'period_start_day' => BudgetPeriodType::from($this->validated('period_type'))->storedStartDay($this->startDay()),
            'is_catch_all' => $this->boolean('is_catch_all'),
        ];
    }

    /**
     * Refuse unknown fields, and a start day the cadence has no such day for.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...$this->startDayRefusal($validator),
        ])];
    }

    /**
     * @return array<string, string>
     */
    private function startDayRefusal(Validator $validator): array
    {
        if ($validator->errors()->hasAny(['period_type', 'period_start_day'])
            || BudgetPeriodType::from($this->input('period_type'))->acceptsStartDay($this->startDay())) {
            return [];
        }

        return ['period_start_day' => 'The start day is invalid for the selected cadence.'];
    }

    private function startDay(): ?int
    {
        return $this->filled('period_start_day') ? $this->integer('period_start_day') : null;
    }
}
