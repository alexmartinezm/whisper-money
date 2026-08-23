<?php

namespace App\Models;

use App\Enums\RecurringCadence;
use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\Concerns\BelongsToSpace;
use Carbon\Carbon;
use Database\Factories\RecurringSeriesFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A charge that repeats on a recognisable cadence — a subscription, a utility
 * bill, a standing loan payment. A series is metadata over transactions that
 * already exist in the ledger; it never creates ledger rows of its own.
 *
 * @property RecurringCadence $cadence
 * @property RecurringSeriesStatus $status
 * @property RecurringSeriesUserState $user_state
 * @property Carbon $first_occurred_on
 * @property Carbon $last_occurred_on
 * @property Carbon $next_expected_on
 * @property ?Carbon $last_reminded_on
 */
class RecurringSeries extends Model
{
    /** @use HasFactory<RecurringSeriesFactory> */
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $table = 'recurring_series';

    protected $fillable = [
        'user_id',
        'space_id',
        'match_field',
        'merchant_key',
        'display_name',
        'cadence',
        'interval_days',
        'expected_amount',
        'price_alerted_amount',
        'amount_is_variable',
        'currency_code',
        'account_id',
        'category_id',
        'direction',
        'first_occurred_on',
        'last_occurred_on',
        'next_expected_on',
        'occurrence_count',
        'status',
        'user_state',
        'last_reminded_on',
    ];

    /** @var list<string> */
    protected $hidden = ['space_id'];

    protected function casts(): array
    {
        return [
            'cadence' => RecurringCadence::class,
            'status' => RecurringSeriesStatus::class,
            'user_state' => RecurringSeriesUserState::class,
            'interval_days' => 'integer',
            'expected_amount' => 'integer',
            'price_alerted_amount' => 'integer',
            'occurrence_count' => 'integer',
            'amount_is_variable' => 'boolean',
            'first_occurred_on' => 'date',
            'last_occurred_on' => 'date',
            'next_expected_on' => 'date',
            'last_reminded_on' => 'date',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsToMany<Transaction, $this, RecurringSeriesTransaction, 'pivot'> */
    public function transactions(): BelongsToMany
    {
        return $this->belongsToMany(Transaction::class, 'recurring_series_transaction')
            ->using(RecurringSeriesTransaction::class)
            ->withTimestamps();
    }

    /**
     * Series the user has not dismissed.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('user_state', '!=', RecurringSeriesUserState::Ignored);
    }

    /**
     * The series a forecast walks forward: still billing, still wanted, still
     * here. Soft deletes are handled by the model's own scope.
     *
     * The everyday-spending estimate leaves out exactly the transactions these
     * series claim, on the grounds that the forecast already counts them. That
     * only holds while both sides read the same set, so the definition lives
     * here rather than being spelled out again at each call site: the two
     * drifting apart is how spending falls through the gap between them.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStillCharging(Builder $query): Builder
    {
        return $query->visible()->where('status', RecurringSeriesStatus::Active);
    }

    /**
     * The expected amount rescaled to a month, so cadences can be summed and
     * ranked against each other.
     */
    public function monthlyEquivalentAmount(): int
    {
        return (int) round($this->expected_amount * $this->cadence->monthlyFactor());
    }

    public function isIgnored(): bool
    {
        return $this->user_state === RecurringSeriesUserState::Ignored;
    }
}
