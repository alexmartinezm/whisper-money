<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ValidatesTransactionWrites;
use App\Models\Label;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{
    use ValidatesTransactionWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->transactionFields(TransactionWriteRules::createRules()),
            ...$this->idListInSpace('label_ids', 'sometimes', 100, Label::class, 'a label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        return $this->transactionChanges();
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->idListAsStored(Label::class, 'label_ids');
    }
}
