<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

/**
 * Your expenses in a date range rolled up by category.
 *
 * @property Collection<int, array<string, mixed>> $resource
 */
class SpendingByCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'currency' => $user->currency_code ?? 'USD',
            'total' => $this->resource->sum('amount'),
            'categories' => CategoryAmountResource::collection($this->resource->sortByDesc('amount')->values()),
        ];
    }
}
