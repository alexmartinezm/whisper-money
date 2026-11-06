<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteBudget;
use Illuminate\Foundation\Http\FormRequest;

class DeleteBudgetRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteBudget;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
