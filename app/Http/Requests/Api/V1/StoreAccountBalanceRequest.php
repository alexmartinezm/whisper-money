<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAccount;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountBalanceRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteAccount;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = app(AccountBalanceRecorder::class)->rules();

        return [
            'balance' => $rules['balance'],
            'balance_date' => $rules['balance_date'],
            'invested_amount' => $rules['invested_amount'],
        ];
    }

    /**
     * Refuse unknown fields, and a bank-connected account and a credit card, which take no
     * manual balance.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->addRefusals($validator, [
                ...$this->unknownFieldRefusals(),
                ...AccountBalanceRecorder::unwritableErrors($this->account(), 'account'),
            ]);
        }];
    }
}
