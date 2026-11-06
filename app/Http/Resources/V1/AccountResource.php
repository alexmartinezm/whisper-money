<?php

namespace App\Http\Resources\V1;

use App\Models\Account;
use App\Models\User;
use App\Services\CreditCards\CreditCardStatementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * An account: a bookkeeping record of money held somewhere, either kept by
 * hand or connected to a bank that syncs it.
 *
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        // The day-by-day usage series is for the app's chart, not the API.
        $creditCard = Arr::except(app(CreditCardStatementService::class)->presentFor($this->resource, $user), 'credit_card_usage.daily');

        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            // Still encrypted on the device: empty, as the CSV export writes it.
            'name' => $this->name_iv === null ? $this->name : '',
            'type' => $this->type->value,
            'currency' => $this->currency_code,
            'bank' => $this->bank === null ? null : ['id' => $this->bank->id, 'name' => $this->bank->name],
            'is_connected' => $this->isConnected(),
            'ownership_percentage' => $this->ownership_percentage,
            'ownership_applies_to_balance' => $this->ownership_applies_to_balance,
            'credit_card_detail' => $creditCard['credit_card_detail'] ?? null,
            'credit_card_statement' => $creditCard['credit_card_statement'] ?? null,
            'credit_card_usage' => $creditCard['credit_card_usage'] ?? null,
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
