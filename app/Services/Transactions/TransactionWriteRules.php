<?php

namespace App\Services\Transactions;

use App\Enums\TransactionSource;
use App\Models\Transaction;
use App\Rules\StorableDate;

/**
 * The transaction write rules, shared by the create and update transaction
 * MCP tools and the `/api/v1/transactions` form requests, and what `/api/v1`
 * refuses on top of them. Looking up the account, category and labels a write
 * points at is left to each caller, which names the valid ids its own way.
 */
class TransactionWriteRules
{
    /**
     * What a bank or import reported, which the app locks to the source. The
     * date is left out on purpose, as in the app's edit dialog: which month a
     * transaction counts towards is the user's call, and the model keeps the
     * source's date in `source_date`.
     *
     * @var list<string>
     */
    private const array SourceOwnedFields = [
        'description',
        'amount',
        'currency_code',
        'account_id',
        'creditor_name',
        'debtor_name',
    ];

    /**
     * Well under what the TEXT columns hold, so oversized input is a validation
     * error instead of a failed insert.
     */
    private const int DescriptionMaxLength = 1000;

    private const int NotesMaxLength = 10000;

    /**
     * In minor units on either side of zero: ten trillion of a two-decimal
     * currency, past any real transaction, and far enough from the 64-bit
     * limit that negating an amount, adding it to a balance or weighing it by
     * an ownership percentage cannot overflow.
     */
    private const int MaxAmount = 1_000_000_000_000_000;

    /**
     * @return array<string, array<mixed>>
     */
    public static function createRules(): array
    {
        return [
            'account_id' => ['required', 'string'],
            'description' => ['required', 'string', 'max:'.self::DescriptionMaxLength],
            'amount' => ['required', ...self::amountRules()],
            'transaction_date' => ['required', 'date', new StorableDate],
            ...self::optionalFieldRules(),
        ];
    }

    /**
     * Every field is optional: only what is sent changes.
     *
     * @return array<string, array<mixed>>
     */
    public static function updateRules(): array
    {
        return [
            'description' => ['sometimes', 'string', 'max:'.self::DescriptionMaxLength],
            'amount' => ['sometimes', ...self::amountRules()],
            'transaction_date' => ['sometimes', 'date', new StorableDate],
            ...self::optionalFieldRules(),
        ];
    }

    /**
     * Why `/api/v1` refuses to edit the $sent fields, keyed by the field the
     * error goes under: what the source owns on a row that came from a bank or
     * import, and blanking a note the server cannot read. A split's ledger is
     * TransactionWriter's to refuse, under the row lock.
     *
     * @param  array<string, mixed>  $sent  the fields the edit carries
     * @return array<string, string>
     */
    public static function editRefusals(Transaction $transaction, array $sent): array
    {
        return [
            ...self::sourceOwnedRefusals($transaction, array_keys($sent)),
            ...array_filter(['notes' => self::blankedEncryptedNotesError($transaction, $sent)]),
        ];
    }

    /**
     * Why an edit of the $sent fields cannot touch a split transaction, or
     * null when it can. A split one is filed under its lines, so its category
     * and amount belong to them. The wording is the app's.
     *
     * @param  list<string>  $sent  the fields the edit carries
     */
    public static function splitLedgerError(Transaction $transaction, array $sent): ?string
    {
        if (array_intersect(['category_id', 'amount'], $sent) === [] || ! $transaction->splits()->exists()) {
            return null;
        }

        return 'Category or amount changes on a split transaction require an explicit split replacement or removal.';
    }

    /**
     * Why the transaction has nothing to merge back, or null when it does.
     */
    public static function mergeError(Transaction $transaction): ?string
    {
        return $transaction->splits()->exists() ? null : 'That transaction is not split, so there is nothing to merge.';
    }

    /**
     * Whether the edit may move the account's balance snapshots: a row that
     * came from a bank or import never does, even moved to another date, as
     * in the app's edit dialog.
     */
    public static function movesBalance(Transaction $transaction): bool
    {
        return $transaction->source === TransactionSource::ManuallyCreated;
    }

    /**
     * @param  list<string>  $sent
     * @return array<string, string>
     */
    private static function sourceOwnedRefusals(Transaction $transaction, array $sent): array
    {
        if ($transaction->source === TransactionSource::ManuallyCreated) {
            return [];
        }

        $locked = array_values(array_intersect(self::SourceOwnedFields, $sent));
        $message = 'Only manually-created transactions can change '.implode(', ', $locked).'. This one came from a bank or import, so those fields are locked; transaction_date, notes and category_id can still be edited here.';

        return $locked === [] ? [] : array_fill_keys($locked, $message);
    }

    /**
     * A note encrypted on the device reads as '', so a client writing back
     * what it read would send it blank. New text replaces the note on purpose;
     * a blank value would only wipe something nobody can read here.
     *
     * @param  array<string, mixed>  $sent
     */
    private static function blankedEncryptedNotesError(Transaction $transaction, array $sent): ?string
    {
        if ($transaction->notes_iv === null || ! array_key_exists('notes', $sent) || filled($sent['notes'])) {
            return null;
        }

        return 'These notes are encrypted on your device and read as empty here, so a blank value would erase them. Send new text to replace them, or leave notes out.';
    }

    /**
     * @return list<string>
     */
    private static function amountRules(): array
    {
        // Bail: `between` cannot read what `integer` refuses, and 1e999 decodes
        // to INF.
        return ['bail', 'integer', 'between:-'.self::MaxAmount.','.self::MaxAmount];
    }

    /**
     * @return array<string, array<mixed>>
     */
    private static function optionalFieldRules(): array
    {
        return [
            // Any code, not just the ones an account can be in: a trip paid in a
            // currency the app holds no account for is a real transaction. With
            // no rate for it, totals count the amount unconverted and a balance
            // stays where it is, with `balance_updated` saying so. Three ASCII
            // letters, as Intl.NumberFormat takes no other shape in the browser;
            // \z, since $ would let a trailing newline through.
            'currency_code' => ['sometimes', 'string', 'regex:/\A[A-Za-z]{3}\z/'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:'.self::NotesMaxLength],
            'creditor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'debtor_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
