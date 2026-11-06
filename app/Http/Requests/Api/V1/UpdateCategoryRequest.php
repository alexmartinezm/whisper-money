<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Api\V1\Concerns\ResolvesRouteCategory;
use App\Http\Requests\Api\V1\Concerns\ValidatesCategoryWrites;
use App\Services\Categories\CategoryWriter;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCategoryRequest extends FormRequest
{
    use ResolvesRouteCategory, ValidatesCategoryWrites;

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return $this->categoryFields(CategoryWriter::updateRules());
    }
}
