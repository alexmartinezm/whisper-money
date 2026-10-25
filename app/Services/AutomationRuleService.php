<?php

namespace App\Services;

use App\Enums\CategorySource;
use App\Jobs\ReassignTransactionsToBudgets;
use App\Models\AutomationRule;
use App\Models\LabelTransaction;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JWadhams\JsonLogic;

class AutomationRuleService
{
    /**
     * How many transaction ids ride in one reassignment job.
     *
     * ponytail: fixed 500; only the AI suggestion path is unbounded, and it has
     * never come close to needing a smarter split.
     */
    private const REASSIGN_BATCH_SIZE = 500;

    /**
     * Per-user rule cache, memoized for the lifetime of this service instance.
     *
     * Bulk re-evaluation calls applyRules() once per transaction; without this
     * every transaction re-queried the user's full rule set (an N+1). A service
     * instance is short-lived (resolved fresh per job/request), so rules created
     * after it is built are never seen mid-run — which is the intended behaviour.
     *
     * @var array<string, EloquentCollection<int, AutomationRule>>
     */
    private array $rulesByUser = [];

    /**
     * @param  bool  $reassignBudgets  set to false when the caller loops over many
     *                                 transactions and batches the reassignment
     *                                 itself, so this does not queue one job per row
     * @return bool whether the rule changed something budget membership depends on
     */
    public function applyRules(Transaction $transaction, bool $reassignBudgets = true): bool
    {
        if ($transaction->description_iv !== null || $this->isSplit($transaction)) {
            return false;
        }

        $rules = $this->rulesForUser($transaction->user_id);

        if ($rules->isEmpty()) {
            return false;
        }

        $transactionData = $this->prepareTransactionData($transaction);
        $matchedRule = $this->evaluateRules($rules, $transactionData);

        $affectsBudgets = $matchedRule !== null && $this->applyActions($transaction, $matchedRule);

        if ($affectsBudgets && $reassignBudgets) {
            ReassignTransactionsToBudgets::dispatch([$transaction->id]);
        }

        return $affectsBudgets;
    }

