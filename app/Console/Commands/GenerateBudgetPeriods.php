<?php

namespace App\Console\Commands;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Services\BudgetPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class GenerateBudgetPeriods extends Command
{
    protected $signature = 'budgets:generate-periods';

    protected $description = 'Generate upcoming budget periods and close completed ones';

    public function __construct(protected BudgetPeriodService $budgetPeriodService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Generating budget periods...');

        $applicationDate = CarbonImmutable::today();
        $budgets = Budget::query()->get();
        $generatedCount = 0;
        $closedCount = 0;

        foreach ($budgets as $budget) {
            $currentPeriod = $budget->getCurrentPeriod($applicationDate);

            if ($currentPeriod === null) {
                $currentPeriod = $this->budgetPeriodService->generatePeriod($budget, null, $applicationDate);
                $generatedCount++;
                $this->info("Generated initial period for budget: {$budget->name}");
            }

            $cursor = $currentPeriod;
            for ($i = 0; $i < 2; $i++) {
                $successor = $this->budgetPeriodService->ensureSuccessor(
                    $budget,
                    $cursor,
                    $cursor->allocated_amount,
                );
                $generatedCount += $successor->wasRecentlyCreated ? 1 : 0;
                $cursor = $successor;
            }

            $completedPeriods = BudgetPeriod::query()
                ->where('budget_id', $budget->id)
                ->whereDate('end_date', '<', $applicationDate->toDateString())
                ->get();

            foreach ($completedPeriods as $period) {
                $this->budgetPeriodService->closePeriod($period);
                $closedCount++;
            }
        }

        $this->info("Generated {$generatedCount} new periods");
        $this->info("Closed {$closedCount} completed periods");

        return Command::SUCCESS;
    }
}
