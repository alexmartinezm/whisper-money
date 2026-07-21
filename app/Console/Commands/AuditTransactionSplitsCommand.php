<?php

namespace App\Console\Commands;

use App\Enums\CategoryType;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditTransactionSplitsCommand extends Command
{
    protected $signature = 'transactions:audit-splits {--json : Emit stable JSON} {--fail-on-invalid : Return failure when anomalies exist}';

    protected $description = 'Read-only integrity audit of transaction splits';

    public function handle(): int
    {
        $report = $this->audit();

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Reviewed {$report['parents_reviewed']} parents and {$report['lines_reviewed']} lines; {$report['anomaly_count']} anomalies.");
            foreach ($report['anomalies'] as $anomaly) {
                $this->line($anomaly['code'].' '.$anomaly['id']);
            }
        }

        return $this->option('fail-on-invalid') && $report['anomaly_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array{parents_reviewed: int, lines_reviewed: int, anomaly_count: int, anomalies: list<array{code: string, id: string}>} */
    public function audit(): array
    {
        $anomalies = [];
        $parentsReviewed = 0;
        $linesReviewed = (int) DB::table('transaction_splits')->count();

        Transaction::query()
            ->withTrashed()
            ->whereHas('splits')
            ->with(['splits', 'splits.category' => fn ($query) => $query->withTrashed()])
            ->chunkById(200, function ($transactions) use (&$anomalies, &$parentsReviewed): void {
                foreach ($transactions as $transaction) {
                    $parentsReviewed++;
                    $splits = $transaction->splits;
                    $this->check(count($splits) < 2, 'parent_has_fewer_than_two_lines', $transaction->id, $anomalies);
                    $this->check((int) $splits->sum('amount') !== $transaction->amount, 'split_sum_mismatch', $transaction->id, $anomalies);
                    $this->check($splits->contains(fn ($split): bool => $split->amount === 0), 'split_amount_zero', $transaction->id, $anomalies);
                    $sign = $transaction->amount <=> 0;
                    $this->check($sign === 0 || $splits->contains(fn ($split): bool => ($split->amount <=> 0) !== $sign), 'split_sign_mismatch', $transaction->id, $anomalies);

                    $positions = $splits->pluck('position')->sort()->values()->all();
                    $this->check($positions !== range(0, max(count($positions) - 1, 0)), 'position_not_contiguous', $transaction->id, $anomalies);

                    $missing = $splits->contains(fn ($split): bool => $split->category === null || $split->category->trashed());
                    $this->check($missing, 'category_missing_or_deleted', $transaction->id, $anomalies);
                    $this->check($splits->contains(fn ($split): bool => $split->category !== null && ($split->category->user_id !== $transaction->user_id || $split->category->space_id !== $transaction->space_id)), 'category_owner_or_space_mismatch', $transaction->id, $anomalies);

                    $types = $splits->map(fn ($split): ?string => $split->category?->type?->value)->filter()->unique();
                    $validTypes = [CategoryType::Expense->value, CategoryType::Income->value];
                    $this->check($types->count() !== 1 || ! in_array($types->first(), $validTypes, true), 'category_type_invalid_or_mixed', $transaction->id, $anomalies);

                    $this->check($transaction->category_id !== null || $transaction->category_source !== null || $transaction->categorized_by_rule_id !== null || $transaction->ai_model !== null || $transaction->ai_confidence !== null, 'parent_classification_present', $transaction->id, $anomalies);
                }
            });

        DB::table('transaction_splits as split')
            ->leftJoin('transactions as parent', 'parent.id', '=', 'split.transaction_id')
            ->whereNull('parent.id')
            ->orderBy('split.id')
            ->pluck('split.id')
            ->each(fn (string $id) => $this->check(true, 'parent_missing', $id, $anomalies));

        usort($anomalies, fn (array $left, array $right): int => [$left['code'], $left['id']] <=> [$right['code'], $right['id']]);

        return [
            'parents_reviewed' => $parentsReviewed,
            'lines_reviewed' => $linesReviewed,
            'anomaly_count' => count($anomalies),
            'anomalies' => $anomalies,
        ];
    }

    /** @param list<array{code: string, id: string}> $anomalies */
    private function check(bool $invalid, string $code, string $id, array &$anomalies): void
    {
        if ($invalid) {
            $anomalies[] = ['code' => $code, 'id' => $id];
        }
    }
}
