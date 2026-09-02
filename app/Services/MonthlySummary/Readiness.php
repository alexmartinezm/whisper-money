<?php

namespace App\Services\MonthlySummary;

use App\Enums\BankingConnectionStatus;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Decides what can be said about a month, and how final it is.
 *
 * Nothing here refuses to build a summary: the user asked for it, so they get
 * it. What this answers is whether the month is worth reporting at all, and
 * whether it has stopped moving — which is what decides if the stored snapshot
 * is final or may still be regenerated.
 */
class Readiness
{
    /**
     * At least one transaction dated inside the month. Without this there is no
     * report to write at all.
     */
    public function hasDataFor(User $user, Carbon $month): bool
    {
        return $this->transactionsDatedIn($user, $month)->exists();
    }

    /**
     * Whether the user has a month before this one. Half the analysis is
     * comparisons, so without it the reader gets the simpler first-month read.
     */
    public function hasHistoryBefore(User $user, Carbon $month): bool
    {
        return $this->hasDataFor($user, $month->copy()->subMonth());
    }

    /**
     * Whether the month has settled: something has happened in the month after
     * it, so the sources have moved on. There is no "I have finished importing"
     * signal in the product, and most users have no bank connected at all, so
     * the proxy is deliberately one condition rather than one per account type:
     * a successful sync counts, and so does a transaction created by hand or by
     * import. Requiring both would deadlock the mixed user, whose abandoned
     * manual account would hold the month open forever.
     *
     * `last_synced_at` is only written on the success path of the sync job, so
     * its presence already means the sync was satisfactory.
     */
    public function hasSettled(User $user, Carbon $month): bool
    {
        $from = $month->copy()->addMonth()->startOfMonth();

        $synced = BankingConnection::query()
            ->where('user_id', $user->id)
            ->where('status', BankingConnectionStatus::Active)
            ->where('last_synced_at', '>=', $from)
            ->exists();

        if ($synced) {
            return true;
        }

        return Transaction::query()
            ->where('user_id', $user->id)
            ->where('created_at', '>=', $from)
            ->exists();
    }

    /**
     * @return Builder<Transaction>
     */
    private function transactionsDatedIn(User $user, Carbon $month): Builder
    {
        return Transaction::query()
            ->where('user_id', $user->id)
            ->whereBetween('transaction_date', [
                $month->copy()->startOfMonth(),
                $month->copy()->endOfMonth(),
            ]);
    }
}
