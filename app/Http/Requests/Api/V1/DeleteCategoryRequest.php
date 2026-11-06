<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CategoryDeletionStrategy;
use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
{
    use AddsRefusals, ResolvesRouteCategory;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'strategy' => ['sometimes', Rule::enum(CategoryDeletionStrategy::class)],
        ];
    }

    public function strategy(): CategoryDeletionStrategy
    {
        return $this->enum('strategy', CategoryDeletionStrategy::class) ?? CategoryDeletionStrategy::Reparent;
    }
}
