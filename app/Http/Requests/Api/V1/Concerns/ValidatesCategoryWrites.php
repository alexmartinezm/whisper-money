<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Category;
use Illuminate\Contracts\Validation\Validator;

/**
 * What the category create and update requests share: the CategoryWriter
 * rules the MCP tools validate with too, and the parent they point at,
 * checked to be in the request's space.
 */
trait ValidatesCategoryWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @param  array<string, array<mixed>>  $rules  the CategoryWriter rules for the write
     * @return array<string, array<mixed>>
     */
    protected function categoryFields(array $rules): array
    {
        return [
            'name' => $rules['name'],
            'icon' => $rules['icon'],
            'color' => $rules['color'],
            'type' => $rules['type'],
            'cashflow_direction' => $rules['cashflow_direction'],
            'parent_id' => ['bail', 'sometimes', 'nullable', 'string', $this->inSpace(Category::class, 'a category')],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->addRefusals($validator, $this->unknownFieldRefusals())];
    }
}
