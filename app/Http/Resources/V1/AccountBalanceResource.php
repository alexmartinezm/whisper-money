<?php

namespace App\Http\Resources\V1;

use App\Models\AccountBalance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A balance snapshot: what an account held at the end of one day.
 *
 * @mixin AccountBalance
 */
class AccountBalanceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'balance_date' => $this->balance_date->toDateString(),
            'balance' => $this->balance,
            'invested_amount' => $this->invested_amount,
            'derived' => $this->derived,
        ];
    }
}
