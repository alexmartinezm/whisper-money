<?php

namespace App\Data;

use App\Models\Category;

final readonly class EffectiveTransactionPosting
{
    public function __construct(
        public ?Category $category,
        public ?string $categoryId,
        public int $amount,
    ) {}
}
