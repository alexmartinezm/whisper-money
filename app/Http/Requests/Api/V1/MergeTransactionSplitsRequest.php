<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\AddsRefusals;
use App\Http\Requests\Api\V1\Concerns\ChecksIdsInSpace;
use App\Http\Requests\Api\V1\Concerns\ResolvesRouteTransaction;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;

class MergeTransactionSplitsRequest extends FormRequest
{
    use AddsRefusals, ChecksIdsInSpace, ResolvesRouteTransaction;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'string', $this->inSpace(Category::class, 'a category')],
        ];
    }

    public function categoryId(): string
    {
        return $this->idInSpace(Category::class, 'category_id', $this->validated('category_id'));
    }
}
