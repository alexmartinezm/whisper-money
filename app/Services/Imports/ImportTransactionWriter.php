<?php

namespace App\Services\Imports;

use App\Enums\CategorySource;
use App\Enums\ImportChunkKind;
use App\Enums\LabelColor;
use App\Enums\LabelSource;
use App\Enums\TransactionSource;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\Account;
use App\Models\Import;
use App\Models\ImportChunk;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\TransactionDuplicateMatcher;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Writes the staged movements of a full import, chunk by chunk, leaving out
 * what the target account already held.
 *
 * Each row goes through Eloquent rather than a bulk insert on purpose: the
 * automation rules hang off `TransactionCreated`, and an imported movement
 * has to meet them the way a hand-made one does. A row whose category came
 * from the file is marked as categorized by hand, so a rule never overrides
 * what the user had in their previous app. Budgets are assigned once per
 * chunk instead of once per row (`AssignTransactionToBudget` skips imported
 * rows), and without the emails a history of crossed limits would set off.
 *
 * A chunk's rows, its removal from the staging table and the running counts
 * are committed together, so a run that stops at its deadline leaves exactly
 * the chunks still to do, and the next run carries on from there.
 *
 * A row Money Pro had split across several categories, without saying how
 * much went to each, comes in whole and uncategorized, with the label the
 * user finds them by to split them by hand.
 *
 * @phpstan-type TransactionRow array{account_key: string, date: string, amount: int, description: string, notes?: ?string, category_key?: ?string, external_id?: ?string, currency_code?: ?string, source_split?: bool}
 */
class ImportTransactionWriter
{
    /** @var array<string, array<string, true>> account id => external ids it held before the import */
    private array $externalIds = [];

    /** @var array<string, array<string, true>> account id => date|amount|description keys it held before the import */
    private array $duplicateKeys = [];

    /** @var array<string, array{account_id: string, name: string, created: bool, imported: int, duplicates: int}> */
    private array $perAccount = [];

    /** @var array<string, string> space id => the label the import puts on the rows split in Money Pro */
    private array $sourceSplitLabelIds = [];

    /** @var array{total: int, processed: int, imported: int, duplicates: int, skipped: int} */
    private array $totals = ['total' => 0, 'processed' => 0, 'imported' => 0, 'duplicates' => 0, 'skipped' => 0];

    public function __construct(private TransactionDuplicateMatcher $duplicates) {}

    /**
     * Write chunks until none is left or the deadline passes.
     *
     * @param  array<string, string>  $categoryIds  plan key => category id
     * @return bool whether every chunk has been written
     */
    public function write(Import $import, ImportAccountMap $accounts, array $categoryIds, CarbonInterface $deadline): bool
    {
        $this->resume($import);

        while (($chunk = $import->nextChunk(ImportChunkKind::Transactions)) !== null) {
            if (now()->greaterThanOrEqualTo($deadline)) {
                return false;
            }

            DB::transaction(fn () => $this->writeChunk($import, $accounts, $categoryIds, $chunk));
        }

        return true;
    }

    /**
     * Pick the counts up from the import row: a resumed run keeps adding to
     * what the earlier ones wrote. The duplicate snapshots are rebuilt from
     * the database, minus this import's own rows.
     */
    private function resume(Import $import): void
    {
        $stats = $import->stats['transactions'] ?? [];

        $this->totals = [
            'total' => (int) ($stats['total'] ?? $import->stagedRowCount(ImportChunkKind::Transactions)),
            'processed' => (int) ($stats['processed'] ?? 0),
            'imported' => (int) ($stats['imported'] ?? 0),
            'duplicates' => (int) ($stats['duplicates'] ?? 0),
            'skipped' => (int) ($stats['skipped'] ?? 0),
        ];
        $this->perAccount = collect($import->stats['per_account'] ?? [])->keyBy('account_id')->all();
        $this->externalIds = $this->duplicateKeys = $this->sourceSplitLabelIds = [];

        $import->recordStats(['transactions' => $this->totals]);
    }

    /**
     * @param  array<string, string>  $categoryIds
     */
    private function writeChunk(Import $import, ImportAccountMap $accounts, array $categoryIds, ImportChunk $chunk): void
    {
        $createdIds = [];

        foreach ($chunk->rows as $row) {
            $created = $this->writeRow($import, $accounts, $categoryIds, $row);

            if ($created !== null) {
                $createdIds[] = $created->id;
            }

            $this->totals['processed']++;
        }

        if ($createdIds !== []) {
            ReassignTransactionsToBudgets::dispatch($createdIds, notify: false);
        }

        $chunk->delete();

        $import->recordStats(['transactions' => $this->totals, 'per_account' => array_values($this->perAccount)]);
    }

