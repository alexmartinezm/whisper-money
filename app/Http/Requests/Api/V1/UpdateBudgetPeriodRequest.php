<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteBudget;
use App\Http\Requests\Api\V1\Concerns\ValidatesBudgetWrites;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBudgetPeriodRequest extends FormRequest
{
    use ResolvesRouteBudget, ValidatesBudgetWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'allocated_amount' => ['nullable', ...self::allocationRule()],
            'reset' => ['nullable', 'boolean'],
        ];
    }

    public function periodDate(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('date'))->startOfDay();
    }

    /**
     * The period's own amount, null to put it back on the regular allocation.
     */
    public function oneOffAmount(): ?int
    {
        return $this->boolean('reset') ? null : $this->integer('allocated_amount');
    }

    /**
     * Refuse unknown fields, and a request naming neither or both of an amount
     * and a reset.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...($this->boolean('reset') === $this->filled('allocated_amount') ? ['allocated_amount' => 'Pass either allocated_amount or reset: true.'] : []),
        ])];
    }
}
