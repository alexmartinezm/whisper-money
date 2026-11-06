<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesAccountWrites;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAccountRequest extends FormRequest
{
    use ValidatesAccountWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->accountRules();
        $fields = $rules->createRules($this->requestedType());

        return [
            'name' => $fields['name'],
            'type' => $rules->typeRule(required: true)['type'],
            'currency_code' => $fields['currency_code'],
            'bank_id' => $fields['bank_id'],
            'balance' => $fields['balance'],
            ...$this->detailFields($fields),
        ];
    }

    /**
     * Refuse unknown fields, the ones that would make a bank-connected
     * account, and a balance on a credit card.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $rules = $this->accountRules();
            $errors = [
                ...$this->unknownFieldRefusals(),
                ...$rules->connectionFieldErrors($this->all()),
                ...$rules->creditCardBalanceErrors($this->all(), $this->requestedType()),
            ];

            $this->addRefusals($validator, $errors);
        }];
    }
}
