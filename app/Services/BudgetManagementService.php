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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
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

            if ($isCatchAll && Budget::query()->notArchived()->where('user_id', $user->id)->where('is_catch_all', true)->exists()) {
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

            $this->assertMutable($budget);

            if ($changes === []) {
                throw ValidationException::withMessages(['budget' => 'Provide a mutable budget field.']);
            }

            $reconciliationPeriods = $this->applyTrackingChange($user, $space, $budget, $changes, $applicationDate);

            if (array_key_exists('name', $changes)) {
                $budget->name = $changes['name'];
            }

            $adjustment = array_key_exists('allocated_amount', $changes)
                ? $this->applyAllocation($budget, (int) $changes['allocated_amount'], $applicationDate)
                : null;

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
     * Re-point a budget at different categories or labels.
     *
     * @param  array<string, mixed>  $changes
     * @return Collection<int, BudgetPeriod> the periods whose membership has to be recomputed, empty when the tracking did not move
     */
    private function applyTrackingChange(User $user, Space $space, Budget $budget, array $changes, CarbonImmutable $applicationDate): Collection
    {
        if (! array_key_exists('category_ids', $changes) && ! array_key_exists('label_ids', $changes)) {
            return new Collection;
        }

        if ($budget->is_catch_all) {
            throw ValidationException::withMessages(['tracking' => 'A catch-all budget cannot have categories or labels.']);
        }

        [$categories, $labels, $trackingChanged] = $this->resolveTracking($user, $space, $budget, $changes);

        if (! $trackingChanged) {
            return new Collection;
        }

        $budget->categories()->sync($categories->modelKeys());
        $budget->labels()->sync($labels->modelKeys());

        return $this->claimAffectedPeriods($user, $space, $budget, $applicationDate);
    }

    /**
     * The categories and labels the budget should end up tracking, plus whether
     * that is actually a change. A key the caller left out keeps what the budget
     * already had, so editing labels alone does not clear its categories.
     *
     * @param  array<string, mixed>  $changes
     * @return array{0: Collection<int, Model>, 1: Collection<int, Model>, 2: bool}
     */
    private function resolveTracking(User $user, Space $space, Budget $budget, array $changes): array
    {
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

        $trackingChanged = $this->sortedIds($existingCategoryIds) !== $this->sortedIds($categories->modelKeys())
            || $this->sortedIds($existingLabelIds) !== $this->sortedIds($labels->modelKeys());

        return [$categories, $labels, $trackingChanged];
    }

    /**
     * @param  array<int, string>  $ids
     * @return array<int, string>
     */
    private function sortedIds(array $ids): array
    {
        return collect($ids)->sort()->values()->all();
    }

    /**
     * Claim the periods a tracking change invalidates: the budget's own current
     * period, and the catch-all's, since spending moving in or out of this
     * budget changes what the catch-all absorbs.
     *
     * @return Collection<int, BudgetPeriod>
     */
    private function claimAffectedPeriods(User $user, Space $space, Budget $budget, CarbonImmutable $applicationDate): Collection
    {
        $periods = new Collection;

        $currentPeriod = $this->periodCovering($budget->periods(), $applicationDate);
        if ($currentPeriod !== null) {
            $periods->push($this->claimForReconciliation($currentPeriod));
        }

        $catchAllPeriod = $this->periodCovering(
            BudgetPeriod::query()->whereHas('budget', fn ($query) => $query
                ->where('user_id', $user->id)
                ->where('space_id', $space->id)
                ->where('is_catch_all', true)),
            $applicationDate,
        );
        if ($catchAllPeriod !== null) {
            $periods->push($this->claimForReconciliation($catchAllPeriod));
        }

        return $periods;
    }

    /**
     * @param  Builder<BudgetPeriod>|HasMany<BudgetPeriod, Budget>  $query
     */
    private function periodCovering(Builder|HasMany $query, CarbonImmutable $applicationDate): ?BudgetPeriod
    {
        return $query
            ->whereDate('start_date', '<=', $applicationDate->toDateString())
            ->whereDate('end_date', '>=', $applicationDate->toDateString())
            ->lockForUpdate()
            ->first();
    }

    /**
     * Write an allocation across the current period and every one after it, and
     * describe what moved. Periods already closed keep the figure they ran on.
     *
     * @return array<string, mixed>
     */
    private function applyAllocation(Budget $budget, int $amount, CarbonImmutable $applicationDate): array
    {
        $date = $applicationDate->startOfDay();
        $affected = $this->periodsFrom($budget, $date);

        foreach ($affected as $period) {
            $period->update(['allocated_amount' => $amount]);
        }

        $current = $affected->first(fn (BudgetPeriod $period): bool => $period->start_date <= $date && $period->end_date >= $date);
        if ($current !== null) {
            $this->reconcileNotificationFlags($current->fresh());
        }

        return [
            'application_date' => $date->toDateString(),
            'effective_from' => $affected->first()->start_date->toDateString(),
            'current_period_changed' => $current !== null,
            'affected_period_count' => $affected->count(),
            'affected_period_ids' => $affected->modelKeys(),
            'historical_periods_changed' => 0,
        ];
    }

    /**
     * The periods from $date onwards, locked for the write that follows.
     *
     * A budget whose chain has run out has none, so one is generated off the end
     * of the chain and the window is read again — twice at most, which is what
     * it takes when the chain ends before $date and the first generated period
     * still lands behind it.
     *
     * @return Collection<int, BudgetPeriod>
     */
    private function periodsFrom(Budget $budget, CarbonImmutable $date): Collection
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $affected = $budget->periods()
                ->whereDate('start_date', '>=', $date->toDateString())
                ->orderBy('start_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($affected->isNotEmpty()) {
                return $affected;
            }

            $lastPeriod = $budget->periods()->orderByDesc('end_date')->orderByDesc('id')->first();
            $this->periods->generatePeriod(
                $budget,
                null,
                $lastPeriod ? CarbonImmutable::parse($lastPeriod->end_date)->addDay() : $date,
            );
        }

        return $budget->periods()
            ->whereDate('start_date', '>=', $date->toDateString())
            ->orderBy('start_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
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

    /**
     * Freeze a budget: it keeps every figure it has already counted and stops
     * taking in anything new. One-way, which is why the record stays reachable
     * instead of being deleted.
     */
    public function archive(Budget $budget): Budget
    {
        return DB::transaction(function () use ($budget): Budget {
            $locked = Budget::query()->whereKey($budget->id)->lockForUpdate()->firstOrFail();

            // Idempotent on purpose: a second click must not move the date a
            // frozen budget's figures are pinned to.
            if (! $locked->isArchived()) {
                $locked->forceFill(['archived_at' => now()])->save();
            }

            return $locked;
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

    /**
     * Archiving is what makes a budget read-only: it keeps every figure it has
     * already counted, so letting it change afterwards would move a total that
     * is meant to be final.
     */
    private function assertMutable(Budget $budget): void
    {
        if ($budget->isArchived()) {
            throw ValidationException::withMessages(['budget' => 'An archived budget cannot be changed.']);
        }
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
