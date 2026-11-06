<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Http\Requests\Api\V1\Concerns\ValidatesTransactionWrites;
use App\Models\Account;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTransactionRequest extends FormRequest
{
    use ResolvesRouteTransaction, ValidatesTransactionWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->transactionFields(TransactionWriteRules::updateRules());
    }

    /**
     * Refuse unknown fields, and the edits the transaction does not take.
     * Read off the data the request is validated with, the query string
     * included, so a field cannot skip a refusal by being sent there.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, [
            ...$this->unknownFieldRefusals(),
            ...TransactionWriteRules::editRefusals($this->transaction(), $this->validationData()),
        ])];
    }

    /**
     * What to change. The currency follows a new account unless the request
     * picks one, as in the app's edit dialog: picking an account is the
     * clearest statement of what the transaction is in.
     *
     * @return array<string, mixed>
     */
    public function changes(): array
    {
        $changes = $this->transactionChanges();
        $accountId = $changes['account_id'] ?? $this->transaction()->account_id;

        if ($accountId !== $this->transaction()->account_id && ! array_key_exists('currency_code', $changes)) {
            $changes['currency_code'] = Account::query()->whereKey($accountId)->value('currency_code');
        }

        return $changes;
    }
}
