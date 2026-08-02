<?php

namespace App\Jobs;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Services\BudgetTransactionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssignHistoricalTransactionsToBudget implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 10;

    /**
     * Create a new job instance.
     *
     * `$reconciliationToken` is the value the period carried when this run was
     * queued. Editing what a budget tracks twice in a row queues two of these,
     * and without a way to tell them apart the first to finish would clear
     * `processing_historical` while the second is still rewriting the rows
     * underneath it — reporting settled numbers that are still moving.
     */
    public function __construct(
        public Budget $budget,
        public BudgetPeriod $period,
        public ?string $reconciliationToken = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(BudgetTransactionService $service): void
    {
        // The row lock is held across the whole reconciliation so two runs
        // cannot interleave their writes. It also blocks a concurrent edit from
        // stamping a new token mid-run, which is what makes the check below
        // sufficient rather than merely likely.
        DB::transaction(function () use ($service): void {
            $period = BudgetPeriod::query()
                ->whereKey($this->period->id)
                ->lockForUpdate()
                ->first();

            if ($period === null) {
                return;
            }

            if ($this->isSuperseded($period)) {
                Log::info('Skipping superseded historical transaction assignment', [
                    'budget_id' => $this->budget->id,
                    'budget_period_id' => $period->id,
                    'reconciliation_token' => $this->reconciliationToken,
                ]);

                return;
            }

            Log::info('Starting historical transaction assignment', [
                'budget_id' => $this->budget->id,
                'budget_period_id' => $period->id,
                'period_start' => $period->start_date,
                'period_end' => $period->end_date,
                'user_id' => $this->budget->user_id,
            ]);

            $count = $service->assignHistoricalTransactionsToPeriod($period);

            $this->release($period);

            Log::info("Assigned {$count} historical transactions to budget period", [
                'budget_id' => $this->budget->id,
                'budget_period_id' => $period->id,
                'user_id' => $this->budget->user_id,
            ]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        $period = BudgetPeriod::query()->whereKey($this->period->id)->first();

        // A newer reconciliation owns the period now, so it owns the flag too:
        // clearing it here would announce numbers that are still being written.
        if ($period !== null && ! $this->isSuperseded($period)) {
            $this->release($period);
        }

        Log::error('Historical transaction assignment failed', [
            'budget_id' => $this->budget->id,
            'budget_period_id' => $this->period->id,
            'exception' => $exception?->getMessage(),
        ]);
    }

    /** Whether a later reconciliation has claimed this period. */
    private function isSuperseded(BudgetPeriod $period): bool
    {
        return $period->reconciliation_token !== $this->reconciliationToken;
    }

    /**
     * Hand the period back, but only if it is still ours. The token guard makes
     * this a no-op for a run that lost the race, so the winner keeps the flag
     * raised until it is genuinely finished.
     */
    private function release(BudgetPeriod $period): void
    {
        BudgetPeriod::query()
            ->whereKey($period->id)
            ->when(
                $this->reconciliationToken === null,
                fn ($query) => $query->whereNull('reconciliation_token'),
                fn ($query) => $query->where('reconciliation_token', $this->reconciliationToken),
            )
            ->update([
                'processing_historical' => false,
                'reconciliation_token' => null,
            ]);
    }
}
