<?php

namespace App\Models;

use App\Enums\ChartColorScheme;
use Carbon\Carbon;
use Database\Factories\UserSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ChartColorScheme $chart_color_scheme
 * @property bool $include_loans_in_net_worth_chart
 * @property bool $include_real_estate_in_net_worth_chart
 * @property bool $notify_on_bank_transactions_synced
 * @property bool $budget_notify_on_new_transaction
 * @property bool $budget_notify_on_close_to_limit
 * @property bool $budget_notify_on_over_limit
 * @property bool $notify_upcoming_recurring
 * @property bool $notify_runway_shortfall
 * @property ?Carbon $runway_alerted_low_on
 * @property bool $notify_price_changes
 */
class UserSetting extends Model
{
    /** @use HasFactory<UserSettingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'chart_color_scheme',
        'include_loans_in_net_worth_chart',
        'include_real_estate_in_net_worth_chart',
        'notify_on_bank_transactions_synced',
        'budget_notify_on_new_transaction',
        'budget_notify_on_close_to_limit',
        'budget_notify_on_over_limit',
        'notify_upcoming_recurring',
        'notify_runway_shortfall',
        'runway_alerted_low_on',
        'notify_price_changes',
    ];

    protected function casts(): array
    {
        return [
            'chart_color_scheme' => ChartColorScheme::class,
            'include_loans_in_net_worth_chart' => 'boolean',
            'include_real_estate_in_net_worth_chart' => 'boolean',
            'notify_on_bank_transactions_synced' => 'boolean',
            'budget_notify_on_new_transaction' => 'boolean',
            'budget_notify_on_close_to_limit' => 'boolean',
            'budget_notify_on_over_limit' => 'boolean',
            'notify_upcoming_recurring' => 'boolean',
            'notify_runway_shortfall' => 'boolean',
            'runway_alerted_low_on' => 'date',
            'notify_price_changes' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
