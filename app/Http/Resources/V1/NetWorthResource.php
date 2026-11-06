<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Your net worth at the end of a date range against the previous period, and
 * every account's balance over the range.
 *
 * @property array{granularity: string, current: array{current: int, previous: int}, counted_account_ids: list<string>, evolution: array{data: list<array<string, mixed>>, accounts: Collection<string, array<string, mixed>>}} $resource
 */
class NetWorthResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $accounts = collect($this->resource['evolution']['accounts'])->values();
        $accountIds = $accounts->pluck('id')->all();

        return [
            'currency' => $user->currency_code ?? 'USD',
            'granularity' => $this->resource['granularity'],
            'net_worth' => $this->resource['current']['current'],
            'previous_net_worth' => $this->resource['current']['previous'],
            'accounts' => $accounts->map(fn (array $account): array => [
                'id' => $account['id'],
                // Still encrypted on the device: empty, as the CSV export writes it.
                'name' => $account['name_iv'] === null ? $account['name'] : '',
                'type' => $account['type']->value,
                'currency' => $account['currency_code'],
                'counts_in_net_worth' => in_array($account['id'], $this->resource['counted_account_ids'], true),
            ])->all(),
            'evolution' => NetWorthPointResource::collection(array_map(
                fn (array $point): array => ['point' => $point, 'account_ids' => $accountIds],
                $this->resource['evolution']['data'],
            )),
        ];
    }
}
