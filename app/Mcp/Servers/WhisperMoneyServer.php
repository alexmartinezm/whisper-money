<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
Access to the authenticated user's Whisper Money finance data, for analysing
spending, cashflow and net worth — and, with write access, for editing that
data.

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction, account, category and label tools accept an optional `space` id and
  default to the personal space; call `list_spaces` to discover ids. The cashflow,
  net-worth and spending tools cover the user's whole account.
- To find recurring charges (subscriptions), use `search_transactions` and group
  the results by merchant and cadence yourself.

Write tools ... require a read & write Sanctum token; OAuth connections follow the current
WriteTool policy. A read-only Sanctum token can analyse data but never change it. Bank-connected accounts
and bank/imported transactions are protected: you can only create, edit or
delete manual transactions and manual-account balances, but you can categorize
and label any transaction, and split any accessible transaction without changing
its ledger fields. `split_transaction` replaces all category postings at once;
the amounts must sum exactly to the parent amount. Use `splits: []` with a
`fallback_category_id` to remove a split. Labels remain fields of the parent.
MARKDOWN)]
class WhisperMoneyServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        // Read
        SearchTransactions::class,
        SpendingByCategory::class,
        GetCashflow::class,
        GetNetWorth::class,
        ListAccounts::class,
        ListCategories::class,
        ListLabels::class,
        ListSpaces::class,

        // Write
        CreateTransaction::class,
        UpdateTransaction::class,
        DeleteTransaction::class,
        CategorizeTransaction::class,
        SplitTransaction::class,
        LabelTransaction::class,
        CreateBalance::class,
        CreateCategory::class,
        UpdateCategory::class,
        DeleteCategory::class,
        CreateLabel::class,
        UpdateLabel::class,
        DeleteLabel::class,
        CreateAutomationRule::class,
        UpdateAutomationRule::class,
        DeleteAutomationRule::class,
    ];
}
