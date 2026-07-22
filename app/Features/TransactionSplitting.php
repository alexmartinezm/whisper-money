<?php

namespace App\Features;

use App\Models\User;

/**
 * Gates creation and replacement of transaction splits while preserving read
 * access and the ability to remove an existing split.
 *
 * @api
 */
class TransactionSplitting
{
    public function resolve(?User $user): bool
    {
        return true;
    }
}
