<?php

namespace App\Http\Requests\Api\V1;

use App\Models\Category;
use Illuminate\Validation\Rule;

class SpendingByCategoryRequest extends AnalyticsRangeRequest
{
    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'parent_category_id' => ['nullable', 'uuid', Rule::exists('categories', 'id')->where('user_id', $this->user()?->getAuthIdentifier())->whereNull('deleted_at')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_category_id.exists' => 'The parent_category_id is not one of your categories.',
        ];
    }

    /**
     * The category as stored: `exists` ignores case, the roll-up does not.
     */
    public function parentCategoryId(): ?string
    {
        $sent = $this->validated('parent_category_id');

        return $sent === null ? null : Category::query()->whereKey($sent)->value('id');
    }
}
