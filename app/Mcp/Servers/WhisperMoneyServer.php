<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\GetRunway;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListRecurringSeries;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateTransaction;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.5.0')]
#[Instructions(<<<'MARKDOWN'
Access to the authenticated user's Whisper Money finance data, for analysing
spending, cashflow and net worth — and, with write access, for editing that
data.

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction, account, category, label and budget tools accept an optional `space` id and
  default to the personal space; call `list_spaces` to discover ids. The cashflow,
  net-worth and spending tools cover the user's whole account.
- Budgets are owner-scoped inside a space. `create_budget` creates the cadence and
  tracking configuration; `update_budget` changes the name, allocation, categories
  and labels. Tracking changes preserve closed-period history and recalculate the
  active period. Cadence, start day and rollover remain immutable. Historical
  assignment may still be processing after creation or a tracking change.
- For monthly budgets, allocation changes use the server application date. A change
  on the first day includes the current period; after that it affects periods whose
  start date is on or after the application date, plus future periods.
- Recurring charges (subscriptions, bills, standing payments) are detected server-side
  on a schedule. Read them with `list_recurring_series` instead of grouping
  `search_transactions` results by merchant and cadence yourself. Expected amounts are
  medians and may be marked variable; `monthly_equivalent_amount` rescales any cadence
  to a month so series can be compared and summed. Totals are reported per currency in
  `monthly_expense_totals`; never add amounts from different currencies together.
- For anything forward-looking — "do I make it to payday", "what is left at the end of
  the month", "am I about to overdraw" — call `get_runway`. It already walks the balance
  through the expected charges and adds an estimate of everyday spending, so do not
  rebuild it from `list_recurring_series` or `get_cashflow`, which reports the past. Its
  `everyday_spending` block is an estimate and is null when there is too little history;
  when it is present, quote its figures rather than the exact ones, which ignore
  groceries and everything else that never repeats on a cadence.

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
    /**
     * Serve the whole catalogue on one page.
     *
     * `tools/list` is paginated and the framework default of 15 splits thirty
     * tools across two pages, so a client that does not follow `nextCursor`
     * silently loses everything past the fifteenth — half the write tools, and
     * whichever read tool a later addition happens to push over the edge. The
     * ceiling is 50, comfortably above the current count, and a larger
     * discovery payload is a better trade than tools nobody can see.
     */
    public int $defaultPaginationLength = 50;

    /** @var array<int, class-string<Tool>> */
    protected array $tools = [
        // Read
        SearchTransactions::class,
        SpendingByCategory::class,
        GetCashflow::class,
        GetRunway::class,
        GetNetWorth::class,
        ListAccounts::class,
        ListBudgets::class,
        ListCategories::class,
        ListLabels::class,
        ListRecurringSeries::class,
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
        CreateBudget::class,
        UpdateBudget::class,
        DeleteBudget::class,
    ];
}
