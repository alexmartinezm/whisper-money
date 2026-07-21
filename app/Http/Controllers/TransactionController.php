<?php

namespace App\Http\Controllers;

use App\Enums\CategorySource;
use App\Features\TransactionSplitting;
use App\Http\Requests\BulkUpdateTransactionsRequest;
use App\Http\Requests\IndexTransactionRequest;
use App\Http\Requests\StoreTransactionRequest;
use App\Http\Requests\UpdateTransactionRequest;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Bank;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Services\Ai\CategoryOverrideHandler;
use App\Services\ManualBalanceAdjuster;
use App\Services\Transactions\ReplaceTransactionSplits;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Pennant\Feature;

class TransactionController extends Controller
{
    use AuthorizesRequests;

    public function index(IndexTransactionRequest $request): Response
    {
        $user = $request->user();
        $validated = $request->validated();

        $lastVisitAt = $user->transactions_last_visited_at;

        $perPage = (int) ($validated['per_page'] ?? 50);
        $sortParam = $validated['sort'] ?? '-transaction_date';

        $descending = str_starts_with($sortParam, '-');
        $sortColumn = ltrim($sortParam, '-');
        $sortDirection = $descending ? 'desc' : 'asc';

        $filters = array_filter([
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? null,
            'account_ids' => $validated['account_ids'] ?? null,
            'label_ids' => $validated['label_ids'] ?? null,
            'creditor_name' => $validated['creditor_name'] ?? null,
            'debtor_name' => $validated['debtor_name'] ?? null,
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? null,
        ], fn ($value) => $value !== null);

        $query = Transaction::query()
            ->where('user_id', $user->id)
            ->with(['account.bank', 'category', 'labels', 'categorizedByRule:id,origin', 'splits.category'])
            ->applyFilters($filters);

        $nullableSortColumns = ['creditor_name', 'debtor_name'];

        if (in_array($sortColumn, $nullableSortColumns, true)) {
            $sortAlias = $sortColumn.'_sort';
            $query->select('transactions.*')
                ->selectRaw("COALESCE({$sortColumn}, '') as {$sortAlias}")
                ->orderBy($sortAlias, $sortDirection);
        } else {
            $query->orderBy($sortColumn, $sortDirection);
        }

        $transactions = $query
            ->orderBy('id', 'desc')
            ->cursorPaginate($perPage)
            ->withQueryString();

        $transactions->getCollection()->each(function (Transaction $transaction): void {
            $transaction->makeHidden(['creditor_name_sort', 'debtor_name_sort'])
                ->append(['ai_categorized', 'is_split', 'split_count']);
        });

        $newestServed = $transactions->getCollection()->max('created_at');
        if ($newestServed && (! $lastVisitAt || $newestServed->gt($lastVisitAt))) {
            $user->forceFill(['transactions_last_visited_at' => $newestServed])->save();
        }

        $appliedFilters = [
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'amount_min' => $validated['amount_min'] ?? null,
            'amount_max' => $validated['amount_max'] ?? null,
            'category_ids' => $validated['category_ids'] ?? [],
            'account_ids' => $validated['account_ids'] ?? [],
            'label_ids' => $validated['label_ids'] ?? [],
            'creditor_name' => $validated['creditor_name'] ?? '',
            'debtor_name' => $validated['debtor_name'] ?? '',
            'category_source' => $validated['category_source'] ?? null,
            'search' => $validated['search'] ?? '',
            'sort' => $sortParam,
        ];

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        $automationRules = AutomationRule::query()
            ->where('user_id', $user->id)
            ->with(['category', 'labels'])
            ->orderBy('priority')
            ->get();

        return Inertia::render('transactions/index', [
            'transactions' => $transactions,
            'appliedFilters' => $appliedFilters,
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'automationRules' => $automationRules,
            'hasAiConsent' => $user->hasActiveAiConsent(),
            'aiConsentPromptDismissed' => $user->hasDismissedAiConsentPrompt(),
            'lastVisitAt' => $lastVisitAt?->toISOString(),
        ]);
    }

