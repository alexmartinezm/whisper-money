<?php

namespace App\Services\MonthlySummary;

use App\Models\MonthlySummary;
use App\Models\User;
use Carbon\Carbon;

/**
 * Creates and freezes the summary for one user and month.
 *
 * A summary is written once. The reader asks for a month, the figures are frozen
 * on that first pass, and every later visit reads the same ones — which is what
 * makes "August was this" keep meaning the same thing, and what stops the paid
 * AI paragraph being re-bought on every page view.
 *
 * The single exception is a summary frozen while the month was still open. Once
 * the month has settled — a bank has synced since, or a transaction has been
 * added in the month after — the figures are worth freezing again, because what
 * was missing has since arrived. After that the month is final.
 */
class Summaries
{
    public function __construct(
        private SummaryBuilder $builder,
        private Readiness $readiness,
    ) {}

    public function find(User $user, Carbon $month): ?MonthlySummary
    {
        return $user->monthlySummaries()
            ->where('period', $month->copy()->startOfMonth()->format('Y-m'))
            ->first();
    }

    /**
     * The frozen summary for this month, building it if it does not exist yet or
     * if the stored one was taken before the month settled. Returns null when the
     * month holds nothing worth reporting.
     */
    public function freeze(User $user, Carbon $month): ?MonthlySummary
    {
        $month = $month->copy()->startOfMonth();
        $existing = $this->find($user, $month);
        $complete = $this->readiness->hasSettled($user, $month);

        if ($existing !== null && ! $this->worthRefreezing($existing, $complete)) {
            return $existing;
        }

        if (! $this->readiness->hasDataFor($user, $month)) {
            return null;
        }

        $summary = $existing ?? new MonthlySummary([
            'user_id' => $user->id,
            'period' => $month->format('Y-m'),
        ]);

        $summary->fill([
            'space_id' => $user->activeSpace()->id,
            'payload' => $this->builder->build($user, $month, $complete),
            'complete' => $complete,
            // A refreeze moves the figures, so an analysis written against the
            // old ones is now describing a month that no longer exists. Drop it
            // and let the reader ask again rather than leave a paragraph that
            // contradicts the numbers under it.
            ...($existing !== null ? ['ai_analysis' => null, 'ai_generated_at' => null] : []),
        ])->save();

        return $summary->refresh();
    }

    /**
     * Only a snapshot taken before the month settled is rebuilt, and only once
     * it has. A month already frozen as complete never moves again, however much
     * the reader recategorises afterwards.
     */
    private function worthRefreezing(MonthlySummary $summary, bool $complete): bool
    {
        return ! $summary->complete && $complete;
    }
}
