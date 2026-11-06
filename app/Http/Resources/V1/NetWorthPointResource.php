<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Every account's balance on one point of the net worth evolution.
 *
 * @property array{point: array<string, mixed>, account_ids: list<string>} $resource
 */
class NetWorthPointResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $point = $this->resource['point'];

        return [
            'period' => $point['month'] ?? $point['date'],
            'balances' => array_map(fn (string $id): array => [
                'account_id' => $id,
                'balance' => $point[$id],
                'original_balance' => $point["{$id}_original"]['amount'] ?? null,
                'original_currency' => $point["{$id}_original"]['currency_code'] ?? null,
                'invested_amount' => $point["{$id}_invested"] ?? null,
            ], $this->resource['account_ids']),
        ];
    }
}