    /**
     * Determine whether a single rule's conditions match the transaction.
     *
     * Encrypted transactions are skipped because rule evaluation reads the
     * plaintext description and notes which the server cannot access.
     */
    public function ruleMatches(AutomationRule $rule, Transaction $transaction): bool
    {
        if ($transaction->description_iv !== null || $this->isSplit($transaction)) {
            return false;
        }

        $transactionData = $this->prepareTransactionData($transaction, $rule);

        try {
            $normalizedRulesJson = $this->normalizeRuleJson($rule->rules_json);

            return JsonLogic::apply($normalizedRulesJson, $transactionData) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Apply a rule's actions to a set of transactions.
     *
     * When $onlyUncategorized is true the set is re-filtered here, at apply
     * time, rather than trusting the caller's (possibly stale) match snapshot.
     * A transaction that was categorized after the snapshot was taken — by the
     * user, another rule, or the AI backfill — is skipped so the rule never
     * silently overwrites a category the user did not ask it to touch.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     */
    public function applyRuleActionsToTransactions(EloquentCollection $transactions, AutomationRule $rule, bool $onlyUncategorized = false): int
    {
        if ($transactions->isEmpty()) {
            return 0;
        }

        $rule->loadMissing('labels');
        $transactions->loadMissing(['labels', 'splits']);

        // A split transaction carries its categories on its postings, so a rule
        // that would rewrite its category column has nothing to say about it.
        $transactions = $transactions->reject(
            fn (Transaction $transaction): bool => $transaction->splits->isNotEmpty(),
        );

        if ($transactions->isEmpty()) {
            return 0;
        }

        if ($onlyUncategorized) {
            $transactions = $transactions->reject(
                fn (Transaction $transaction): bool => $this->shouldSkipForOnlyUncategorized($rule, $transaction),
            );

            if ($transactions->isEmpty()) {
                return 0;
            }
        }

        // Kept in this order: the note is appended with saveQuietly(), which must
        // run after the category mass update so it writes only the notes column.
        $categorizedIds = $this->applyCategoryInBulk($transactions, $rule);
        $notedIds = $this->applyNoteInBulk($transactions, $rule);
        $labelledIds = $this->applyLabelsInBulk($transactions, $rule);

        // A note never changes which budget counts a transaction, so it does not
        // earn a reassignment — but it does count as a change for the caller.
        $this->reassignInBatches(array_merge($categorizedIds, $labelledIds));

        return count(array_unique(array_merge($categorizedIds, $notedIds, $labelledIds)));
    }

    /**
     * Queue the budget reassignment of transactions the bulk apply changed.
     *
     * The mass update and the pivot insert fire no model event, so nothing else
     * re-derives which budgets now track them. Dispatching per row would queue a
     * job for every transaction the rule touched, and dispatching one job for
     * everything would put an unbounded id list in a single queue payload.
     *
     * @param  array<int, string>  $transactionIds
     */
    private function reassignInBatches(array $transactionIds): void
    {
        foreach (array_chunk(array_values(array_unique($transactionIds)), self::REASSIGN_BATCH_SIZE) as $batch) {
            ReassignTransactionsToBudgets::dispatch($batch, notify: false);
        }
    }

    /**
     * Set the rule's category on every transaction that does not already carry
     * it, in a single mass update.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     * @return array<int, string> ids of the transactions changed
     */
    private function applyCategoryInBulk(EloquentCollection $transactions, AutomationRule $rule): array
    {
        if ($rule->action_category_id === null) {
            return [];
        }

        $candidateIds = $transactions->pluck('id')->all();

        if ($candidateIds === []) {
            return [];
        }

        // Locked and re-read: the collection was loaded before this ran, so a
        // transaction split or recategorized in between must not be overwritten.
        return DB::transaction(function () use ($candidateIds, $rule): array {
            $eligibleIds = Transaction::query()
                ->whereIn('id', $candidateIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->whereDoesntHave('splits')
                ->where(fn ($query) => $query
                    ->whereNull('category_id')
                    ->orWhere('category_id', '!=', $rule->action_category_id))
                ->pluck('id')
                ->all();

            if ($eligibleIds !== []) {
                Transaction::query()
                    ->whereIn('id', $eligibleIds)
                    ->update([
                        'category_id' => $rule->action_category_id,
                        'category_source' => CategorySource::Rule->value,
                        'categorized_by_rule_id' => $rule->id,
                        'updated_at' => now(),
                    ]);
            }

            return $eligibleIds;
        }, attempts: 5);
    }

    /**
     * Append the rule's note where it is not already present. Encrypted notes are
     * skipped because applying them requires the user's key.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     * @return array<int, string> ids of the transactions changed
     */
    private function applyNoteInBulk(EloquentCollection $transactions, AutomationRule $rule): array
    {
        if (! $rule->action_note || $rule->action_note_iv !== null) {
            return [];
        }

        $transactionIds = [];

        foreach ($transactions as $transaction) {
            $existingNotes = $transaction->notes ?? '';

            if ($this->noteAlreadyPresent($existingNotes, $rule->action_note)) {
                continue;
            }

            $transaction->notes = $existingNotes
                ? $existingNotes."\n".$rule->action_note
                : $rule->action_note;
            $transaction->saveQuietly();
            $transactionIds[] = $transaction->id;
        }

        return $transactionIds;
    }

    /**
     * Attach the rule's labels in a single pivot insert, skipping the pairs that
     * already exist.
     *
     * @param  EloquentCollection<int, Transaction>  $transactions
     * @return array<int, string> ids of the transactions changed
     */
    private function applyLabelsInBulk(EloquentCollection $transactions, AutomationRule $rule): array
    {
        $labelIds = $rule->labels->pluck('id')->all();

        if ($labelIds === []) {
            return [];
        }

        $now = now();
        $labelTransactionRows = [];
        $transactionIds = [];

        foreach ($transactions as $transaction) {
            $missingLabelIds = array_diff($labelIds, $transaction->labels->pluck('id')->all());

            foreach ($missingLabelIds as $labelId) {
                $labelTransactionRows[] = [
                    'id' => (string) Str::uuid(),
                    'label_id' => $labelId,
                    'transaction_id' => $transaction->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $transactionIds[] = $transaction->id;
            }
        }

        if ($labelTransactionRows !== []) {
            LabelTransaction::query()->insertOrIgnore($labelTransactionRows);
        }

        return $transactionIds;
    }

    /**
     * Whether a transaction should be skipped when "only uncategorized" is on.
     *
     * For category-setting rules: skip if the transaction already has a category.
     * For label-only rules: skip if the transaction already has every label
     * the rule would add (otherwise a label-only rule could never apply).
     */
    public function shouldSkipForOnlyUncategorized(AutomationRule $rule, Transaction $transaction): bool
    {
        if ($rule->action_category_id !== null) {
            return $transaction->category_id !== null || $this->isSplit($transaction);
        }

        $ruleLabelIds = $rule->labels->pluck('id')->all();

        if (empty($ruleLabelIds)) {
            return false;
        }

        $transactionLabelIds = $transaction->labels->pluck('id')->all();

        return empty(array_diff($ruleLabelIds, $transactionLabelIds));
    }

    /**
     * Whether the transaction is filed under postings rather than its own
     * category column. Reads the loaded relation when there is one so a caller
     * looping over a chunk does not query per row.
     */
    private function isSplit(Transaction $transaction): bool
    {
        return $transaction->relationLoaded('splits')
            ? $transaction->splits->isNotEmpty()
            : $transaction->splits()->exists();
    }

    public function eagerLoadsForRuleEvaluation(AutomationRule $rule): array
    {
        $variables = $this->ruleVariables($rule);
        $eagerLoads = [];

        if (in_array('bank_name', $variables, true)) {
            $eagerLoads[] = 'account.bank';
        } elseif (in_array('account_name', $variables, true)) {
            $eagerLoads[] = 'account';
        }

        if (in_array('category', $variables, true)) {
            $eagerLoads[] = 'category';
        }

        return $eagerLoads;
    }

    /**
     * @return array{description: string, amount: float, transaction_date: string, bank_name: string, account_name: string, category: string|null, notes: string|null, creditor_name: string|null, debtor_name: string|null}
     */
    private function prepareTransactionData(Transaction $transaction, ?AutomationRule $rule = null): array
    {
        $transaction->loadMissing($rule ? $this->eagerLoadsForRuleEvaluation($rule) : ['account.bank', 'category']);

        $account = $transaction->relationLoaded('account') ? $transaction->account : null;
        $bank = $account?->relationLoaded('bank') ? $account->bank : null;
        $category = $transaction->relationLoaded('category') ? $transaction->category : null;

        $accountName = '';
        if ($account && ! $account->encrypted) {
            $accountName = trim($account->name);
        }

        return [
            'description' => $this->normalizeWhitespace(mb_strtolower($transaction->description ?? '')),
            'amount' => $transaction->amount / 100,
            'transaction_date' => $transaction->transaction_date->format('Y-m-d'),
            'bank_name' => mb_strtolower($bank->name ?? ''),
            'account_name' => mb_strtolower($accountName),
            'category' => $category?->name,
            'notes' => $transaction->notes
                ? $this->normalizeWhitespace(mb_strtolower($transaction->notes))
                : null,
            'creditor_name' => $transaction->creditor_name
                ? $this->normalizeWhitespace(mb_strtolower($transaction->creditor_name))
                : null,
            'debtor_name' => $transaction->debtor_name
                ? $this->normalizeWhitespace(mb_strtolower($transaction->debtor_name))
                : null,
        ];
    }

    /**
     * @return EloquentCollection<int, AutomationRule>
     */
    private function rulesForUser(string $userId): EloquentCollection
    {
        return $this->rulesByUser[$userId] ??= AutomationRule::query()
            ->where('user_id', $userId)
            ->with('labels')
            ->orderBy('priority')
            ->get();
    }

    /**
     * @param  Collection<int, AutomationRule>  $rules
     * @param  array<string, mixed>  $transactionData
     */
    private function evaluateRules(Collection $rules, array $transactionData): ?AutomationRule
    {
        foreach ($rules as $rule) {
            try {
                $normalizedRulesJson = $this->normalizeRuleJson($rule->rules_json);
                $result = JsonLogic::apply($normalizedRulesJson, $transactionData);

                if ($result === true) {
                    return $rule;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function ruleVariables(AutomationRule $rule): array
    {
        $variables = [];
        $this->collectRuleVariables($this->normalizeRuleJson($rule->rules_json), $variables);

        return array_values(array_unique($variables));
    }

    /**
     * @param  array<int, string>  $variables
     */
    private function collectRuleVariables(mixed $ruleJson, array &$variables): void
    {
        if (! is_array($ruleJson)) {
            return;
        }

        if (isset($ruleJson['var']) && is_string($ruleJson['var'])) {
            $variables[] = $ruleJson['var'];
        }

        foreach ($ruleJson as $value) {
            $this->collectRuleVariables($value, $variables);
        }
    }

    /**
     * @return bool whether the actions changed something budget membership
     *              depends on — the note is deliberately not one of them
     */
    private function applyActions(Transaction $transaction, AutomationRule $rule): bool
    {
        return DB::transaction(function () use ($transaction, $rule): bool {
            $locked = Transaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            $recategorized = $this->applyCategoryAction($locked, $rule);
            $this->applyNoteAction($locked, $rule);

            if ($locked->isDirty()) {
                $locked->saveQuietly();
            }

            $labelled = $this->applyLabelActions($locked, $rule);

            $transaction->setRawAttributes($locked->getAttributes(), true);

            return $recategorized || $labelled;
        });
    }

    /**
     * A split transaction is filed under its postings, so a rule must not
     * overwrite the parent's category.
     *
     * @return bool whether the category actually moved
     */
    private function applyCategoryAction(Transaction $locked, AutomationRule $rule): bool
    {
        if ($rule->action_category_id === null
            || $locked->category_id === $rule->action_category_id
            || $locked->splits()->exists()) {
            return false;
        }

        $locked->category_id = $rule->action_category_id;
        $locked->category_source = CategorySource::Rule;
        $locked->categorized_by_rule_id = $rule->id;

        return true;
    }

    /**
     * Only plain notes are applied — an encrypted one would need the user's key,
     * which a background rule run does not have.
     */
    private function applyNoteAction(Transaction $locked, AutomationRule $rule): void
    {
        if (! $rule->action_note || $rule->action_note_iv !== null) {
            return;
        }

        $existingNotes = $locked->notes ?? '';

        if ($this->noteAlreadyPresent($existingNotes, $rule->action_note)) {
            return;
        }

        $locked->notes = $existingNotes
            ? $existingNotes."\n".$rule->action_note
            : $rule->action_note;
    }

    /**
     * @return bool whether a label was newly attached — budget membership can
     *              follow a label, so only an actual attach counts
     */
    private function applyLabelActions(Transaction $locked, AutomationRule $rule): bool
    {
        $labelIds = $rule->labels->pluck('id')->all();

        if ($labelIds === []) {
            return false;
        }

        $result = $locked->labels()->syncWithoutDetaching($labelIds);

        return ! empty($result['attached']);
    }

    private function noteAlreadyPresent(string $existingNotes, string $note): bool
    {
        return mb_strpos($existingNotes, $note) !== false;
    }

    private function normalizeRuleJson(mixed $rulesJson): mixed
    {
        if (is_string($rulesJson)) {
            $decoded = json_decode($rulesJson, true);
            if (is_array($decoded)) {
                return $this->normalizeRuleJson($decoded);
            }

            return mb_strtolower($rulesJson);
        }

        if (is_array($rulesJson)) {
            if (array_is_list($rulesJson)) {
                return array_map(function (mixed $item, int $index): mixed {
                    if ($index === 0 && is_string($item)) {
                        return mb_strtolower($item);
                    }

                    if (is_array($item) && isset($item['var']) && in_array($item['var'], ['description', 'notes', 'creditor_name', 'debtor_name'], true)) {
                        return $item;
                    }

                    return $this->normalizeRuleJson($item);
                }, $rulesJson, array_keys($rulesJson));
            }

            $normalized = [];
            foreach ($rulesJson as $key => $value) {
                $normalized[$key] = $this->normalizeRuleJson($value);
            }

            return $normalized;
        }

        return $rulesJson;
    }

    private function normalizeWhitespace(string $str): string
    {
        return trim(preg_replace('/\s+/', ' ', $str));
    }
}
