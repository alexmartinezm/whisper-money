<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\BudgetTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileTransactionSplitsCommand extends Command
{
    protected $signature = 'transactions:reconcile-splits {--execute : Apply reconciliation} {--chunk=200 : Parents per chunk}';

    protected $description = 'Reconcile split transaction budgets and sync cursors';

    public function __construct(
        private readonly AuditTransactionSplitsCommand $auditor,
        private readonly BudgetTransactionService $budgets,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $report = $this->auditor->audit();
        $execute = (bool) $this->option('execute');

        if ($execute && $report['anomaly_count'] > 0) {
            $this->error("Reconciliation blocked: {$report['anomaly_count']} split anomalies found.");

            return self::FAILURE;
        }

        $inspected = $report['parents_reviewed'];
        $reconciled = 0;
        $errors = 0;
        $chunk = max(1, (int) $this->option('chunk'));

        if ($execute) {
            Transaction::query()->whereHas('splits')->chunkById($chunk, function ($transactions) use (&$reconciled, &$errors): void {
                foreach ($transactions as $transaction) {
                    try {
                        DB::transaction(function () use ($transaction): void {
                            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
                            $this->budgets->assignTransaction($locked);
                            $nextTimestamp = now();
                            if ($locked->updated_at === null || $nextTimestamp->gt($locked->updated_at)) {
                                DB::table('transactions')
                                    ->where('id', $locked->id)
                                    ->update(['updated_at' => $nextTimestamp->format('Y-m-d H:i:s.u')]);
                            }
                        }, attempts: 5);
                        $reconciled++;
                    } catch (Throwable $exception) {
                        report($exception);
                        $errors++;
                    }
                }
            });
        }

        $duration = round(microtime(true) - $startedAt, 3);
        $this->info(sprintf(
            '%s: inspected=%d reconciled=%d budgets_updated=%d errors=%d duration=%0.3fs',
            $execute ? 'Executed' : 'Dry run',
            $inspected,
            $reconciled,
            $reconciled,
            $errors,
            $duration,
        ));

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
