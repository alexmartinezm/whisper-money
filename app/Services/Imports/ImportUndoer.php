<?php

namespace App\Services\Imports;

use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\TransactionSplit;
use App\Services\CategoryTree;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Takes a full import back out of the user's data: the accounts it created,
 * with everything on them (rows added since by hand or by another import
 * included, which the undo dialog says), the movements and balances it put
 * into the user's own accounts, and the categories and banks it created. The
 * user's own accounts stay, and so does an account the import created that
 * has since been connected to a bank: only what the import wrote leaves it.
 *
 * Runs from UndoImportJob, in short steps rather than one transaction, and
 * every step only removes what is still there, so a retry finishes the job.
 *
 * What a "start from scratch" import wiped before it ran is gone for good;
 * undo cannot bring it back.
 */
class ImportUndoer
{
    public function __construct(
        private CategoryTree $tree,
        private AccountPurger $purger,
    ) {}

    public function undo(Import $import): void
    {
        $transactions = $this->purger->deleteInChunks(Transaction::withTrashed()->where('import_id', $import->id));
        $balances = $this->purger->deleteInChunks(AccountBalance::query()->where('import_id', $import->id));

        $created = Account::withTrashed()->where('import_id', $import->id)->get(['id', 'banking_connection_id']);
        [$connected, $manual] = $created->partition(fn (Account $account): bool => $account->isConnected());

        $transactions += $this->purger->purge($manual->pluck('id'));

        // Kept, and no longer counted as the import's.
        Account::withTrashed()->whereIn('id', $connected->pluck('id'))->update(['import_id' => null]);

        ['created' => $categories, 'kept' => $categoriesKept] = $this->deleteCreatedCategories($import);
        $banks = $this->deleteCreatedBanks($import);

        $import->forceFill([
            'status' => ImportStatus::tryFrom((string) ($import->stats['undo']['previous_status'] ?? '')) ?? ImportStatus::Completed,
            'undone_at' => now(),
        ])->save();

        $requestedAt = $import->stats['undo']['requested_at'] ?? null;

        Log::info('Full import undone', $import->logContext([
            'transactions' => $transactions,
            'balances' => $balances,
            'accounts_deleted' => $manual->count(),
            'accounts_kept_connected' => $connected->count(),
            'categories' => $categories,
            'categories_kept_for_splits' => $categoriesKept,
            'banks_deleted' => $banks,
            'duration_seconds' => $requestedAt !== null ? Carbon::parse($requestedAt)->diffInSeconds(now()) : null,
        ]));
    }

    /**
     * The categories go the way Settings deletes a category with its
     * subcategories: whatever still points at them is left uncategorized
     * rather than deleted with them, since by now it can be a movement the
     * user filed there by hand. Uncategorized for real, too: a row filed by
     * hand into a category that is gone is no longer the user's choice, so
     * the rules and the AI may file it again.
     *
     * A split line cannot be left uncategorized, so a category a split line
     * still files money into stays, with every category above it, and is no
     * longer counted as the import's. The rest goes.
     *
     * @return array{created: int, kept: int} how many categories the import
     *                                        created, and how many of them stay
     */
    private function deleteCreatedCategories(Import $import): array
    {
        $created = Category::query()->where('import_id', $import->id)->get();
        $kept = $this->categoriesHoldingSplitLines($import, $created);
        $deletable = $created->reject(fn (Category $category): bool => isset($kept[$category->id]));
        $deletableIds = $deletable->pluck('id')->flip();
        $roots = $deletable->reject(fn (Category $category): bool => $category->parent_id !== null && $deletableIds->has($category->parent_id));

        foreach ($roots as $root) {
            Transaction::query()
                ->where('user_id', $root->user_id)
                ->whereIn('category_id', [$root->id, ...$this->tree->descendantIds($root)])
                ->update(['category_source' => null]);

            $this->tree->deleteSubtree($root);
        }

        // Kept, and no longer counted as the import's.
        Category::query()->whereKey(array_keys($kept))->update(['import_id' => null]);

        return ['created' => $created->count(), 'kept' => count($kept)];
    }

