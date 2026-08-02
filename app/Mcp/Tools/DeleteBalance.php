<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsBalances;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Space;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete an account balance snapshot from a non-connected (manual) account. Identify it by balance_id, or by account_id together with balance_date. Use this for a snapshot that should never have existed; to correct the amount of one that should, call create_balance again for the same date. Connected/bank accounts are read-only.')]
class DeleteBalance extends WriteTool
{
    use PresentsBalances;

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'balance_id' => $schema->string()->description('Id of the snapshot to delete. Use this or account_id + balance_date.'),
            'account_id' => $schema->string()->description('Id of a non-connected (manual) account. Requires balance_date.'),
            'balance_date' => $schema->string()->description('Snapshot date, YYYY-MM-DD. Requires account_id.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        $request->validate([
            'balance_date' => ['sometimes', 'date'],
        ]);

        $space = $this->resolveSpace($request, $user);

        $balance = $request->filled('balance_id')
            ? $this->balanceById($request, $space)
            : $this->balanceByAccountAndDate($request, $space);

        $presented = $this->presentBalance($balance);

        $balance->delete();

        return $this->json(['deleted' => true, ...$presented]);
    }

    private function balanceById(Request $request, Space $space): AccountBalance
    {
        $id = $request->string('balance_id')->toString();

        $balance = AccountBalance::query()
            ->whereKey($id)
            ->whereIn('account_id', Account::query()->forSpace($space)->select('id'))
            ->first();

        if ($balance === null) {
            throw ValidationException::withMessages([
                'balance_id' => "No balance snapshot with id {$id} in space {$space->id}. Call list_balances to see valid ids.",
            ]);
        }

        // Resolved from the snapshot rather than from an argument, so the
        // bank-account protection has to be applied to the account it points at.
        $this->assertWritableAccount($balance->account()->sole(), 'balance_id');

        return $balance;
    }

    private function balanceByAccountAndDate(Request $request, Space $space): AccountBalance
    {
        if (! $request->filled('account_id') || ! $request->filled('balance_date')) {
            throw ValidationException::withMessages([
                'balance_id' => 'Identify the snapshot with balance_id, or with account_id and balance_date together.',
            ]);
        }

        $account = $this->writableAccount($request, $space);
        $date = $request->string('balance_date')->toString();

        $balance = AccountBalance::query()
            ->where('account_id', $account->id)
            ->whereDate('balance_date', $date)
            ->first();

        if ($balance === null) {
            throw ValidationException::withMessages([
                'balance_date' => "No balance snapshot on {$date} for account {$account->id}. Call list_balances to see what exists.",
            ]);
        }

        return $balance;
    }
}
