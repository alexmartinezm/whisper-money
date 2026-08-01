<?php

namespace App\Policies;

use App\Models\RecurringSeries;
use App\Models\User;

class RecurringSeriesPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RecurringSeries $series): bool
    {
        return $user->id === $series->user_id;
    }

    public function update(User $user, RecurringSeries $series): bool
    {
        return $user->id === $series->user_id;
    }

    public function delete(User $user, RecurringSeries $series): bool
    {
        return $user->id === $series->user_id;
    }
}
