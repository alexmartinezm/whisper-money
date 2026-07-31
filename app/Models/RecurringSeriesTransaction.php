<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class RecurringSeriesTransaction extends Pivot
{
    use HasUuids;

    protected $table = 'recurring_series_transaction';

    public $incrementing = false;

    protected $keyType = 'string';
}