    public function categorize(Request $request): Response
    {
        $user = $request->user();

        $categories = Category::query()
            ->where('user_id', $user->id)
            ->forDisplay()
            ->get();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank')
            ->orderBy('name')
            ->get();

        $banks = Bank::query()
            ->availableForUser($user)
            ->orderBy('name')
            ->get();

        $labels = Label::query()
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get();

        $transactions = Transaction::query()
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->whereDoesntHave('splits')
            ->with(['account.bank', 'labels'])
            ->orderBy('transaction_date', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        return Inertia::render('transactions/categorize', [
            'categories' => $categories,
            'accounts' => $accounts,
            'banks' => $banks,
            'labels' => $labels,
            'transactions' => $transactions,
        ]);
    }

    public function store(StoreTransactionRequest $request, ManualBalanceAdjuster $balanceAdjuster, ReplaceTransactionSplits $replaceSplits): JsonResponse
    {
        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        $splits = $data['splits'] ?? null;
        unset($data['label_ids'], $data['splits']);

        $this->authorizeSplitWrite($request, $splits);

        $transaction = new Transaction([
            ...$data,
            'user_id' => $request->user()->id,
        ]);

        if (isset($data['id'])) {
            $transaction->id = $data['id'];
            $transaction->exists = false;
        }

        DB::transaction(function () use ($request, $transaction, $labelIds, $splits, $replaceSplits, $balanceAdjuster): void {
            $transaction->save();

            if ($labelIds !== null) {
                $transaction->labels()->sync($labelIds);
            }

            if ($splits !== null) {
                $replaceSplits->replace($transaction, $splits, $transaction->category_id);
            }

            if ($request->boolean('update_balance')) {
                $balanceAdjuster->applyCreatedTransaction($transaction);
            }
        }, attempts: 5);

        return response()->json([
            'data' => $transaction->fresh()->load(['labels', 'splits.category'])->append(['is_split', 'split_count']),
        ], 201);
    }

    public function update(UpdateTransactionRequest $request, Transaction $transaction, ManualBalanceAdjuster $balanceAdjuster, ReplaceTransactionSplits $replaceSplits): JsonResponse
    {
        $this->authorize('update', $transaction);

        $data = $request->validated();
        $labelIds = $data['label_ids'] ?? null;
        $hasLabelUpdate = $request->has('label_ids');
        $splits = $data['splits'] ?? null;
        $hasSplitUpdate = $request->has('splits');
        unset($data['label_ids'], $data['splits']);

        if ($hasSplitUpdate) {
            $this->authorizeSplitWrite($request, $splits ?? []);
        }

        $learnedRule = null;

        if ($transaction->splits()->exists() && ! $hasSplitUpdate && ($request->has('category_id') || $request->has('amount'))) {
            throw ValidationException::withMessages([
                'splits' => 'Category or amount changes on a split transaction require an explicit split replacement or removal.',
            ]);
        }

        DB::transaction(function () use (
            $request,
            $transaction,
            $data,
            $labelIds,
            $hasLabelUpdate,
            $splits,
            $hasSplitUpdate,
            $replaceSplits,
            $balanceAdjuster,
            &$learnedRule,
        ): void {
            $transaction->setRawAttributes(
                Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail()->getAttributes(),
                true,
            );
            $originalSnapshot = clone $transaction;

            if ($transaction->splits()->exists() && ! $hasSplitUpdate && $request->hasAny(['category_id', 'amount'])) {
                throw ValidationException::withMessages([
                    'splits' => 'Category or amount changes on a split transaction require an explicit split replacement or removal.',
                ]);
            }

            // Split creation supersedes the simple category and must not teach a
            // single-category correction.
            if ($request->has('category_id') && ! ($hasSplitUpdate && $splits !== [])) {
                $newCategoryId = $data['category_id'] ?? null;

                if ($newCategoryId !== $transaction->category_id) {
                    $learnedRule = app(CategoryOverrideHandler::class)->record($transaction, $newCategoryId);

                    $data['category_source'] = $newCategoryId === null ? null : CategorySource::Manual->value;
                    $data['ai_confidence'] = null;
                    $data['categorized_by_rule_id'] = null;
                }
            }

            if ($hasLabelUpdate) {
                $transaction->labels()->sync($labelIds ?? []);
                $transaction->load('labels');
            }

            if ($hasSplitUpdate) {
                $updated = $replaceSplits->replace(
                    $transaction,
                    $splits ?? [],
                    $data['category_id'] ?? null,
                    $data,
                );
                $transaction->setRawAttributes($updated->getAttributes(), true);
                $transaction->setRelations($updated->getRelations());
            } else {
                if (! empty($data)) {
                    $transaction->fill($data);
                }

                if ($transaction->isDirty() || $hasLabelUpdate) {
                    if (! $transaction->isDirty() && $hasLabelUpdate) {
                        $transaction->touch();
                    }
                    $transaction->save();
                }
            }

            $balanceFieldsChanged = collect(['amount', 'transaction_date', 'account_id'])
                ->contains(fn (string $field): bool => $originalSnapshot->getRawOriginal($field) !== $transaction->getRawOriginal($field));
            if ($request->boolean('update_balance') && $balanceFieldsChanged) {
                $balanceAdjuster->reverseDeletedTransaction($originalSnapshot);
                $balanceAdjuster->applyCreatedTransaction($transaction->load('account'));
            }
        }, attempts: 5);

        return response()->json([
            'data' => $transaction->fresh()->load(['labels', 'splits.category'])->append(['is_split', 'split_count']),
            'learned_rule' => $learnedRule === null ? null : [
                'id' => $learnedRule->id,
                'title' => $learnedRule->title,
                'category_id' => $learnedRule->action_category_id,
            ],
        ]);
    }

    /** @param array<int, array{category_id: string, amount: int}>|null $splits */
    private function authorizeSplitWrite(Request $request, ?array $splits): void
    {
        if ($splits === null || $splits === []) {
            return;
        }

        abort_unless(
            Feature::for($request->user())->active(TransactionSplitting::class),
            403,
            'Creating or replacing transaction splits is currently disabled.',
        );
    }

    public function destroy(Request $request, Transaction $transaction, ManualBalanceAdjuster $balanceAdjuster): JsonResponse
    {
        $this->authorize('delete', $transaction);

        if ($request->boolean('update_balance')) {
            $balanceAdjuster->reverseDeletedTransaction($transaction);
        }

        $transaction->delete();

        return response()->json([
            'message' => 'Transaction deleted successfully',
        ]);
    }

    public function bulkUpdate(BulkUpdateTransactionsRequest $request): JsonResponse
    {
        $user = $request->user();
        $transactionIds = $request->input('transaction_ids');
        $filters = $request->input('filters');

        $query = Transaction::query()->where('user_id', $user->id);

        if ($transactionIds && count($transactionIds) > 0) {
            $query->whereIn('id', $transactionIds);
            $transactions = $query->get();

            if ($transactions->count() !== count($transactionIds)) {
                return response()->json([
                    'message' => 'Some transactions were not found or do not belong to you.',
                ], 403);
            }
        } elseif ($filters !== null) {
            $query->applyFilters($filters);
            $transactions = $query->get();
        } else {
            $transactions = $query->get();
        }

        $updateData = [];
        if ($request->has('category_id')) {
            $newCategoryId = $request->input('category_id');
            $updateData['category_id'] = $newCategoryId;
            $updateData['category_source'] = $newCategoryId === null ? null : CategorySource::Manual->value;
            $updateData['ai_confidence'] = null;
            $updateData['categorized_by_rule_id'] = null;
        }
        if ($request->has('notes')) {
            $updateData['notes'] = $request->input('notes');
        }
        if ($request->has('notes_iv')) {
            $updateData['notes_iv'] = $request->input('notes_iv');
        }

        $labelIds = $request->input('label_ids');
        $hasLabelUpdate = $request->has('label_ids');

        if (empty($updateData) && ! $hasLabelUpdate) {
            return response()->json([
                'message' => 'No update data provided.',
            ], 400);
        }

        $selectedIds = $transactions->pluck('id');
        $result = DB::transaction(function () use (
            $user,
            $transactionIds,
            $selectedIds,
            $request,
            $updateData,
            $hasLabelUpdate,
            $labelIds,
        ): array {
            $lockedTransactions = Transaction::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $selectedIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->load('splits');

            if ($transactionIds && $lockedTransactions->count() !== count($transactionIds)) {
                abort(403, 'Some transactions were not found or do not belong to you.');
            }

            $hasCategoryUpdate = $request->has('category_id');
            $splitIds = $hasCategoryUpdate
                ? $lockedTransactions->filter(fn (Transaction $candidate): bool => $candidate->splits->isNotEmpty())->pluck('id')
                : collect();
            $eligibleTransactions = $hasCategoryUpdate
                ? $lockedTransactions->whereNotIn('id', $splitIds)
                : $lockedTransactions;
            $categoryData = collect($updateData)
                ->only(['category_id', 'category_source', 'ai_confidence', 'categorized_by_rule_id'])
                ->all();
            $generalData = collect($updateData)
                ->except(['category_id', 'category_source', 'ai_confidence', 'categorized_by_rule_id'])
                ->all();

            $changedIds = collect();
            if ($hasCategoryUpdate) {
                $categoryCandidates = $eligibleTransactions->filter(
                    fn (Transaction $candidate): bool => collect($categoryData)->contains(
                        fn (mixed $value, string $field): bool => $candidate->getRawOriginal($field) !== $value,
                    ),
                );
                $overrideHandler = app(CategoryOverrideHandler::class);
                foreach ($categoryCandidates as $candidate) {
                    if ($candidate->category_id !== $request->input('category_id')) {
                        $overrideHandler->record($candidate, $request->input('category_id'));
                    }
                }
                if ($categoryCandidates->isNotEmpty()) {
                    Transaction::query()
                        ->whereIn('id', $categoryCandidates->pluck('id'))
                        ->update($categoryData);
                    $changedIds = $changedIds->merge($categoryCandidates->pluck('id'));
                }
            }

            if ($generalData !== []) {
                $generalCandidates = $lockedTransactions->filter(
                    fn (Transaction $candidate): bool => collect($generalData)->contains(
                        fn (mixed $value, string $field): bool => $candidate->getRawOriginal($field) !== $value,
                    ),
                );
                if ($generalCandidates->isNotEmpty()) {
                    Transaction::query()
                        ->whereIn('id', $generalCandidates->pluck('id'))
                        ->update($generalData);
                    $changedIds = $changedIds->merge($generalCandidates->pluck('id'));
                }
            }

            if ($hasLabelUpdate) {
                foreach ($lockedTransactions as $lockedTransaction) {
                    $changes = $lockedTransaction->labels()->sync($labelIds ?? []);
                    if ($changes['attached'] !== [] || $changes['detached'] !== [] || $changes['updated'] !== []) {
                        $lockedTransaction->touch();
                        $changedIds->push($lockedTransaction->id);
                    }
                }
            }

            return [
                'count' => $lockedTransactions->count(),
                'updated_count' => $changedIds->unique()->count(),
                'skipped_split_count' => $splitIds->count(),
            ];
        }, attempts: 5);

        return response()->json([
            'message' => 'Transactions updated successfully',
            ...$result,
        ]);
    }
}
