<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates the recurring series screen and its detection endpoints.
 *
 * The initial value comes from configuration so a self-hosted deployment can
 * turn the feature off entirely, while Pennant still stores per-user overrides
 * from `php artisan feature:enable App\\Features\\RecurringTransactions <target>`.
 *
 * @api
 */
class RecurringTransactions
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(?User $user): bool
    {
        return (bool) config('recurring.enabled');
    }
}
