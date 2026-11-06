<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\BudgetExpenseType;
use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\User;
use App\Services\Budgets\BudgetPresenter;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\JsonSchema\Types\StringType;
use Illuminate\Validation\ValidationException;

trait InteractsWithBudgets
{
    /**
     * The `expense_type` argument of create_budget and update_budget, with
     * $note appended to its description.
     */
    protected function expenseTypeSchema(JsonSchema $schema, string $note = ''): StringType
    {
        return $schema->string()
            ->enum(array_column(BudgetExpenseType::cases(), 'value'))
            ->description(trim('Fixed spending repeats at the same amount every period (rent, subscriptions); variable spending can be adjusted (groceries, restaurants). Only the cashflow expense split reads it. A catch-all budget is always variable, whatever is sent. '.$note));
    }

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
            'expense_type' => $budget->expense_type->value,
            'is_catch_all' => (bool) $budget->is_catch_all,
            ...app(BudgetPresenter::class)->tracking($budget),
            'current_period' => $current === null ? null : $this->presentCurrentPeriod($current),
            'next_period' => $next === null ? null : $this->presentNextPeriod($next),
            'updated_at' => $budget->updated_at?->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentCurrentPeriod(BudgetPeriod $period): array
    {
        return app(BudgetPresenter::class)->period($period);
    }

    /** @return array<string, mixed> */
    protected function presentNextPeriod(BudgetPeriod $period): array
    {
        return app(BudgetPresenter::class)->allocation($period);
    }
}
