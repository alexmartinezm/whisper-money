<?php

namespace App\Http\Resources\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A category: what a transaction was for. Categories form a tree up to 3
 * levels deep through `parent_id`.
 *
 * @mixin Category
 */
class CategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'space_id' => $this->space_id,
            'name' => $this->name,
            'icon' => $this->icon,
            'color' => $this->color,
            'type' => $this->type->value,
            'cashflow_direction' => $this->cashflow_direction->value,
            'parent_id' => $this->parent_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
