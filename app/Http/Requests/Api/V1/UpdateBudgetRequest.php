<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BudgetExpenseType;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteBudget;
use App\Http\Requests\Api\V1\Concerns\ValidatesBudgetWrites;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBudgetRequest extends FormRequest
{
    use ResolvesRouteBudget, ValidatesBudgetWrites;

    /**
     * What a budget's periods are built on. They are calculated over its
     * history, so reshaping them afterwards would leave overlapping ones
     * counting the same transaction twice.
     *
     * @var list<string>
     */
    private const array FixedFields = ['period_type', 'period_start_day', 'rollover_type', 'is_catch_all'];

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:1', 'max:255'],
            'allocated_amount' => ['sometimes', ...self::allocationRule()],
            'expense_type' => ['sometimes', Rule::enum(BudgetExpenseType::class)],
            ...$this->trackingRules(),
        ];
    }

    /**
     * The fields sent, in the form BudgetManagementService::update() takes
     * them: a list left out keeps what the budget tracks.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        return [
            ...$this->safe()->only(['name', 'allocated_amount', 'expense_type']),
            ...($this->has('category_ids') ? ['category_ids' => $this->categoryIds()] : []),
            ...($this->has('label_ids') ? ['label_ids' => $this->labelIds()] : []),
        ];
    }

    /**
     * Refuse the fixed fields, and any other unknown field.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...collect(self::FixedFields)
                ->filter(fn (string $field): bool => $this->has($field))
                ->mapWithKeys(fn (string $field): array => [$field => "A budget's {$field} is fixed once it is created, because its periods are calculated over its history. To change it, delete the budget and create it again."])
                ->all(),
        ])];
    }
}
