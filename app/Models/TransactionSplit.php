<?php

namespace App\Models;

use Database\Factories\TransactionSplitFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransactionSplit extends Model
{
    /** @use HasFactory<TransactionSplitFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['transaction_id', 'category_id', 'amount', 'position'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'position' => 'integer'];
    }

    /** @return BelongsTo<Transaction, $this> */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
