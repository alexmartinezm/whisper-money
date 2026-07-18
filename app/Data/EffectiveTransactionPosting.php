<?php

namespace App\Data;

use App\Models\Category;
use App\Models\Transaction;

final readonly class EffectiveTransactionPosting
{
    public function __construct(
        public Transaction $transaction,
        public ?Category $category,
        public ?string $categoryId,
        public int $amount,
        public bool $fromSplit,
    ) {}
}
