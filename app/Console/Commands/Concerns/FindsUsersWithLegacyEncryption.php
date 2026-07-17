<?php

namespace App\Console\Commands\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

trait FindsUsersWithLegacyEncryption
{
    /**
     * Users who still have at least one client-side encrypted transaction or account
     * (a non-null `*_iv` column is the source of truth, not the stale `accounts.encrypted` flag).
     *
     * Subscriptions are eager-loaded so callers can filter with {@see excludeBilledUsers()}
     * without an N+1 query.
     *
     * @return Builder<User>
     */
    protected function usersWithLegacyEncryption(): Builder
    {
        return User::query()->with('subscriptions')->where(function (Builder $query): void {
            $query->whereHas('transactions', function (Builder $transactions): void {
                $transactions->whereNotNull('description_iv')
                    ->orWhereNotNull('notes_iv');
            })->orWhereHas('accounts', function (Builder $accounts): void {
                $accounts->whereNotNull('name_iv');
            });
        });
    }

    /**
     * Drop users who are still being billed. These accounts must never be emailed a
     * deletion warning nor deleted while a subscription or trial is active.
     *
     * {@see User::isBilled()} is used instead of {@see User::hasActiveSubscriptionOrTrial()}
     * because it is independent of the `subscriptions.enabled` feature flag: that flag
     * controls whether the paywall is enforced, not whether real Stripe subscriptions
     * exist. A destructive command must never delete a paying customer just because the
     * flag is off in the runtime that happens to execute it.
     *
     * @param  Collection<int, User>  $users
     * @return Collection<int, User>
     */
    protected function excludeBilledUsers(Collection $users): Collection
    {
        return $users->reject(fn (User $user): bool => $user->isBilled())->values();
    }
}
