<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\PresentsAutomationRules;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\Category;
use App\Models\Label;
use App\Models\Space;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * Base for every Whisper Money write tool. On top of the McpTool Pro-plan gate
 * it gates write access: OAuth connections (Claude Desktop / ChatGPT) get
 * read+write, and Sanctum personal access tokens must carry the `mcp:write`
 * ability, so a read-only PAT can analyse data but never change it.
 *
 * Only the irreversible tools (the deletes) carry #[IsDestructive]; creating,
 * updating, categorizing and labelling are reversible and inherit
 * `destructiveHint: false` from McpTool. PHP attributes are not inherited, so
 * the framework only reports one declared directly on the served tool class —
 * it cannot live here.
 */
abstract class WriteTool extends McpTool
{
    use PresentsAutomationRules;

    protected function respond(Request $request, User $user): Response
    {
        // Write access is granted to OAuth connections (Claude Desktop /
        // ChatGPT, resolved via the `api` guard — the user approves the
        // connection on the consent screen) and to Sanctum personal access
        // tokens carrying the mcp:write ability. A read-only Sanctum token is
        // rejected.
        if (Auth::getDefaultDriver() !== 'api' && ! $user->tokenCan('mcp:write')) {
            return Response::error('This token is read-only. Create a read & write token to make changes.');
        }

        return $this->write($request, $user);
    }

    abstract protected function write(Request $request, User $user): Response;

    /**
     * Assign only the fields the request actually carries — the "only what you
     * pass changes" contract every update tool promises. Values are closures so
     * that resolving a related model (which may fail validation) happens only
     * when the field is present.
     *
     * @param  array<string, callable(): mixed>  $fields
     */
    protected function applyFields(Request $request, Model $model, array $fields): void
    {
        foreach ($fields as $attribute => $value) {
            if ($request->has($attribute)) {
                $model->{$attribute} = $value();
            }
        }
    }

    /**
     * A string field, or null when the agent passed an empty value to clear it.
     */
    protected function nullableString(Request $request, string $key): ?string
    {
        return $request->filled($key) ? $request->string($key)->toString() : null;
    }

    /**
     * Resolve an account whose balance snapshots may be written. Connected
     * accounts are rejected: their balances come from the bank and any manual
     * snapshot would be overwritten by the next sync.
     */
    protected function balanceWritableAccount(Request $request, Space $space, string $key = 'account_id'): Account
    {
        $account = $this->accountInSpace($request, $space, $key);

        $this->assertBalanceWritable($account, $key);

        return $account;
    }

    /**
     * Assert an already-resolved account accepts balance writes. Reached from
     * the other direction too: a tool given a snapshot's id resolves the account
     * from it and needs the same verdict, worded the same way.
     */
    protected function assertBalanceWritable(Account $account, string $key = 'account_id'): void
    {
        if ($account->isConnected()) {
            throw ValidationException::withMessages([
                $key => 'That account is connected to a bank, so its balances come from the sync and a manual snapshot would be overwritten. Only non-connected (manual) accounts accept balances.',
            ]);
        }
    }

    protected function transactionInSpace(Request $request, Space $space, string $key = 'transaction_id'): Transaction
    {
        return $this->modelInSpace($request, $space, Transaction::query()->forSpace($space), $key, 'transaction', 'Call search_transactions to find ids.');
    }

    protected function categoryInSpace(Request $request, Space $space, string $key = 'category_id'): Category
    {
        return $this->modelInSpace($request, $space, Category::query()->forSpace($space), $key, 'category', 'Call list_categories to see valid ids.');
    }

    protected function labelInSpace(Request $request, Space $space, string $key = 'label_id'): Label
    {
        return $this->modelInSpace($request, $space, Label::query()->forSpace($space), $key, 'label', 'Call list_labels to see valid ids.');
    }

    protected function ruleInSpace(Request $request, Space $space, string $key = 'automation_rule_id'): AutomationRule
    {
        return $this->modelInSpace($request, $space, AutomationRule::query()->forSpace($space), $key, 'automation rule');
    }

    /**
     * The transaction shape returned by every transaction write tool, matching
     * the fields search_transactions exposes so the agent sees a familiar row.
     *
     * @return array<string, mixed>
     */
    protected function presentTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing(['account:id,name', 'category:id,name', 'labels:id,name', 'splits.category:id,name']);

        return [
            'id' => $transaction->id,
            'date' => $transaction->transaction_date->toDateString(),
            'description' => $transaction->description,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency_code,
            'category_id' => $transaction->category_id,
            'category' => $transaction->category?->name,
            'category_source' => $transaction->category_source?->value,
            'account_id' => $transaction->account_id,
            'account' => $transaction->account?->name,
            'source' => $transaction->source->value,
            'creditor_name' => $transaction->creditor_name,
            'debtor_name' => $transaction->debtor_name,
            'labels' => $transaction->labels
                ->map(fn (Label $label): array => ['id' => $label->id, 'name' => $label->name])
                ->values()
                ->all(),
            'is_split' => $transaction->splits->isNotEmpty(),
            'splits' => $transaction->splits->map(fn ($split): array => [
                'id' => $split->id,
                'category_id' => $split->category_id,
                'category' => $split->category?->name,
                'amount' => $split->amount,
                'position' => $split->position,
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentCategory(Category $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'icon' => $category->icon,
            'color' => $category->color,
            'type' => $category->type->value,
            'cashflow_direction' => $category->cashflow_direction->value,
            'parent_id' => $category->parent_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentLabel(Label $label): array
    {
        return [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
        ];
    }
}
