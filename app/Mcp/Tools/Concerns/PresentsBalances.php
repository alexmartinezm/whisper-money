<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\AccountBalance;

/**
 * The balance-snapshot shape shared by the balance tools, so a snapshot reads
 * the same whether it was just written, listed or deleted.
 */
trait PresentsBalances
{
    /**
     * @return array<string, mixed>
     */
    protected function presentBalance(AccountBalance $balance): array
    {
        return [
            'id' => $balance->id,
            'account_id' => $balance->account_id,
            'balance_date' => $balance->balance_date->toDateString(),
            'balance' => $balance->balance,
            'invested_amount' => $balance->invested_amount,
        ];
    }
}
