<?php

namespace App\Http\Controllers;

use App\Enums\RecurringSeriesStatus;
use App\Features\RecurringTransactions;
use App\Http\Requests\UpdateRecurringSeriesRequest;
use App\Models\RecurringSeries;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class RecurringSeriesController extends Controller
{
    use AuthorizesRequests;

    /** How far ahead the "upcoming" list looks. */
    private const UPCOMING_DAYS = 30;

    public function index(Request $request): Response
    {
        $user = $request->user();

        abort_unless(Feature::for($user)->active(RecurringTransactions::class), 404);
        $this->authorize('viewAny', RecurringSeries::class);

        $series = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('space_id', $user->activeSpace()->id)
            ->with(['category', 'account'])
            ->orderBy('next_expected_on')
            ->orderBy('display_name')
            ->get();

        $active = $series->where('status', RecurringSeriesStatus::Active)->values();

        return Inertia::render('recurring/index', [
            'series' => $series->values(),
            'summary' => $this->buildSummary($active),
            'upcoming' => $this->buildUpcoming($active),
            'currencyCode' => $user->currency_code ?? 'USD',
        ]);
    }

    public function update(UpdateRecurringSeriesRequest $request, RecurringSeries $recurringSeries): RedirectResponse
    {
        $this->authorize('update', $recurringSeries);

        $recurringSeries->update($request->validated());

        return back();
    }

    public function destroy(Request $request, RecurringSeries $recurringSeries): RedirectResponse
    {
        $this->authorize('delete', $recurringSeries);

        $recurringSeries->delete();

        return back();
    }

    /**
     * Totals are computed on visible series only: a dismissed series should stop
     * counting towards what the user believes they spend every month.
     *
     * Expense and income are reported apart rather than netted, because a
     * salary would otherwise hide the subscriptions the screen exists to show.
     *
     * @param  Collection<int, RecurringSeries>  $series
     * @return array{monthly_expense: int, monthly_income: int, yearly_expense: int, active_count: int}
     */
    private function buildSummary(Collection $series): array
    {
        $visible = $series->reject(fn (RecurringSeries $row): bool => $row->isIgnored());

        $monthlyExpense = $visible
            ->where('direction', 'expense')
            ->sum(fn (RecurringSeries $row): int => $row->monthlyEquivalentAmount());

        $monthlyIncome = $visible
            ->where('direction', 'income')
            ->sum(fn (RecurringSeries $row): int => $row->monthlyEquivalentAmount());

        return [
            'monthly_expense' => (int) $monthlyExpense,
            'monthly_income' => (int) $monthlyIncome,
            'yearly_expense' => (int) $monthlyExpense * 12,
            'active_count' => $visible->count(),
        ];
    }

    /**
     * @param  Collection<int, RecurringSeries>  $series
     * @return list<array<string, mixed>>
     */
    private function buildUpcoming(Collection $series): array
    {
        $today = CarbonImmutable::today();
        $horizon = $today->addDays(self::UPCOMING_DAYS);

        return $series
            ->reject(fn (RecurringSeries $row): bool => $row->isIgnored())
            ->filter(fn (RecurringSeries $row): bool => $row->next_expected_on >= $today
                && $row->next_expected_on <= $horizon)
            ->sortBy('next_expected_on')
            ->map(fn (RecurringSeries $row): array => [
                'id' => $row->id,
                'display_name' => $row->display_name,
                'expected_amount' => $row->expected_amount,
                'amount_is_variable' => $row->amount_is_variable,
                'currency_code' => $row->currency_code,
                'next_expected_on' => $row->next_expected_on->toDateString(),
                'cadence' => $row->cadence->value,
                'category' => $row->category === null ? null : [
                    'id' => $row->category->id,
                    'name' => $row->category->name,
                    'color' => $row->category->color,
                    'icon' => $row->category->icon,
                ],
            ])
            ->values()
            ->all();
    }
}
