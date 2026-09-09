<?php

namespace App\Mcp\Tools;

use App\Enums\CategorySource;
use App\Enums\TransactionSource;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ManualBalanceAdjuster;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
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
            'update_balance' => $schema->boolean()->description('When true and the amount/date/account changed, move the account balance snapshots accordingly. Ignored on connected accounts, whose balances come from the bank. Default false.'),
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

            $request->validate([
                'description' => ['sometimes', 'string'],
                'amount' => ['sometimes', 'integer'],
                'transaction_date' => ['sometimes', 'date'],
                'currency_code' => ['sometimes', 'string', 'size:3'],
                'notes' => ['sometimes', 'nullable', 'string'],
                'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            ]);

            // Snapshot the pre-edit account/date/amount so a manual balance can be
            // moved off the old values if the edit changes them.
            $originalSnapshot = clone $transaction;

            $this->applyFields($request, $transaction, [
                'description' => fn () => $request->string('description')->toString(),
                'amount' => fn () => $request->integer('amount'),
                'transaction_date' => fn () => Carbon::parse($request->string('transaction_date')->toString()),
                'currency_code' => fn () => mb_strtoupper($request->string('currency_code')->toString()),
                'account_id' => fn () => $this->accountInSpace($request, $space)->id,
                'notes' => fn () => $this->nullableString($request, 'notes'),
                'creditor_name' => fn () => $this->nullableString($request, 'creditor_name'),
                'debtor_name' => fn () => $this->nullableString($request, 'debtor_name'),
            ]);

            $this->retireLegacyIvs($request, $transaction);
            $this->applyCategory($request, $transaction, $space);

            $transaction->save();

            // Before the refresh below: it reloads the row and with it drops the
            // wasChanged() state the balance move is decided on.
            $balanceUpdated = $this->syncManualBalance($request, $transaction, $originalSnapshot);

            return $this->json([
                'transaction' => $this->presentTransaction($transaction->refresh()),
                'balance_updated' => $balanceUpdated,
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

    /**
     * Writing one of the legacy encrypted fields in the clear retires its iv:
     * one left behind would have the browser try to decrypt plain text and
     * render the field as broken. The web edit dialog clears them for the same
     * reason, and the client-side encryption they belong to is being migrated
     * away.
     */
    private function retireLegacyIvs(Request $request, Transaction $transaction): void
    {
        foreach (['description' => 'description_iv', 'notes' => 'notes_iv'] as $field => $iv) {
            if ($request->has($field)) {
                $transaction->{$iv} = null;
            }
        }
    }

    /**
     * A new category is always a manual assignment: reset any AI/rule provenance
     * so the row is not later treated as machine-categorized.
     *
     * ponytail: unlike the web edit path this does not learn a correction rule —
     * MCP writes stay predictable and side-effect free.
     */
    private function applyCategory(Request $request, Transaction $transaction, Space $space): void
    {
        if (! $request->has('category_id')) {
            return;
        }

        $newCategoryId = $request->filled('category_id') ? $this->categoryInSpace($request, $space)->id : null;

        if ($newCategoryId === $transaction->category_id) {
            return;
        }

        $transaction->category_id = $newCategoryId;
        $transaction->category_source = $newCategoryId === null ? null : CategorySource::Manual;
        $transaction->ai_confidence = null;
        $transaction->categorized_by_rule_id = null;
    }

    /**
     * Move the manual balance off the pre-edit account/date/amount and onto the
     * new ones. Either side no-ops on a connected account, so moving a
     * transaction onto one still unwinds the manual account it came from.
     */
    private function syncManualBalance(Request $request, Transaction $transaction, Transaction $originalSnapshot): bool
    {
        if (! $request->boolean('update_balance') || ! $transaction->wasChanged(ManualBalanceAdjuster::BALANCE_AFFECTING_ATTRIBUTES)) {
            return false;
        }

        $adjuster = app(ManualBalanceAdjuster::class);
        $reversed = $adjuster->reverseDeletedTransaction($originalSnapshot);
        $applied = $adjuster->applyCreatedTransaction($transaction->load('account'));

        return $reversed || $applied;
    }
}
