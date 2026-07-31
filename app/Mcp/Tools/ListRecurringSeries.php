<?php

namespace App\Mcp\Tools;

use App\Enums\RecurringSeriesStatus;
use App\Enums\RecurringSeriesUserState;
use App\Models\RecurringSeries;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List detected recurring charges (subscriptions, bills, standing payments) in an accessible space. Amounts are minor units. Detection runs on a schedule; this tool reads its result and never triggers a scan.')]
class ListRecurringSeries extends McpTool
{
    protected function respond(Request $request, User $user): Response
    {
        $space = $this->resolveSpace($request, $user);
        $status = $request->string('status')->toString();
        $includeIgnored = $request->boolean('include_ignored');

        $query = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('space_id', $space->id)
            ->with(['category', 'account']);

        if ($status !== '') {
            $query->where('status', $status);
        }

        if (! $includeIgnored) {
            $query->where('user_state', '!=', RecurringSeriesUserState::Ignored);
        }

        $series = $query
            ->orderBy('next_expected_on')
            ->orderBy('display_name')
            ->get();

        return $this->json([
            'space_id' => $space->id,
            'as_of' => today()->toDateString(),
            'monthly_expense_total' => (int) $series
                ->where('status', RecurringSeriesStatus::Active)
                ->where('direction', 'expense')
                ->sum(fn (RecurringSeries $row): int => $row->monthlyEquivalentAmount()),
            'series' => $series->map(fn (RecurringSeries $row): array => [
                'id' => $row->id,
                'name' => $row->display_name,
                'direction' => $row->direction,
                'cadence' => $row->cadence->value,
                'interval_days' => $row->interval_days,
                'expected_amount' => $row->expected_amount,
                'monthly_equivalent_amount' => $row->monthlyEquivalentAmount(),
                'amount_is_variable' => $row->amount_is_variable,
                'currency_code' => $row->currency_code,
                'category' => $row->category?->name,
                'account' => $row->account?->name,
                'first_occurred_on' => $row->first_occurred_on->toDateString(),
                'last_occurred_on' => $row->last_occurred_on->toDateString(),
                'next_expected_on' => $row->next_expected_on->toDateString(),
                'occurrence_count' => $row->occurrence_count,
                'status' => $row->status->value,
                'user_state' => $row->user_state->value,
            ])->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
            'status' => $schema->string()->description('Filter by status: active or lapsed. Defaults to both.'),
            'include_ignored' => $schema->boolean()->description('Include series the user dismissed. Defaults to false.'),
        ];
    }
}
