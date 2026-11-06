<?php

namespace App\Console\Commands;

use App\Enums\RolloverType;
use App\Models\BudgetPeriod;
use App\Models\User;
use App\Services\BudgetTransactionService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class RebuildCatchAllBudgets extends Command
{
    private const array LEFT_ALONE_REASONS = [
        BudgetTransactionService::CLAIMED_NOT_HELD => 'a budget tracks it today but holds no row for it',
        BudgetTransactionService::HOLDER_ROW_UNEXPLAINED => 'a budget holds a row that does not match what it tracks today',
    ];

    protected $signature = 'budgets:rebuild-catch-all
        {--user= : Only rebuild the catch-all budgets of the user with this email address}
        {--dry-run : Report what would change without making changes}';

    protected $description = 'Heal the running catch-all budgets, which used to keep what a budget created later also counts. Rows go only when another budget\'s row proves the double count; closed periods absorb nothing; what cannot be proved is left alone and listed';

    public function handle(BudgetTransactionService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $userEmail = $this->option('user');
        $userId = $userEmail ? User::query()->where('email', $userEmail)->value('id') : null;

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        if ($userEmail && $userId === null) {
            $this->error("User with email '{$userEmail}' not found.");

            return self::FAILURE;
        }

        $results = BudgetPeriod::query()
            ->ofRunningCatchAll($userId)
            ->with('budget')
            ->lazyById()
            ->map(fn (BudgetPeriod $period): array => [
                'period' => $period,
                'state' => $period->hasEnded() ? 'closed' : 'open',
                // Silent: rebuilding never sends budget emails.
                'changes' => $isDryRun ? $service->catchAllDrift($period) : $service->rebuildCatchAllPeriod($period),
            ])
            ->collect();

        $this->report($results, $isDryRun ? 'would be' : 'were');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, array{period: BudgetPeriod, state: string, changes: array{released_double_count: int, reduced_double_count: int, released_other: int, adjusted: int, absorbed: int, spent_change: int, left_alone: array<int, array{transaction_id: string, transaction_date: string, amount: int, reason: string}>}}>  $results
     */
    private function report(Collection $results, string $verb): void
    {
        $changed = $results->filter(fn (array $result): bool => $this->rowsChanged($result['changes']) + count($result['changes']['left_alone']) > 0);

        if ($changed->isNotEmpty()) {
            $this->table(
                ['Budget', 'Period', 'State', 'Double count released', 'Double count reduced', 'Released, other reason', 'Adjusted', 'Absorbed', 'Left alone', 'Spent change (cents)'],
                $changed->map(fn (array $result): array => [
                    $result['period']->budget->name,
                    $this->periodLabel($result['period']),
                    $result['state'],
                    $result['changes']['released_double_count'],
                    $result['changes']['reduced_double_count'],
                    $result['changes']['released_other'],
                    $result['changes']['adjusted'],
                    $result['changes']['absorbed'],
                    count($result['changes']['left_alone']),
                    $result['changes']['spent_change'],
                ])->all(),
            );
        }

        $this->reportLeftAlone($results);
        $this->reportTotals($results, $verb);

        $carryOverPeriods = $changed
            ->filter(fn (array $result): bool => $result['state'] === 'closed'
                && $this->rowsChanged($result['changes']) > 0
                && $result['period']->budget->rollover_type === RolloverType::CarryOver)
            ->count();

        if ($carryOverPeriods > 0) {
            $this->warn("{$carryOverPeriods} closed period(s) of carry-over budgets changed: the nightly budgets:generate-periods recalculates what each one carries into the next period.");
        }
    }

    /**
     * @param  array{released_double_count: int, reduced_double_count: int, released_other: int, adjusted: int, absorbed: int}  $changes
     */
    private function rowsChanged(array $changes): int
    {
        return $changes['released_double_count'] + $changes['reduced_double_count'] + $changes['released_other'] + $changes['adjusted'] + $changes['absorbed'];
    }

    private function periodLabel(BudgetPeriod $period): string
    {
        return $period->start_date->toDateString().' to '.$period->end_date->toDateString();
    }

    /**
     * Every row a person may want to look at, with why it was not touched.
     *
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function reportLeftAlone(Collection $results): void
    {
        $rows = $results->flatMap(fn (array $result): array => array_map(fn (array $row): array => [
            $this->periodLabel($result['period']),
            $row['transaction_id'],
            $row['transaction_date'],
            $row['amount'],
            self::LEFT_ALONE_REASONS[$row['reason']],
        ], $result['changes']['left_alone']));

        if ($rows->isNotEmpty()) {
            $this->warn("{$rows->count()} row(s) left alone, nothing proves they should change:");
            $this->table(['Period', 'Transaction', 'Date', 'Catch-all amount (cents)', 'Why it was left alone'], $rows->all());
        }
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $results
     */
    private function reportTotals(Collection $results, string $verb): void
    {
        $total = fn (string $state, string $change): int => $results->where('state', $state)->sum("changes.{$change}");

        $this->info("{$results->count()} catch-all period(s) checked.");
        $this->info("Running periods: {$total('open', 'released_double_count')} proved double count(s) {$verb} released, {$total('open', 'reduced_double_count')} {$verb} reduced; {$total('open', 'released_other')} row(s) {$verb} released for another reason, {$total('open', 'adjusted')} {$verb} adjusted, {$total('open', 'absorbed')} {$verb} absorbed.");
        $this->info("Closed periods: {$total('closed', 'released_double_count')} proved double count(s) {$verb} released, {$total('closed', 'reduced_double_count')} {$verb} reduced; a closed period absorbs nothing and changes nothing else.");
    }
}
