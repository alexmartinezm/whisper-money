<?php

namespace App\Http\Requests\Api\V1\Concerns;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Label;

/**
 * What the budget create and update requests share. A budget only tracks the
 * caller's own categories and labels in its space; BudgetManagementService
 * holds the rest of what a budget may track, under its lock.
 */
trait ValidatesBudgetWrites
{
    use AddsRefusals, ChecksIdsInSpace;

    /**
     * @return array<string, array<mixed>>
     */
    protected function trackingRules(): array
    {
        return [
            ...$this->idListInSpace('category_ids', 'sometimes', 100, Category::class, 'a category'),
            ...$this->idListInSpace('label_ids', 'sometimes', 100, Label::class, 'a label'),
        ];
    }

    /**
     * @return list<string>
     */
    public function categoryIds(): array
    {
        return $this->idListAsStored(Category::class, 'category_ids');
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->idListAsStored(Label::class, 'label_ids');
    }

    /**
     * @return list<mixed>
     */
    protected static function allocationRule(): array
    {
        return ['integer', 'min:0', 'max:'.Budget::MAX_ALLOCATED_AMOUNT];
    }
}
