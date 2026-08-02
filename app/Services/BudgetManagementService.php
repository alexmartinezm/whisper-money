<?php

namespace App\Services;

use App\Jobs\AssignHistoricalTransactionsToBudget;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BudgetManagementService
{
    public function __construct(private readonly BudgetPeriodService $periods) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $categoryIds
     * @param  array<int, string>  $labelIds
     * @return array{budget: Budget, period: BudgetPeriod, previous_period: BudgetPeriod, processing_historical: bool}
     */
    public function create(User $user, Space $space, array $attributes, array $categoryIds, array $labelIds): array
    {
        $categoryIds = $this->normaliseIds($categoryIds);
        $labelIds = $this->normaliseIds($labelIds);

        $result = DB::transaction(function () use ($user, $space, $attributes, $categoryIds, $labelIds): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->assertSpaceAccess($user, $space);

            $categories = $this->ownedReferences(Category::class, $user, $space, $categoryIds, 'category_ids');
            $labels = $this->ownedReferences(Label::class, $user, $space, $labelIds, 'label_ids');
            $isCatchAll = (bool) ($attributes['is_catch_all'] ?? false);

            if (! $isCatchAll && $categories->isEmpty() && $labels->isEmpty()) {
                throw ValidationException::withMessages(['selection' => 'You must select at least one category or label.']);
            }

            if ($isCatchAll && ($categories->isNotEmpty() || $labels->isNotEmpty())) {
                throw ValidationException::withMessages(['tracking' => 'A catch-all budget cannot have categories or labels.']);
            }

            if ($isCatchAll && Budget::query()->where('user_id', $user->id)->where('is_catch_all', true)->exists()) {
                throw ValidationException::withMessages(['is_catch_all' => 'You already have a catch-all budget.']);
            }

            $setting = $user->setting;
            $budget = new Budget([
                'user_id' => $user->id,
                'space_id' => $space->id,
                'name' => $attributes['name'],
                'period_type' => $attributes['period_type'],
                'period_start_day' => $attributes['period_start_day'] ?? null,
                'rollover_type' => $attributes['rollover_type'],
                'is_catch_all' => $isCatchAll,
                'notify_on_new_transaction' => $setting->budget_notify_on_new_transaction ?? false,
                'notify_on_close_to_limit' => $setting->budget_notify_on_close_to_limit ?? true,
                'notify_on_over_limit' => $setting->budget_notify_on_over_limit ?? true,
            ]);
            $budget->save();
            $budget->categories()->sync($categories->modelKeys());
            $budget->labels()->sync($labels->modelKeys());

            $period = $this->periods->generatePeriod($budget, (int) $attributes['allocated_amount'], null, true);
            $previousPeriod = $this->periods->generatePreviousPeriod($budget, $period, (int) $attributes['allocated_amount'], true);

            return compact('budget', 'period', 'previousPeriod');
        }, attempts: 5);

        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['period'])->afterCommit();
        AssignHistoricalTransactionsToBudget::dispatch($result['budget'], $result['previousPeriod'])->afterCommit();

        return [
            'budget' => $result['budget']->load(['categories', 'labels']),
            'period' => $result['period'],
            'previous_period' => $result['previousPeriod'],
            'processing_historical' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array{budget: Budget, adjustment: array<string, mixed>|null}
     */
    public function update(User $user, Space $space, string $budgetId, array $changes, CarbonImmutable $applicationDate): array
    {
        $result = DB::transaction(function () use ($user, $space, $budgetId, $changes, $applicationDate): array {
            $this->assertSpaceAccess($user, $space);
            $budget = $this->ownedBudget($user, $space, $budgetId, lock: true);
            $reconciliationPeriods = new Collection;

            if ($changes === []) {
                throw ValidationException::withMessages(['budget' => 'Provide a mutable budget field.']);
            }

            if (array_key_exists('category_ids', $changes) || array_key_exists('label_ids', $changes)) {
                if ($budget->is_catch_all) {
                    throw ValidationException::withMessages(['tracking' => 'A catch-all budget cannot have categories or labels.']);
                }

                $existingCategoryIds = $budget->categories()->pluck('categories.id')->all();
                $existingLabelIds = $budget->labels()->pluck('labels.id')->all();
                $categoryIds = array_key_exists('category_ids', $changes)
                    ? $this->normaliseIds($changes['category_ids'])
                    : $existingCategoryIds;
                $labelIds = array_key_exists('label_ids', $changes)
                    ? $this->normaliseIds($changes['label_ids'])
                    : $existingLabelIds;
                $categories = $this->ownedReferences(Category::class, $user, $space, $categoryIds, 'category_ids');
                $labels = $this->ownedReferences(Label::class, $user, $space, $labelIds, 'label_ids');

                if ($categories->isEmpty() && $labels->isEmpty()) {
                    throw ValidationException::withMessages(['selection' => 'You must select at least one category or label.']);
                }

                $trackingChanged = collect($existingCategoryIds)->sort()->values()->all()
                    !== collect($categories->modelKeys())->sort()->values()->all()
                    || collect($existingLabelIds)->sort()->values()->all()
                    !== collect($labels->modelKeys())->sort()->values()->all();

                if ($trackingChanged) {
                    $budget->categories()->sync($categories->modelKeys());
                    $budget->labels()->sync($labels->modelKeys());

                    $currentPeriod = $budget->periods()
                        ->whereDate('start_date', '<=', $applicationDate->toDateString())
                        ->whereDate('end_date', '>=', $applicationDate->toDateString())
                        ->lockForUpdate()
                        ->first();
                    if ($currentPeriod !== null) {
                        $reconciliationPeriods->push($this->claimForReconciliation($currentPeriod));
                    }

                    $catchAllPeriod = BudgetPeriod::query()
                        ->whereHas('budget', fn ($query) => $query
                            ->where('user_id', $user->id)
                            ->where('space_id', $space->id)
                            ->where('is_catch_all', true))
                        ->whereDate('start_date', '<=', $applicationDate->toDateString())
                        ->whereDate('end_date', '>=', $applicationDate->toDateString())
                        ->lockForUpdate()
                        ->first();
                    if ($catchAllPeriod !== null) {
                        $reconciliationPeriods->push($this->claimForReconciliation($catchAllPeriod));
                    }
                }
            }

            if (array_key_exists('name', $changes)) {
                $budget->name = $changes['name'];
            }

            $adjustment = null;
            if (array_key_exists('allocated_amount', $changes)) {
                $date = $applicationDate->startOfDay();
                $affected = $budget->periods()
                    ->whereDate('start_date', '>=', $date->toDateString())
                    ->orderBy('start_date')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($affected->isEmpty()) {
                    $lastPeriod = $budget->periods()->orderByDesc('end_date')->orderByDesc('id')->first();
                    $startDate = $lastPeriod
                        ? CarbonImmutable::parse($lastPeriod->end_date)->addDay()
                        : $date;
                    $this->periods->generatePeriod($budget, null, $startDate);
                    $affected = $budget->periods()
                        ->whereDate('start_date', '>=', $date->toDateString())
                        ->orderBy('start_date')
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($affected->isEmpty()) {
                        $lastPeriod = $budget->periods()->orderByDesc('end_date')->orderByDesc('id')->firstOrFail();
                        $this->periods->generatePeriod(
                            $budget,
                            null,
                            CarbonImmutable::parse($lastPeriod->end_date)->addDay(),
                        );
                        $affected = $budget->periods()
                            ->whereDate('start_date', '>=', $date->toDateString())
                            ->orderBy('start_date')
                            ->orderBy('id')
                            ->lockForUpdate()
                            ->get();
                    }
                }

                $amount = (int) $changes['allocated_amount'];
                foreach ($affected as $period) {
                    $period->update(['allocated_amount' => $amount]);
                }

                $current = $affected->first(fn (BudgetPeriod $period): bool => $period->start_date <= $date && $period->end_date >= $date);
                if ($current !== null) {
                    $this->reconcileNotificationFlags($current->fresh());
                }

                $adjustment = [
                    'application_date' => $date->toDateString(),
                    'effective_from' => $affected->first()->start_date->toDateString(),
                    'current_period_changed' => $current !== null,
                    'affected_period_count' => $affected->count(),
                    'affected_period_ids' => $affected->modelKeys(),
                    'historical_periods_changed' => 0,
                ];
            }

            $budget->save();

            return [
                'budget' => $budget->fresh()->load(['categories', 'labels']),
                'adjustment' => $adjustment,
                'reconciliation_periods' => $reconciliationPeriods,
            ];
        }, attempts: 5);

        foreach ($result['reconciliation_periods'] as $period) {
            $reconciliationBudget = $period->budget()->firstOrFail();
            AssignHistoricalTransactionsToBudget::dispatch(
                $reconciliationBudget,
                $period,
                $period->reconciliation_token,
            )->afterCommit();
        }
        unset($result['reconciliation_periods']);

        return $result;
    }

    /**
     * Mark a period as awaiting reconciliation and stamp it with the token that
     * identifies this run, so a job queued by an earlier edit can tell it has
     * been overtaken and leave the period to the newer one.
     */
    private function claimForReconciliation(BudgetPeriod $period): BudgetPeriod
    {
        $period->update([
            'processing_historical' => true,
            'reconciliation_token' => (string) Str::uuid(),
        ]);

        return $period;
    }

    public function updateNextPeriodAllocation(
        User $user,
        Space $space,
        string $budgetId,
        string $periodId,
        int $allocatedAmount,
        CarbonImmutable $applicationDate,
    ): BudgetPeriod {
        return DB::transaction(function () use ($user, $space, $budgetId, $periodId, $allocatedAmount, $applicationDate): BudgetPeriod {
            $this->assertSpaceAccess($user, $space);
            $budget = $this->ownedBudget($user, $space, $budgetId, lock: true);
            $planningPeriod = $budget->periods()->whereKey($periodId)->lockForUpdate()->firstOrFail();
            $currentPeriod = $budget->getCurrentPeriod($applicationDate);

            if ($currentPeriod === null) {
                throw (new ModelNotFoundException)->setModel(BudgetPeriod::class);
            }

            $directSuccessor = $this->periods->ensureSuccessor(
                $budget,
                $currentPeriod,
                $currentPeriod->allocated_amount,
            );

            if ($directSuccessor->id !== $planningPeriod->id) {
                throw (new ModelNotFoundException)->setModel(BudgetPeriod::class, [$periodId]);
            }

            $this->periods->ensureSuccessor(
                $budget,
                $planningPeriod,
                $planningPeriod->allocated_amount,
            );

            $planningPeriod->update(['allocated_amount' => $allocatedAmount]);

            return $planningPeriod->fresh();
        }, attempts: 5);
    }

    public function delete(User $user, Space $space, string $budgetId): string
    {
        return DB::transaction(function () use ($user, $space, $budgetId): string {
            $this->assertSpaceAccess($user, $space);
            $budget = $this->ownedBudget($user, $space, $budgetId, lock: true);
            $id = $budget->id;
            $budget->delete();

            return $id;
        }, attempts: 5);
    }

    /** @return array<int, string> */
    private function normaliseIds(array $ids): array
    {
        return collect($ids)->map(fn (mixed $id): string => (string) $id)->filter()->unique()->values()->all();
    }

    private function assertSpaceAccess(User $user, Space $space): void
    {
        if (! $space->hasMember($user)) {
            throw ValidationException::withMessages(['space' => 'You do not have access to that space.']);
        }
    }

    /** @return Collection<int, Model> */
    private function ownedReferences(string $model, User $user, Space $space, array $ids, string $key): Collection
    {
        $references = $model::query()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->whereIn('id', $ids)
            ->get();

        if ($references->count() !== count($ids)) {
            throw ValidationException::withMessages([$key => 'Every reference must belong to the authenticated user and selected space.']);
        }

        return $references;
    }

    private function ownedBudget(User $user, Space $space, string $budgetId, bool $lock = false): Budget
    {
        $query = Budget::query()
            ->whereKey($budgetId)
            ->where('user_id', $user->id)
            ->where('space_id', $space->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        $budget = $query->first();
        if ($budget === null) {
            throw ValidationException::withMessages(['budget_id' => 'Budget not found.']);
        }

        return $budget;
    }

    private function reconcileNotificationFlags(BudgetPeriod $period): void
    {
        $status = $period->limitStatus();
        $period->update([
            'close_to_limit_notified' => in_array($status, ['close_to_limit', 'over_limit'], true),
            'over_limit_notified' => $status === 'over_limit',
        ]);
    }
}
