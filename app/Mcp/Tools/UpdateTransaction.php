<?php

namespace App\Mcp\Tools;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Transactions\TransactionWriter;
use App\Services\Transactions\TransactionWriteRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Edit a transaction; only the fields you pass change. Notes and category work on any transaction, bank/imported ones included; the other fields only on manually-created ones.')]
class UpdateTransaction extends WriteTool
{
    /**
     * The fields that describe the transaction itself. A bank/imported row owns
     * them through its sync, so they are locked there — while notes and
     * category_id stay editable on any transaction.
     *
     * @var list<string>
     */
    private const CORE_FIELDS = [
        'description',
        'amount',
        'transaction_date',
        'currency_code',
        'account_id',
        'creditor_name',
        'debtor_name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'transaction_id' => $schema->string()->description('Id of the transaction to edit.')->required(),
            'description' => $schema->string()->description('New description.'),
            'amount' => $schema->integer()->description('New signed amount, in the minor units of the transaction\'s own currency.'),
            'transaction_date' => $schema->string()->description('New transaction date, YYYY-MM-DD.'),
            'currency_code' => $schema->string()->description('New ISO 4217 currency code (3 letters).'),
            'account_id' => $schema->string()->description('Move the transaction to another account.'),
            'category_id' => $schema->string()->nullable()->description('New category id, or null to clear the category.'),
            'creditor_name' => $schema->string()->nullable()->description('New creditor (payee) name, or null to clear it.'),
            'debtor_name' => $schema->string()->nullable()->description('New debtor (payer) name, or null to clear it.'),
            'notes' => $schema->string()->nullable()->description('New free-text notes, or null to clear them. Editable on any transaction, bank/imported ones included.'),
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the account balance snapshots accordingly. Ignored on connected accounts, whose balances come from the bank, and on credit cards that track a limit instead of a balance. Default false.'),
            'space' => $schema->string()->description('Space id. Defaults to the personal space.'),
        ];
    }

    protected function write(Request $request, User $user): Response
    {
        return DB::transaction(function () use ($request, $user): Response {
            $space = $this->resolveSpace($request, $user);
            $transaction = $this->transactionInSpace($request, $space);
            $transaction = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $refusal = $this->refuseUneditable($request, $transaction);

            if ($refusal !== null) {
                return $refusal;
            }

            $request->validate(TransactionWriteRules::updateRules());

            // ponytail: unlike the web edit path this does not learn a correction
            // rule: MCP writes stay predictable and side-effect free.
            $written = app(TransactionWriter::class)->update($transaction, $this->requestedFields($request, [
                'description' => fn () => $request->string('description')->toString(),
                'amount' => fn () => $request->integer('amount'),
                'transaction_date' => fn () => $request->string('transaction_date')->toString(),
                'currency_code' => fn () => $request->string('currency_code')->toString(),
                'account_id' => fn () => $this->accountInSpace($request, $space)->id,
                'notes' => fn () => $this->nullableString($request, 'notes'),
                'creditor_name' => fn () => $this->nullableString($request, 'creditor_name'),
                'debtor_name' => fn () => $this->nullableString($request, 'debtor_name'),
                'category_id' => fn () => $request->filled('category_id') ? $this->categoryInSpace($request, $space)->id : null,
            ]), $request->boolean('update_balance'));

            return $this->json([
                'transaction' => $this->presentTransaction($written->transaction),
                'balance_updated' => $written->balanceUpdated,
            ]);
        });
    }

    /**
     * Why the request cannot go through, as the error the client sees, or null
     * when it can. Only a request that actually carries a locked field is
     * refused: an edit limited to notes is allowed on every transaction, which
     * is how an agent annotates bank and imported rows.
     */
    private function refuseUneditable(Request $request, Transaction $transaction): ?Response
    {
        if ($transaction->splits()->exists() && $request->hasAny(['category_id', 'amount'])) {
            return Response::error('This transaction is split. Direct category and amount changes are blocked; use split_transaction for category postings. It does not change the parent ledger amount.');
        }

        $locked = array_values(array_filter(
            self::CORE_FIELDS,
            fn (string $field): bool => $request->has($field),
        ));

        if ($locked === [] || $transaction->source === TransactionSource::ManuallyCreated) {
            return null;
        }

        $fields = implode(', ', $locked);

        return Response::error("Only manually-created transactions can change {$fields}. This one came from a bank or import, so its core fields are locked; notes and category_id can still be edited here, and label_transaction handles its labels.");
    }
}
