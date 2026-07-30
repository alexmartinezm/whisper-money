<?php

namespace App\Models;

use App\Enums\BudgetPeriodType;
use App\Enums\RolloverType;
use App\Models\Concerns\BelongsToSpace;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property RolloverType $rollover_type
 * @property BudgetPeriodType $period_type
 */
class Budget extends Model
{
    use BelongsToSpace, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'space_id',
        'name',
        'period_type',
        'period_start_day',
        'rollover_type',
        'is_catch_all',
        'notify_on_new_transaction',
        'notify_on_close_to_limit',
        'notify_on_over_limit',
    ];

    /** @var list<string> */
    protected $hidden = [
        'period_duration',
        'space_id',
    ];

    protected function casts(): array
    {
        return [
            'period_type' => BudgetPeriodType::class,
            'rollover_type' => RolloverType::class,
            'period_start_day' => 'integer',
            'is_catch_all' => 'boolean',
            'notify_on_new_transaction' => 'boolean',
            'notify_on_close_to_limit' => 'boolean',
            'notify_on_over_limit' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Category, $this> */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /** @return BelongsToMany<Label, $this> */
    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(Label::class);
    }

    /** @return HasMany<BudgetPeriod, $this> */
    public function periods(): HasMany
    {
        return $this->hasMany(BudgetPeriod::class);
    }

    public function getCurrentPeriod(?CarbonInterface $applicationDate = null): ?BudgetPeriod
    {
        $applicationDate ??= today();

        return $this->periods()
            ->whereDate('start_date', '<=', $applicationDate->toDateString())
            ->whereDate('end_date', '>=', $applicationDate->toDateString())
            ->first();
    }

    public function getNextPlanningPeriod(CarbonInterface $applicationDate, ?BudgetPeriod $currentPeriod = null): ?BudgetPeriod
    {
        $currentPeriod ??= $this->getCurrentPeriod($applicationDate);
        if ($currentPeriod === null) {
            return null;
        }

        return $this->periods()
            ->whereDate('start_date', $currentPeriod->end_date->copy()->addDay()->toDateString())
            ->first();
    }
}
