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
    protected $signature = 'budgets:rebuild-catch-all
        {--user= : Only rebuild the catch-all budgets of the user with this email address}
        {--dry-run : Report what would change without making changes}';

    protected $description = 'Heal the running catch-all budgets, which used to keep what a budget created later also counts: periods still running are re-derived, closed ones only lose what another budget counts as well';

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
     * @param  Collection<int, array{period: BudgetPeriod, state: string, changes: array{released: int, adjusted: int, absorbed: int, spent_change: int}}>  $results
     */
    private function report(Collection $results, string $verb): void
    {
        $changed = $results->filter(fn (array $result): bool => $result['changes']['released'] + $result['changes']['adjusted'] + $result['changes']['absorbed'] > 0);
        $total = fn (string $state, string $change): int => $results->where('state', $state)->sum("changes.{$change}");

        if ($changed->isNotEmpty()) {
            $this->table(
                ['Budget', 'Period', 'State', 'Released', 'Adjusted', 'Absorbed', 'Spent change (cents)'],
                $changed->map(fn (array $result): array => [
                    $result['period']->budget->name,
                    $result['period']->start_date->toDateString().' to '.$result['period']->end_date->toDateString(),
                    $result['state'],
                    $result['changes']['released'],
                    $result['changes']['adjusted'],
                    $result['changes']['absorbed'],
                    $result['changes']['spent_change'],
                ])->all(),
            );
        }

        $this->info("{$results->count()} catch-all period(s) checked.");
        $this->info("Running periods: {$total('open', 'released')} transaction(s) {$verb} released, {$total('open', 'adjusted')} {$verb} adjusted, {$total('open', 'absorbed')} {$verb} absorbed.");
        $this->info("Closed periods: {$total('closed', 'released')} transaction(s) {$verb} released, {$total('closed', 'adjusted')} {$verb} reduced; a closed period absorbs nothing.");

        $carryOverPeriods = $changed
            ->filter(fn (array $result): bool => $result['state'] === 'closed' && $result['period']->budget->rollover_type === RolloverType::CarryOver)
            ->count();

        if ($carryOverPeriods > 0) {
            $this->warn("{$carryOverPeriods} closed period(s) of carry-over budgets changed: the nightly budgets:generate-periods recalculates what each one carries into the next period.");
        }
    }
}
