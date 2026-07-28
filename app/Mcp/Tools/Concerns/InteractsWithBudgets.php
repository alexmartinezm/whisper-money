<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

trait InteractsWithBudgets
{
    protected function budgetFor(User $user, Space $space, string $budgetId): Budget
    {
        $budget = Budget::query()
            ->whereKey($budgetId)
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->with(['categories' => fn ($query) => $query->orderBy('name')->orderBy('id'), 'labels' => fn ($query) => $query->orderBy('name')->orderBy('id')])
            ->first();

        if ($budget === null) {
            throw ValidationException::withMessages(['budget_id' => 'Budget not found.']);
        }

        return $budget;
    }

    /** @return Collection<int, Category> */
    protected function categoriesFor(User $user, Space $space, array $ids): Collection
    {
        return $this->referencesFor(Category::class, $user, $space, $ids, 'category_ids');
    }

    /** @return Collection<int, Label> */
    protected function labelsFor(User $user, Space $space, array $ids): Collection
    {
        return $this->referencesFor(Label::class, $user, $space, $ids, 'label_ids');
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Collection<int, TModel>
     */
    private function referencesFor(string $model, User $user, Space $space, array $ids, string $key): Collection
    {
        $normalised = collect($ids)->map(fn (mixed $id): string => (string) $id)->filter()->unique()->values();
        /** @var Collection<int, TModel> $references */
        $references = $model::query()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->whereIn('id', $normalised)
            ->get();

        if ($references->count() !== $normalised->count()) {
            throw ValidationException::withMessages([$key => 'Every reference must belong to the authenticated user and selected space.']);
        }

        return $references;
    }

    /** @return array{0: ?BudgetPeriod, 1: ?BudgetPeriod} */
    protected function periodsFor(Budget $budget): array
    {
        $asOf = today();
        $periods = $budget->periods()
            ->where(function ($query) use ($asOf): void {
                $query->where(function ($current) use ($asOf): void {
                    $current->whereDate('start_date', '<=', $asOf)->whereDate('end_date', '>=', $asOf);
                })->orWhereDate('start_date', '>', $asOf);
            })
            ->withSum('budgetTransactions as spent_amount', 'amount')
            ->orderBy('start_date')
            ->orderBy('id')
            ->get();

        return [
            $periods->first(fn (BudgetPeriod $period): bool => $period->start_date <= $asOf && $period->end_date >= $asOf),
            $periods->first(fn (BudgetPeriod $period): bool => $period->start_date > $asOf),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentBudget(Budget $budget, ?BudgetPeriod $current, ?BudgetPeriod $next): array
    {
        return [
            'id' => $budget->id,
            'name' => $budget->name,
            'period_type' => $budget->period_type->value,
            'period_start_day' => $budget->period_start_day,
            'rollover_type' => $budget->rollover_type->value,
            'is_catch_all' => (bool) $budget->is_catch_all,
            'categories' => $budget->categories
                ->sortBy(fn (Category $category): string => $category->name.'|'.$category->id)
                ->values()
                ->map(fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'labels' => $budget->labels
                ->sortBy(fn (Label $label): string => $label->name.'|'.$label->id)
                ->values()
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->all(),
            'current_period' => $current === null ? null : $this->presentCurrentPeriod($current),
            'next_period' => $next === null ? null : $this->presentNextPeriod($next),
            'updated_at' => $budget->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentCurrentPeriod(BudgetPeriod $period): array
    {
        $spent = (int) ($period->spent_amount ?? $period->spentAmount());
        $available = $period->availableAmount();

        return [
            'id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'allocated_amount' => (int) $period->allocated_amount,
            'carried_over_amount' => (int) ($period->carried_over_amount ?? 0),
            'available_amount' => $available,
            'spent_amount' => $spent,
            'remaining_amount' => $available - $spent,
            'status' => $period->limitStatus($spent),
            'processing_historical' => (bool) $period->processing_historical,
        ];
    }

    /** @return array<string, mixed> */
    protected function presentNextPeriod(BudgetPeriod $period): array
    {
        return [
            'id' => $period->id,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'allocated_amount' => (int) $period->allocated_amount,
        ];
    }
}
