<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use Illuminate\Foundation\Http\FormRequest;

class DeleteTransactionRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'update_balance' => ['sometimes', 'boolean'],
        ];
    }
}
