<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsBalances;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List account balance snapshots in a space, newest first. Amounts are minor units (cents). Covers connected accounts as well as manual ones — reading a bank balance is fine, only writing it is not.')]
class ListBalances extends McpTool
{
    use PresentsBalances;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Only snapshots of this account. Defaults to every account in the space.'),
            'from' => $schema->string()->description('Earliest snapshot date, YYYY-MM-DD.'),
            'to' => $schema->string()->description('Latest snapshot date, YYYY-MM-DD.'),
            'limit' => $schema->integer()->min(1)->description('Maximum number of snapshots to return. Defaults to 100.'),
            'space' => $schema->string()->description('Space id to query. Defaults to the personal space.'),
        ];
    }

    protected function respond(Request $request, User $user): Response
    {
        $request->validate([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date'],
            'limit' => ['sometimes', 'integer', 'min:1'],
        ]);

        $space = $this->resolveSpace($request, $user);

        // A snapshot has no space of its own; it inherits the one of the account
        // it hangs off, so the space filter has to go through the accounts.
        $accountIds = Account::query()->forSpace($space)->select('id');

        $balances = AccountBalance::query()
            ->whereIn('account_id', $accountIds)
            ->when($request->filled('account_id'), fn ($query) => $query->where(
                'account_id',
                $this->accountInSpace($request, $space)->id,
            ))
            ->when($request->filled('from'), fn ($query) => $query->whereDate('balance_date', '>=', $request->string('from')->toString()))
            ->when($request->filled('to'), fn ($query) => $query->whereDate('balance_date', '<=', $request->string('to')->toString()))
            ->orderByDesc('balance_date')
            ->orderBy('account_id')
            ->limit($request->filled('limit') ? $request->integer('limit') : 100)
            ->get();

        return $this->json([
            'space_id' => $space->id,
            'balances' => $balances
                ->map(fn (AccountBalance $balance): array => $this->presentBalance($balance))
                ->all(),
        ]);
    }
}
