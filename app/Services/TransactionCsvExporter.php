<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\TransactionSplit;
use Generator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turns transactions into the rows of a CSV export, and says how many rows
 * an export writes.
 *
 * The header stays in English whatever the user's language: the file is meant
 * to be read by spreadsheets and other apps, and a stable header is what makes
 * it importable again.
 *
 * A split transaction is written as one row per split line, the way a file
 * that holds each part as a transaction of its own reads: amount and category
 * from the line, everything else from the transaction. Under a category or
 * label filter only the lines that filter would match are written, in SQL
 * through {@see self::constrainLines()}; under a cashflow block filter only
 * the postings in the block, the way the analysis drawer narrows them. The
 * rows and their count both go through the same narrowing.
 */
class TransactionCsvExporter
{
    /** Relations a row reads, eager loaded by {@see self::withRowRelations()}. */
    private const RELATIONS = ['account.bank', 'category', 'labels'];

    /** @var list<string> */
    public const HEADER = [
        'Date',
        'Description',
        'Amount',
        'Currency',
        'Account',
        'Bank',
        'Category',
        'Labels',
        'Notes',
        'Creditor',
        'Debtor',
    ];

    public function __construct(
        private readonly CategoryTree $tree,
        private readonly CashflowBlockQuery $blocks,
    ) {}

    /**
     * Eager loads what the rows read: the split lines the filters keep, and
     * whether the transaction is split at all, so a split one whose lines are
     * all filtered out writes no row rather than one for its whole amount.
     * Apply it after the sort, whose payee columns replace the select list.
     *
     * @param  Builder<Transaction>  $transactions
     * @param  array<string, mixed>  $filters
     * @return Builder<Transaction>
     */
    public function withRowRelations(Builder $transactions, array $filters): Builder
    {
        return $transactions
            ->with([
                ...self::RELATIONS,
                'splits' => fn (HasMany $lines) => $this->constrainLines($lines->with('category'), $filters),
            ])
            ->withExists('splits');
    }

    /**
     * How many rows the export of these transactions writes: one per
     * unsplit transaction and one per kept line of a split one.
     *
     * A cashflow block filter narrows postings in PHP, so under it the
     * transactions are read and expanded as the export does; the filter
     * requires a date range, which bounds how many that reads. Every other
     * filter is counted in SQL.
     *
     * @param  Builder<Transaction>  $transactions  filtered, not sorted
     * @param  array<string, mixed>  $filters
     */
    public function countRows(Builder $transactions, array $filters): int
    {
        if (! empty($filters['cashflow_block'])) {
            return iterator_count($this->postings(
                $this->withRowRelations(clone $transactions, $filters)->lazy(500),
                $filters,
            ));
        }

        $unsplit = (clone $transactions)->whereDoesntHave('splits')->count();

        $lines = TransactionSplit::query()
            ->whereIn('transaction_id', (clone $transactions)->select('transactions.id'));
        $this->constrainLines($lines, $filters);

        return $unsplit + $lines->count();
    }

    /**
     * The rows these transactions write, in their order, each loaded through
     * {@see self::withRowRelations()} with the same filters.
     *
     * @param  iterable<Transaction>  $transactions
     * @param  array<string, mixed>  $filters
     * @return Generator<int, list<string>>
     */
    public function rows(iterable $transactions, array $filters): Generator
    {
        foreach ($this->postings($transactions, $filters) as $posting) {
            yield $this->row($posting);
        }
    }

    /**
     * Each transaction as the postings it writes: itself when unsplit, else
     * one per kept split line, built the way cashflow expands a split
     * transaction ({@see Transaction::splitPosting()}) but at the line's own
     * amount. Under a cashflow block filter only the postings in the block.
     *
     * @param  iterable<Transaction>  $transactions
     * @param  array<string, mixed>  $filters
     * @return Generator<int, Transaction>
     */
    private function postings(iterable $transactions, array $filters): Generator
    {
        $inBlock = $this->blocks->postingFilter($filters);

        foreach ($transactions as $transaction) {
            $postings = $transaction->splits_exists
                ? $transaction->splits->map(fn (TransactionSplit $line): Transaction => $transaction->splitPosting($line, $line->amount, $transaction->currency_code))
                : [$transaction];

            foreach ($postings as $posting) {
                if ($inBlock === null || $inBlock($posting)) {
                    yield $posting;
                }
            }
        }
    }

    /**
     * Keeps the split lines a category or label filter would match if each
     * line were a transaction of its own: a line in a wanted category (its
     * subcategories included), or every line when the transaction carries a
     * wanted label, since labels live on the transaction. The "uncategorized"
     * pseudo id never matches a line. With neither filter every line is kept.
     *
     * @param  Builder<TransactionSplit>|HasMany<TransactionSplit, Transaction>  $lines
     * @param  array<string, mixed>  $filters
     */
    private function constrainLines(Builder|HasMany $lines, array $filters): void
    {
        $categoryIds = $filters['category_ids'] ?? [];
        $labelIds = $filters['label_ids'] ?? [];

        if (empty($categoryIds) && empty($labelIds)) {
            return;
        }

        $wantedCategoryIds = $this->tree->expand(
            $filters['user_id'],
            array_values(array_filter($categoryIds, fn (string $id): bool => $id !== 'uncategorized')),
        );

        $lines->where(function (Builder $line) use ($wantedCategoryIds, $labelIds): void {
            // An empty list compiles to a condition no line meets.
            $line->whereIn('transaction_splits.category_id', $wantedCategoryIds);

            if (! empty($labelIds)) {
                $line->orWhereHas('transaction.labels', fn (Builder $labels) => $labels->whereIn('labels.id', $labelIds));
            }
        });
    }

    /**
     * One posting's row: an unsplit transaction, or a split line read as one.
     * Fields still encrypted on the device (legacy client-side encryption)
     * are written empty: the server cannot read them, and ciphertext in a
     * spreadsheet is noise.
     *
     * @return list<string>
     */
    private function row(Transaction $posting): array
    {
        $account = $posting->account;

        return [
            $posting->transaction_date->format('Y-m-d'),
            $this->text($posting->description_iv === null ? $posting->description : null),
            // A plain decimal with no symbol and no grouping, so every
            // spreadsheet locale reads it as a number. Every amount here is
            // stored in cents, whatever the currency, so two decimals it is.
            number_format($posting->amount / 100, 2, '.', ''),
            $posting->currency_code,
            $this->text($account?->name_iv === null ? $account?->name : null),
            $this->text($account?->bank?->name),
            $this->text($posting->category?->name),
            $this->text($posting->labels->pluck('name')->join(', ')),
            $this->text($posting->notes_iv === null ? $posting->notes : null),
            $this->text($posting->creditor_name),
            $this->text($posting->debtor_name),
        ];
    }

    /**
     * A spreadsheet runs a cell that starts with one of these as a formula, and
     * descriptions come straight from the bank. A leading quote keeps it text.
     */
    private function text(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
