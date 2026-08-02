<?php

namespace App\Mcp\Tools;

use App\Features\RecurringTransactions;
use App\Models\User;
use App\Services\Budgets\ProjectBudgetPace;
use App\Services\Recurring\ProjectCashflow;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Laravel\Pennant\Feature;

#[IsReadOnly]
#[Description(<<<'TEXT'
Answers "will I make it to payday". Walks today's spendable cash forward through the
recurring charges due in the window, adds an estimate of the everyday spending that never
repeats on a cadence, and reports the budgets the current pace will overshoot. Amounts are
minor units. Read this instead of adding up `list_recurring_series` yourself, and prefer it
over `get_cashflow`, which looks backwards.

Exact versus estimated matters here. `starting_balance`, `expected_in`, `expected_out` and
`projected_balance` are arithmetic over known charges and reconcile against
`upcoming_charges`. `everyday_spending` is a median of recent months and is null when the
ledger is too short to say; when present, treat `everyday_spending.projected_balance` and
`everyday_spending.lowest` as the realistic figures and the exact ones as a ceiling.
Recurring charges alone almost never empty an account — the month's shopping does.

`starting_balance` counts current, savings and other accounts included in net worth, in
the user's own currency after conversion. Property, pensions, investments, loans and
credit cards are excluded on purpose: they cannot pay a direct debit.
TEXT)]
class GetRunway extends McpTool
{
    public function __construct(
        private readonly ProjectCashflow $cashflow,
        private readonly ProjectBudgetPace $budgetPace,
    ) {}

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()->min(1)->max(180)
                ->description('How far ahead to walk. Defaults to the configured window (30).'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        if (! Feature::for($user)->active(RecurringTransactions::class)) {
            return Response::error('Recurring detection is not enabled for this account, so there is no runway to report.');
        }

        $space = $this->resolveSpace($request, $user);
        $days = $request->has('days') ? $request->integer('days') : null;

        $forecast = $this->cashflow->forUser($user, $days, $space);
        $spending = $forecast['spending'];

        return $this->json([
            'space_id' => $space->id,
            'as_of' => today()->toDateString(),
            'currency_code' => $forecast['currency_code'],
            'days' => $forecast['days'],
            'starting_balance' => $forecast['starting_balance'],
            'expected_in' => $forecast['expected_in'],
            'expected_out' => $forecast['expected_out'],
            'projected_balance' => $forecast['ending_balance'],
            'lowest' => $forecast['lowest'],
            // Which accounts produced the opening figure, so "that is not my
            // balance" has an answer without another round trip.
            'accounts_counted' => $forecast['accounts'],
            'spendable_accounts_excluded_from_net_worth' => $forecast['excluded_accounts'],
            'everyday_spending' => $spending === null ? null : [
                'monthly' => $spending['monthly'],
                'months_observed' => $spending['months_observed'],
                'projected_balance' => $spending['ending_balance'],
                'lowest' => $spending['lowest'],
            ],
            'upcoming_charges' => $forecast['occurrences'],
            // Quarterly premiums and annual renewals never land inside the
            // window, so they are reported by month instead of against a
            // running balance.
            'irregular_charges_ahead' => $forecast['later'],
            'budgets_over_pace' => $this->budgetPace->forUser($user, null, $space),
            // Series in these currencies exist but are deliberately not
            // projected; amounts are never converted between currencies.
            'other_currencies' => $forecast['other_currencies'],
        ]);
    }
}
