<?php

namespace App\Http\Resources\V1;

use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What one category adds up to in a period, its subcategories rolled into it.
 *
 * @property array{category_id: ?string, category: Category|null, amount: int, has_children?: bool, is_direct?: bool} $resource
 */
class CategoryAmountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource['category'];

        return [
            'category_id' => $this->resource['category_id'],
            'name' => $category?->name,
            'type' => $category?->type?->value,
            'color' => $category?->color,
            'icon' => $category?->icon,
            'amount' => $this->resource['amount'],
            'has_children' => $this->resource['has_children'] ?? false,
            'is_direct' => $this->resource['is_direct'] ?? false,
        ];
    }
}
