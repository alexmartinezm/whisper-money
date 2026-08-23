<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property Carbon $start_date
 * @property Carbon $end_date
 */
class BudgetPeriod extends Model
{
    use HasFactory, HasUuids;

    private const CLOSE_TO_LIMIT_THRESHOLD_PERCENTAGE = 90;

    protected $fillable = [
        'budget_id',
        'start_date',
        'end_date',
        'allocated_amount',
        'carried_over_amount',
        'processing_historical',
        'reconciliation_token',
        'close_to_limit_notified',
        'over_limit_notified',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'allocated_amount' => 'integer',
            'carried_over_amount' => 'integer',
            'spent_amount' => 'integer',
            'processing_historical' => 'boolean',
            'close_to_limit_notified' => 'boolean',
            'over_limit_notified' => 'boolean',
        ];
    }

    /** @return BelongsTo<Budget, $this> */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * Total spent in this period, in cents. BudgetTransaction amounts are
     * stored positive for expenses, so summing them gives the amount spent.
     */
    public function spentAmount(): int
    {
        return (int) $this->budgetTransactions()->sum('amount');
    }

    /**
     * What is left of this period's own allocation, in cents. Deliberately
     * ignores `carried_over_amount`: this is what rolls into the next period
     * when the budget carries over, and carrying a carry-over forward again
     * would count the same money twice. The warnings use
     * {@see self::availableAmount()} instead.
     */
    public function remainingAmount(): int
    {
        return $this->allocated_amount - $this->spentAmount();
    }

    /**
     * The period's ceiling: its allocation plus anything carried over from the
     * period before. This is what the status warnings judge against, which is
     * why it is not the mirror of {@see self::remainingAmount()}.
     */
    public function availableAmount(): int
    {
        return (int) $this->allocated_amount + (int) ($this->carried_over_amount ?? 0);
    }

    /**
     * Canonical status for UI warnings and email notifications.
     *
     * The limit is the period's allocation plus any carried-over balance.
     */
    public function limitStatus(?int $spentAmount = null): string
    {
        return self::limitStatusFor(
            $spentAmount ?? $this->spentAmount(),
            $this->availableAmount(),
        );
    }

    public static function limitStatusFor(int $spent, int $available): string
    {
        if ($available > 0 && $spent >= $available) {
            return 'over_limit';
        }

        if ($available > 0 && $spent * 100 >= $available * self::CLOSE_TO_LIMIT_THRESHOLD_PERCENTAGE) {
            return 'close_to_limit';
        }

        return 'on_track';
    }

    /** @return HasMany<BudgetTransaction, $this> */
    public function budgetTransactions(): HasMany
    {
        return $this->hasMany(BudgetTransaction::class);
    }
}
