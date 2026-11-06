<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsBalances;
use App\Models\User;
use App\Services\Accounts\AccountBalanceRecorder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Record an account balance snapshot on a non-connected (manual) account. Balance is an integer in minor units (cents). Replaces any existing snapshot for that date; use delete_balance to remove one that should never have existed. Connected/bank accounts are read-only.')]
class CreateBalance extends WriteTool
{
    use PresentsBalances;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->description('Id of a non-connected (manual) account that is not a credit card.')->required(),
            'balance' => $schema->integer()->description('Balance in minor units (cents).')->required(),
            'balance_date' => $schema->string()->description('Snapshot date, YYYY-MM-DD. Defaults to today.'),
            'invested_amount' => $schema->integer()->description('Optional invested amount in minor units (cents), for investment accounts.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $recorder = app(AccountBalanceRecorder::class);
        $request->validate($recorder->rules());

        $space = $this->resolveSpace($request, $user);
        $account = $this->balanceWritableAccount($request, $space);

        $balance = $recorder->record(
            $account,
            $request->integer('balance'),
            $this->nullableString($request, 'balance_date'),
            $request->filled('invested_amount') ? $request->integer('invested_amount') : null,
        );

        return $this->json(['balance' => $this->presentBalance($balance)]);
    }
}
