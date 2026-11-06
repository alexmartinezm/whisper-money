<?php

namespace App\Jobs;

use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-derive catch-all periods after what the other budgets claim there changed:
 * a budget created, deleted, archived or tracking something else.
 *
 * The catch-all keeps the rows it absorbed until something re-derives them, so
 * without this a new budget would count those expenses alongside it, and a
 * deleted or archived one would leave them counted nowhere.
 *
 * Each period comes claimed with a reconciliation token, like any backfill
 * ({@see AssignHistoricalTransactionsToBudget}): a run that lost its token to a
 * newer edit leaves that period, and its flag, to whoever holds it now. A null
 * token is a period whose first backfill was pending at claim time: it stays
 * that backfill's to flag, and this only visits it afterwards.
 */
class RebuildCatchAllBudgetPeriods implements ShouldQueue
{
    use Queueable;

    /**
     * How long this job keeps trying, and how long a flagged period can hold it
     * up. One figure on purpose: a flag older than this is not a backfill still
     * on its way, and a rebuild always outlives the flags it waits on. Generous,
     * since this shares the `default` queue with the bank syncs and a late run
     * is safe: what cannot be proved stays as it is.
     */
    private const int WINDOW_MINUTES = 360;

    /**
     * The longest wait between looks. Every release adds to `jobs.attempts`, an
     * unsigned tiny integer, so the delay has to grow for the window to fit.
     */
    private const int MAX_DELAY_SECONDS = 300;

    /**
     * Waiting on a backfill releases the job without failing it, which still
     * spends a try: retried for a while instead, and failed after three genuine
     * exceptions.
     */
    public int $maxExceptions = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, ?string>  $reconciliationTokens  by period id, the catch-all periods to rebuild
     */
    public function __construct(
        public array $reconciliationTokens,
        public string $userId,
        public string $spaceId,
    ) {}

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(self::WINDOW_MINUTES);
    }

    public function handle(BudgetTransactionService $service): void
    {
        if ($this->isWaitingOnBackfill()) {
            $this->release((int) min($this->backoff * 2 ** max($this->attempts() - 1, 0), self::MAX_DELAY_SECONDS));

            return;
        }

        foreach ($this->reconciliationTokens as $periodId => $token) {
            $this->rebuild($service, $periodId, $token);
        }
    }

    /**
     * Out of tries, hand back what this run still holds: left set, the budget
     * page would spin on those periods forever. What they show may be stale,
     * which beats never showing anything. The token lives in the payload and in
     * the row, so this works on the fresh instance the queue calls it on.
     */
    public function failed(?Throwable $exception): void
    {
        $handedBack = collect($this->reconciliationTokens)
            ->filter(fn (?string $token, string $periodId): bool => $token !== null && $this->handBack($periodId, $token))
            ->keys();

        Log::error('Catch-all budget rebuild gave up: run budgets:rebuild-catch-all to heal these periods', [
            'user_id' => $this->userId,
            'space_id' => $this->spaceId,
            'stale_period_ids' => $handedBack->all(),
            'possibly_stale_period_ids' => array_keys(array_filter($this->reconciliationTokens, fn (?string $token): bool => $token === null)),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * The catch-all gives up what other budgets hold, so whatever is writing
     * them, for this user and space, goes first. That covers runs queued by an
     * earlier edit whose own rebuild this one superseded. Periods claimed by
     * another rebuild are not waited on: two rebuilds would wait on each other.
     *
     * A flag no run has touched for the whole window is stuck, not pending. It
     * could hold up every budget edit of the user for good, so it is reported
     * and ignored. It stays set: clearing it is not this job's call.
     */
    private function isWaitingOnBackfill(): bool
    {
        [$stuck, $pending] = BudgetPeriod::query()
            ->where('processing_historical', true)
            ->whereHas('budget', fn (Builder $budget) => $budget->where('user_id', $this->userId)->where('space_id', $this->spaceId))
            ->where(fn (Builder $periods) => $periods
                ->whereNull('reconciliation_token')
                ->orWhereHas('budget', fn (Builder $budget) => $budget->where('is_catch_all', false)))
            ->get(['id', 'updated_at'])
            ->partition(fn (BudgetPeriod $period): bool => $period->updated_at->lt(now()->subMinutes(self::WINDOW_MINUTES)));

        if ($stuck->isNotEmpty()) {
            Log::warning('Catch-all budget rebuild is ignoring a stuck processing flag', [
                'user_id' => $this->userId,
                'space_id' => $this->spaceId,
                'stuck_period_ids' => $stuck->modelKeys(),
            ]);
        }

        return $pending->isNotEmpty();
    }

    /**
     * The row lock is held across the whole period, which also blocks a
     * concurrent edit from stamping a new token mid-run.
     */
    private function rebuild(BudgetTransactionService $service, string $periodId, ?string $token): void
    {
        DB::transaction(function () use ($service, $periodId, $token): void {
            $period = BudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->first();

            if ($period === null || ($token !== null && $period->reconciliation_token !== $token)) {
                return;
            }

            $service->rebuildCatchAllPeriod($period);

            if ($token !== null) {
                $this->handBack($periodId, $token);
            }
        });
    }

    private function handBack(string $periodId, string $token): bool
    {
        return BudgetPeriod::query()
            ->whereKey($periodId)
            ->where('reconciliation_token', $token)
            ->update(['processing_historical' => false, 'reconciliation_token' => null]) === 1;
    }
}
