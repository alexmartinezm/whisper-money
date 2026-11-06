<?php

namespace App\Jobs;

use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-derive catch-all periods after what the other budgets claim there changed:
 * a budget created, deleted or archived.
 *
 * The catch-all keeps the rows it absorbed until something re-derives them, so
 * without this a new budget would count those expenses alongside it, and a
 * deleted or archived one would leave them counted nowhere.
 *
 * Each period comes claimed with a reconciliation token, like any backfill
 * ({@see AssignHistoricalTransactionsToBudget}): a run that lost its token to a
 * newer edit leaves that period, and its flag, to whoever holds it now.
 */
class RebuildCatchAllBudgetPeriods implements ShouldQueue
{
    use Queueable;

    /**
     * Waiting on a backfill releases the job without failing it, which still
     * spends a try: retried for a while instead, and failed after three genuine
     * exceptions.
     */
    public int $maxExceptions = 3;

    public int $backoff = 10;

    /**
     * @param  array<string, string>  $reconciliationTokens  by period id, the catch-all periods this run claimed
     * @param  array<int, string>  $awaitPeriodIds  periods of the budget being created, whose backfill goes first
     */
    public function __construct(
        public array $reconciliationTokens,
        public array $awaitPeriodIds = [],
    ) {}

    public function retryUntil(): Carbon
    {
        return now()->addMinutes(15);
    }

    public function handle(BudgetTransactionService $service): void
    {
        // A closed period only gives up what another budget holds, so the new
        // budget has to be holding it by now.
        if (BudgetPeriod::query()->whereKey($this->awaitPeriodIds)->where('processing_historical', true)->exists()) {
            $this->release($this->backoff);

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
        foreach ($this->reconciliationTokens as $periodId => $token) {
            $this->handBack($periodId, $token);
        }

        Log::error('Catch-all budget rebuild failed', [
            'budget_period_ids' => array_keys($this->reconciliationTokens),
            'exception' => $exception?->getMessage(),
        ]);
    }

    /**
     * The row lock is held across the whole period, which also blocks a
     * concurrent edit from stamping a new token mid-run.
     */
    private function rebuild(BudgetTransactionService $service, string $periodId, string $token): void
    {
        DB::transaction(function () use ($service, $periodId, $token): void {
            $period = BudgetPeriod::query()->whereKey($periodId)->lockForUpdate()->first();

            if ($period?->reconciliation_token !== $token) {
                return;
            }

            $service->rebuildCatchAllPeriod($period);
            $this->handBack($periodId, $token);
        });
    }

    private function handBack(string $periodId, string $token): void
    {
        BudgetPeriod::query()
            ->whereKey($periodId)
            ->where('reconciliation_token', $token)
            ->update(['processing_historical' => false, 'reconciliation_token' => null]);
    }
}
