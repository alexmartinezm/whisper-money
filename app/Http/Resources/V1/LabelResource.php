<?php

namespace App\Http\Resources\V1;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A label: a free tag a transaction can carry any number of, next to its
 * single category.
 *
 * @mixin Label
 */
class LabelResource extends JsonResource
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
            'color' => $this->color,
            'source' => $this->source->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
