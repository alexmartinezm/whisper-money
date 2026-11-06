<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteAccount;
use App\Http\Requests\Api\V1\Concerns\ValidatesAccountWrites;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAccountRequest extends FormRequest
{
    use ResolvesRouteAccount, ValidatesAccountWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = $this->accountRules();
        $account = $this->account();
        $fields = $rules->updateRules($this->requestedType() ?? $account->type, $account->creditCardDetail);

        return [
            'name' => $fields['name'],
            'type' => $rules->typeRule(required: false)['type'],
            'currency_code' => $fields['currency_code'],
            'bank_id' => $fields['bank_id'],
            'ownership_percentage' => $fields['ownership_percentage'],
            'ownership_applies_to_balance' => $fields['ownership_applies_to_balance'],
            ...$this->detailFields($fields),
        ];
    }

    /**
     * Refuse unknown fields, and changing what the bank sync owns on a
     * connected account.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $errors = [
                ...$this->unknownFieldRefusals(),
                ...$this->accountRules()->syncOwnedFieldErrors($this->account(), $this->all(), $this->requestedType()),
            ];

            $this->addRefusals($validator, $errors);
        }];
    }
}