    /**
     * How many of the categories the import created an undo now would keep
     * because split lines use them, for the undo dialog to say so instead of
     * promising to remove them.
     *
     * Listing several imports of one user, pass that user's
     * {@see CategoryTree::parentMap()} so the tree is read once for all of them.
     *
     * @param  array<string, ?string>|null  $parentMap
     */
    public function categoriesKeptForSplits(Import $import, ?array $parentMap = null): int
    {
        return count($this->categoriesHoldingSplitLines(
            $import,
            Category::query()->where('import_id', $import->id)->get(),
            $parentMap,
        ));
    }

    /**
     * The categories of the import that must stay because a split line files
     * money into them or into a category below them (the line's category and
     * every ancestor of it, as far as the import created them). Lines on
     * rows the undo deletes do not count: the import's own rows and those on
     * the accounts it created and deletes. During the undo those are already
     * gone; before it, the dialog must not count them either.
     *
     * The user's tree is read once, into a parent map, whatever the number
     * of categories the import created.
     *
     * @param  EloquentCollection<int, Category>  $created
     * @param  array<string, ?string>|null  $parentMap
     * @return array<string, true>
     */
    private function categoriesHoldingSplitLines(Import $import, EloquentCollection $created, ?array $parentMap = null): array
    {
        if ($created->isEmpty()) {
            return [];
        }

        $parentMap ??= $this->tree->parentMap($import->user_id);
        $createdIds = $created->pluck('id')->flip();
        $rootIds = $created
            ->reject(fn (Category $category): bool => $category->parent_id !== null && $createdIds->has($category->parent_id))
            ->pluck('id')
            ->all();
        $subtreeIds = $this->tree->expand($import->user_id, $rootIds, parentMap: $parentMap);

        $referenced = TransactionSplit::query()
            ->whereIn('category_id', $subtreeIds)
            ->whereNotIn('transaction_id', $this->rowsUndoDeletes($import))
            ->distinct()
            ->pluck('category_id');

        if ($referenced->isEmpty()) {
            return [];
        }

        $kept = [];

        foreach ($referenced as $categoryId) {
            foreach ($this->tree->ancestorAndSelfIds($import->user_id, $categoryId, $parentMap) as $id) {
                if ($createdIds->has($id)) {
                    $kept[$id] = true;
                }
            }
        }

        return $kept;
    }

    /**
     * The rows an undo deletes: the import's own, and every row on an account
     * it created that is not connected to a bank since.
     *
     * @return Builder<Transaction>
     */
    private function rowsUndoDeletes(Import $import): Builder
    {
        return Transaction::withTrashed()
            ->select('id')
            ->where(fn (Builder $rows) => $rows
                ->where('import_id', $import->id)
                ->orWhereIn('account_id', Account::withTrashed()
                    ->select('id')
                    ->where('import_id', $import->id)
                    ->whereNull('banking_connection_id')));
    }

    /**
     * A bank the import created goes when nothing points at it any more. One
     * the user has since put another account in stays: deleting a bank takes
     * its accounts with it (the foreign key cascades), trashed ones included.
     */
    private function deleteCreatedBanks(Import $import): int
    {
        // One statement, so no account can be put in the bank between the
        // check and the delete and then go with it (accounts.bank_id
        // cascades). Trashed accounts count: the subquery reads the table.
        $deleted = Bank::withTrashed()
            ->where('import_id', $import->id)
            ->whereNotExists(fn (QueryBuilder $accounts) => $accounts
                ->selectRaw('1')
                ->from('accounts')
                ->whereColumn('accounts.bank_id', 'banks.id'))
            ->forceDelete();

        // What is left is used by an account: it stays, no longer the import's.
        Bank::withTrashed()->where('import_id', $import->id)->update(['import_id' => null]);

        return (int) $deleted;
    }
}
