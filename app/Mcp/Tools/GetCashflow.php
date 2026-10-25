<?php

namespace App\Mcp\Tools;

use App\Http\Controllers\Api\CashflowAnalyticsController;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
The full cashflow picture for a date range as JSON, mirroring the app's cashflow screen:
income/expense/savings/investment summary (current vs previous, both with the expense split
fixed/variable/general), the income-vs-expense category flow (sankey), and the monthly
trend. Amounts are in minor units (cents).

Unlike most tools here this one takes no `space`: it aggregates every space the user has,
and there is no way to narrow it to one. Do not set its figures against a space-filtered
total without saying so — the difference is the other spaces, not a discrepancy.
TEXT)]
class GetCashflow extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD. The summary\'s reserved and budgets count weekly, bi-weekly and monthly budgets for a range of up to 92 days, quarterly budgets too for a range of 32 to 92 days, and only yearly budgets for a longer one.')->required(),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $controller = app(CashflowAnalyticsController::class);
        $range = ['from' => $request->string('from')->toString(), 'to' => $request->string('to')->toString()];

        return $this->json([
            'currency' => $this->reportingCurrency($user),
            'summary' => $this->callController($controller, 'summary', $user, $range),
            'sankey' => $this->callController($controller, 'sankey', $user, $range),
            'trend' => $this->callController($controller, 'trend', $user, $range),
        ]);
    }
}