    /**
     * @param  array<string, string>  $categoryIds
     * @param  TransactionRow  $row
     */
    private function writeRow(Import $import, ImportAccountMap $accounts, array $categoryIds, array $row): ?Transaction
    {
        $account = $accounts->targetFor($row['account_key']);

        if ($account === null) {
            $this->totals['skipped']++;

            return null;
        }

        if ($this->isDuplicate($import, $accounts, $account, $row)) {
            $this->tally($accounts, $account, 'duplicates');

            return null;
        }

        $transaction = Transaction::query()->create([
            'user_id' => $import->user_id,
            // Spelled out so each row does not look its account's space up again.
            'space_id' => $account->space_id,
            'account_id' => $account->id,
            'import_id' => $import->id,
            'transaction_date' => $row['date'],
            'amount' => $row['amount'],
            'currency_code' => $row['currency_code'] ?? $account->currency_code,
            'description' => $row['description'],
            'notes' => $row['notes'] ?? null,
            ...$this->classification($row, $categoryIds),
            'source' => TransactionSource::Imported,
            'external_transaction_id' => $row['external_id'] ?? null,
        ]);

        if ((bool) ($row['source_split'] ?? false)) {
            $transaction->labels()->attach($this->sourceSplitLabelId($import, $account));
        }

        $this->tally($accounts, $account, 'imported');

        return $transaction;
    }

    /**
     * The category a row comes in with. One from the file is what the user
     * chose in their previous app, so it is marked as categorized by hand and
     * no rule overrides it. A row split in Money Pro comes in uncategorized and
     * marked the same way: blank is the user's call there, for them to split
     * it by hand, so neither the rules nor the import's AI pass file it whole
     * ({@see Transaction::scopeOpenToImportAi()}).
     *
     * @param  TransactionRow  $row
     * @param  array<string, string>  $categoryIds
     * @return array{category_id: ?string, category_source: ?CategorySource}
     */
    private function classification(array $row, array $categoryIds): array
    {
        if ((bool) ($row['source_split'] ?? false)) {
            return ['category_id' => null, 'category_source' => CategorySource::Manual];
        }

        $categoryId = isset($row['category_key']) ? ($categoryIds[$row['category_key']] ?? null) : null;

        return ['category_id' => $categoryId, 'category_source' => $categoryId !== null ? CategorySource::Manual : null];
    }

    /**
     * The one label the import puts on the rows split in Money Pro, named in
     * the user's language: theirs when the space already has it (from an
     * earlier import, or made by hand), new otherwise. Undoing the import
     * takes the rows out and leaves the label.
     */
    private function sourceSplitLabelId(Import $import, Account $account): string
    {
        return $this->sourceSplitLabelIds[(string) $account->space_id] ??= Label::query()->firstOrCreate([
            'user_id' => $import->user_id,
            'space_id' => $account->space_id,
            'name' => __('Split in Money Pro', [], $import->user?->preferredLocale()),
        ], [
            'color' => LabelColor::Amber->value,
            'source' => LabelSource::User,
        ])->id;
    }

    /**
     * Only an account that held data before the import can already have a
     * row, and only what it held then counts: two identical coffees on one
     * day in the file are two coffees, and a column mapped as the transaction
     * id that repeats within the file loses nothing. A row matches on the
     * other app's id (which is what makes importing the same file twice a
     * no-op), or the way the per-account import matches, on the same day,
     * amount and description.
     *
     * @param  TransactionRow  $row
     */
    private function isDuplicate(Import $import, ImportAccountMap $accounts, Account $account, array $row): bool
    {
        if (! $accounts->isPreexisting($account)) {
            return false;
        }

        $externalId = $row['external_id'] ?? null;
        $this->externalIds[$account->id] ??= $this->duplicates->existingExternalIds($account, $import->id);

        if ($externalId !== null && isset($this->externalIds[$account->id][$externalId])) {
            return true;
        }

        $this->duplicateKeys[$account->id] ??= $this->duplicates->existingKeys($account, exceptImportId: $import->id);

        return isset($this->duplicateKeys[$account->id][$this->duplicates->key($row['date'], (int) $row['amount'], $row['description'])]);
    }

    /**
     * @param  'imported'|'duplicates'  $outcome
     */
    private function tally(ImportAccountMap $accounts, Account $account, string $outcome): void
    {
        $this->perAccount[$account->id] ??= [
            'account_id' => $account->id,
            'name' => $account->name,
            'created' => ! $accounts->isPreexisting($account),
            'imported' => 0,
            'duplicates' => 0,
        ];

        $this->perAccount[$account->id][$outcome]++;
        $this->totals[$outcome]++;
    }
}
