<?php

namespace App\Models;

use App\Models\Concerns\BumpsDataVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RecurringSeriesTransaction extends Pivot
{
    use BumpsDataVersion, HasUuids;

    protected $table = 'recurring_series_transaction';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<RecurringSeries, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(RecurringSeries::class, 'recurring_series_id');
    }

    public function dataVersionOwner(): ?Model
    {
        return $this->dataVersionOwnerVia('series');
    }
}
