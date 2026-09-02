<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSpace;
use Database\Factories\MonthlySummaryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A frozen reading of one month.
 *
 * Built on demand rather than mailed on a schedule: the figures are computed
 * once, stored, and read back unchanged from then on. That freeze is what makes
 * the month comparable to the next one and stops a paid-for AI paragraph being
 * re-bought every time the page is opened.
 *
 * @property array<string, mixed> $payload
 * @property Carbon|null $ai_generated_at
 * @property Carbon $created_at
 */
class MonthlySummary extends Model
{
    /** @use HasFactory<MonthlySummaryFactory> */
    use BelongsToSpace, HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'space_id',
        'period',
        'payload',
        'ai_analysis',
        'ai_generated_at',
        'complete',
    ];

    /** @var list<string> */
    protected $hidden = [
        'space_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'complete' => 'boolean',
            'ai_generated_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * First day of the month this summary reports on.
     */
    public function periodStart(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfMonth();
    }

    /**
     * Read a dotted path out of the frozen payload.
     */
    public function figure(string $path, mixed $default = null): mixed
    {
        return data_get($this->payload, $path, $default);
    }
}
