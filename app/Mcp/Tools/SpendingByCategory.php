<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\CategorySpendingService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description(<<<'TEXT'
Expense spending rolled up by category for a date range. Without parent_category_id, root
categories are returned; pass one to drill into its children. Amounts are in minor units
(cents).

Unlike most tools here this one takes no `space`: it aggregates every space the user has,
and there is no way to narrow it to one. Do not set its figures against a space-filtered
total without saying so — the difference is the other spaces, not a discrepancy.
TEXT)]
class SpendingByCategory extends McpTool
{
    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'from' => $schema->string()->description('Start date, YYYY-MM-DD.')->required(),
            'to' => $schema->string()->description('End date, YYYY-MM-DD.')->required(),
            'parent_category_id' => $schema->string()->description('Drill into a parent category\'s children.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        $spending = app(CategorySpendingService::class)->forPeriod(
            $user->id,
            Carbon::parse($request->string('from')->toString()),
            Carbon::parse($request->string('to')->toString()),
            $request->string('parent_category_id')->toString() ?: null,
        );

        return $this->json(['categories' => $spending->values()]);
    }
}
