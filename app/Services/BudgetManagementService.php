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
    /**
     * How far ahead of today a one-off allocation may land.
     */
    private const ONE_OFF_ALLOCATION_HORIZON_MONTHS = 12;

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
     * describe what moved. Periods already closed keep the figure they ran on,
     * and a period holding a one-off amount keeps it: only the regular
     * allocation it goes back to moves.
     *
     * @return array<string, mixed>
     */
    private function applyAllocation(Budget $budget, int $amount, CarbonImmutable $applicationDate): array
    {
        $date = $applicationDate->startOfDay();
        $affected = $this->periodsFrom($budget, $date);

        foreach ($affected as $period) {
            $period->assignRegularAllocation($amount);
        }

        $current = $affected->first(fn (BudgetPeriod $period): bool => $period->start_date <= $date && $period->end_date >= $date);
        if ($current !== null) {
            $this->reconcileNotificationFlags($current->fresh());
        }

        return [
            'application_date' => $date->toDateString(),
            'effective_from' => $affected->first()->start_date->toDateString(),
            'current_period_changed' => $current !== null && ! $current->hasOneOffAllocation(),
            'affected_period_count' => $affected->count(),
            'affected_period_ids' => $affected->modelKeys(),
            'one_off_period_ids' => $affected->filter(fn (BudgetPeriod $period): bool => $period->hasOneOffAllocation())->values()->modelKeys(),
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
                $currentPeriod->regularAllocatedAmount(),
            );

            if ($directSuccessor->id !== $planningPeriod->id) {
                throw (new ModelNotFoundException)->setModel(BudgetPeriod::class, [$periodId]);
            }

            $this->writeSinglePeriodAllocation($budget, $planningPeriod, $allocatedAmount, isCurrentPeriod: false);

            return $planningPeriod->fresh();
        }, attempts: 5);
    }

    /**
     * Give one period its own allocation, the one in progress or a future one,
     * and leave every other period on the figure it had: a one-off month. A
     * null amount drops the period's one-off amount and puts it back on the
     * regular allocation.
     *
     * The chain is walked forward one period at a time up to the target, so a
     * date further out than the periods generated so far leaves no gap behind -
     * generatePeriod() without a start date anchors at the end of the chain and
     * would never fill one.
     *
     * @return array{budget: Budget, period: BudgetPeriod, following_period: BudgetPeriod, current_period_changed: bool}
     */
    public function updatePeriodAllocation(
        User $user,
        Space $space,
        string $budgetId,
        CarbonImmutable $periodDate,
        ?int $allocatedAmount,
        CarbonImmutable $applicationDate,
    ): array {
        return DB::transaction(function () use ($user, $space, $budgetId, $periodDate, $allocatedAmount, $applicationDate): array {
            $this->assertSpaceAccess($user, $space);
            $budget = $this->ownedBudget($user, $space, $budgetId, lock: true);
            $this->assertMutable($budget);

            $currentPeriod = $budget->getCurrentPeriod($applicationDate)
                ?? $this->periods->generatePeriod($budget, null, $applicationDate);
            $this->assertWithinOneOffWindow($periodDate, $currentPeriod, $applicationDate);

            $period = $this->chainPeriodCovering($budget, $currentPeriod, $periodDate);
            $isCurrentPeriod = $period->is($currentPeriod);
            $followingPeriod = $this->writeSinglePeriodAllocation($budget, $period, $allocatedAmount, $isCurrentPeriod);

            return [
                'budget' => $budget->fresh()->load(['categories', 'labels']),
                'period' => $period->fresh(),
                'following_period' => $followingPeriod,
                'current_period_changed' => $isCurrentPeriod,
            ];
        }, attempts: 5);
    }

    /**
     * Periods that have closed keep the figure they ran on, and the window ahead
     * is capped so a mistyped year cannot generate decades of periods.
     */
    private function assertWithinOneOffWindow(CarbonImmutable $periodDate, BudgetPeriod $currentPeriod, CarbonImmutable $applicationDate): void
    {
        if ($periodDate->lessThan($currentPeriod->start_date)) {
            throw ValidationException::withMessages(['date' => 'That period has already closed; only the period in progress and future ones can change.']);
        }

        if ($periodDate->greaterThan($applicationDate->addMonths(self::ONE_OFF_ALLOCATION_HORIZON_MONTHS))) {
            throw ValidationException::withMessages(['date' => 'A one-off allocation can be set at most '.self::ONE_OFF_ALLOCATION_HORIZON_MONTHS.' months ahead.']);
        }
    }

    /**
     * Walk the chain forward from $period until one covers $date, creating the
     * periods that do not exist yet with the allocation of the one before them,
     * the same way the nightly generation would.
     */
    private function chainPeriodCovering(Budget $budget, BudgetPeriod $period, CarbonImmutable $date): BudgetPeriod
    {
        while ($period->end_date->lessThan($date)) {
            $successor = $this->periods->ensureSuccessor($budget, $period, $period->regularAllocatedAmount());

            // A stored successor that does not end later would loop forever.
            if (! $successor->end_date->greaterThan($period->end_date)) {
                throw ValidationException::withMessages(['date' => 'The budget periods leading to that date could not be resolved.']);
            }

            $period = $successor;
        }

        return $period;
    }

    /**
     * Write an allocation to one period without it leaking into the next, or
     * put it back on the regular allocation when the amount is null.
     *
     * The successor is created before the write so the caller can show that it
     * kept the regular allocation.
     */
    private function writeSinglePeriodAllocation(Budget $budget, BudgetPeriod $period, ?int $allocatedAmount, bool $isCurrentPeriod): BudgetPeriod
    {
        $followingPeriod = $this->periods->ensureSuccessor($budget, $period, $period->regularAllocatedAmount());

        if ($allocatedAmount === null) {
            $period->restoreRegularAllocation();
        } else {
            $period->assignOneOffAllocation($allocatedAmount);
        }

        if ($isCurrentPeriod) {
            $this->reconcileNotificationFlags($period->fresh());
        }

        return $followingPeriod;
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
