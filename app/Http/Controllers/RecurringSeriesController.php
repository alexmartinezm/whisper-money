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
            'summary' => $this->buildSummary($active, $user->currency_code ?? 'USD'),
            'upcoming' => $this->buildUpcoming($active),
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
     * counting towards what the user believes they spend every month. Only
     * active series reach this method, so a cancelled subscription stops
     * inflating the current figures.
     *
     * One total per currency. Amounts in different currencies are never added
     * together and never converted here — a made-up exchange rate would turn a
     * factual number into an estimate without saying so.
     *
     * Expense and income are reported apart rather than netted, because a
     * salary would otherwise hide the subscriptions the screen exists to show.
     *
     * @param  Collection<int, RecurringSeries>  $series
     * @return list<array{currency_code: string, monthly_expense: int, monthly_income: int, yearly_expense: int, active_count: int}>
     */
    private function buildSummary(Collection $series, string $primaryCurrency): array
    {
        return $series
            ->reject(fn (RecurringSeries $row): bool => $row->isIgnored())
            ->groupBy('currency_code')
            ->map(function (Collection $group, string $currency): array {
                $monthlyExpense = (int) $group
                    ->where('direction', 'expense')
                    ->sum(fn (RecurringSeries $row): int => $row->monthlyEquivalentAmount());

                $monthlyIncome = (int) $group
                    ->where('direction', 'income')
                    ->sum(fn (RecurringSeries $row): int => $row->monthlyEquivalentAmount());

                return [
                    'currency_code' => $currency,
                    'monthly_expense' => $monthlyExpense,
                    'monthly_income' => $monthlyIncome,
                    'yearly_expense' => $monthlyExpense * 12,
                    'active_count' => $group->count(),
                ];
            })
            // The user's own currency leads; the rest follow by weight.
            ->sortBy(fn (array $row): array => [
                $row['currency_code'] === $primaryCurrency ? 0 : 1,
                -$row['active_count'],
                $row['currency_code'],
            ])
            ->values()
            ->all();
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
