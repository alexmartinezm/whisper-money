<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CategorizeTransaction;
use App\Mcp\Tools\CreateAccount;
use App\Mcp\Tools\CreateAutomationRule;
use App\Mcp\Tools\CreateBalance;
use App\Mcp\Tools\CreateBudget;
use App\Mcp\Tools\CreateCategory;
use App\Mcp\Tools\CreateLabel;
use App\Mcp\Tools\CreateSavingsGoal;
use App\Mcp\Tools\CreateTransaction;
use App\Mcp\Tools\DeleteAutomationRule;
use App\Mcp\Tools\DeleteBalance;
use App\Mcp\Tools\DeleteBudget;
use App\Mcp\Tools\DeleteCategory;
use App\Mcp\Tools\DeleteLabel;
use App\Mcp\Tools\DeleteTransaction;
use App\Mcp\Tools\GetCashflow;
use App\Mcp\Tools\GetNetWorth;
use App\Mcp\Tools\GetRunway;
use App\Mcp\Tools\LabelTransaction;
use App\Mcp\Tools\ListAccounts;
use App\Mcp\Tools\ListAutomationRules;
use App\Mcp\Tools\ListBalances;
use App\Mcp\Tools\ListBudgets;
use App\Mcp\Tools\ListCategories;
use App\Mcp\Tools\ListLabels;
use App\Mcp\Tools\ListRecurringSeries;
use App\Mcp\Tools\ListSavingsGoals;
use App\Mcp\Tools\ListSpaces;
use App\Mcp\Tools\SearchTransactions;
use App\Mcp\Tools\SpendingByCategory;
use App\Mcp\Tools\SplitTransaction;
use App\Mcp\Tools\UpdateAccount;
use App\Mcp\Tools\UpdateAutomationRule;
use App\Mcp\Tools\UpdateBudget;
use App\Mcp\Tools\UpdateBudgetPeriod;
use App\Mcp\Tools\UpdateCategory;
use App\Mcp\Tools\UpdateLabel;
use App\Mcp\Tools\UpdateSavingsGoal;
use App\Mcp\Tools\UpdateTransaction;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;

#[Name('Whisper Money')]
#[Version('1.8.0')]
#[Instructions(<<<'MARKDOWN'
Access to the authenticated user's Whisper Money finance data, for analysing
spending, cashflow and net worth — and, with write access, for editing that
data.

Whisper Money is bookkeeping only: no tool moves money, pays anyone or reaches a
bank. When the user asks to transfer, pay or send money, say plainly that this
app cannot do that — do not ask for a destination account as if it could — and
offer to record the transfer as a manual transaction once they have made it
themselves.

- All amounts are integers in minor units (cents). Divide by 100 for a display value.
- Data is organised into "spaces" (the personal space and any shared spaces).
  Transaction, account, category, label, budget, balance, automation-rule and savings goal
  tools accept an optional `space` id and default to the personal space; call
  `list_spaces` to discover ids.
  `get_cashflow`, `get_net_worth` and `spending_by_category` are the exception: they take no
  `space` and aggregate every space the user has, with no way to narrow them to one. Never
  present their figures next to a space-filtered total as if the two were comparable — the
  gap is the other spaces, not a discrepancy.
- Categories form a tree, and a child never disagrees with its parent: it inherits the
  parent's type and cashflow direction, and moving a category re-derives both and cascades
  them to everything below it. Deleting one with `strategy: "cascade"` also needs
  `confirm_cascade: true`, because it takes the whole subtree and uncategorizes its
  transactions.
- `list_automation_rules` shows the rules in evaluation order. Read it before writing a rule
  so you do not duplicate or contradict one that exists, and after writing to confirm what
  was stored. The `amount` variable inside `rules_json` is in major units, unlike every
  other amount in this API.
- Budgets are owner-scoped inside a space. `create_budget` creates the cadence and
  tracking configuration; `update_budget` changes the name, allocation, categories
  and labels. Tracking changes preserve closed-period history and recalculate the
  active period. Cadence, start day and rollover remain immutable. Historical
  assignment may still be processing after creation or a tracking change.
  Each budget also has an `expense_type`, `fixed` or `variable` (the default),
  which both tools accept and `update_budget` can change at any time; only the
  cashflow expense split reads it. A catch-all budget is always `variable`.
- For monthly budgets, allocation changes use the server application date. A change
  on the first day includes the current period; after that it affects periods whose
  start date is on or after the application date, plus future periods.
- For a one-off month (an extraordinary expense), use `update_budget_period`: it sets
  the allocation of the single period that contains `date`, the one in progress or a
  future one up to 12 months ahead, and leaves every other period as it was. Periods
  report `is_one_off` and the `regular_allocated_amount` they go back to. A later
  allocation change through `update_budget` keeps one-off amounts and only moves their
  regular allocation; `update_budget_period` with `reset: true` puts a period back on
  the regular allocation.
- `get_cashflow`'s summary splits `expense` three ways by budget: `fixed_expense`
  is spending tracked by budgets marked fixed (rent, subscriptions),
  `variable_expense` by budgets marked variable (groceries, leisure), and
  `general_expense` the rest, which no budget tracks. Catch-all budgets do not
  count; a transaction tracked by both kinds counts once, as fixed. The three
  add up to `expense`, and any can be negative in a month of refunds.
  Budgets are variable unless the user marked them fixed, so `fixed_expense: 0`
  may only mean none has been marked yet, not that there are no fixed costs.
- A savings goal is tracked through a label: its contributions are the
  transactions tagged with it. A `one_off` goal saves towards a total, optionally
  by a date, and `list_savings_goals` reports its `progress`. A `monthly` goal
  starts over every calendar month with a target that is either a fixed `amount`
  or an `income_rate`, a share of the average income of the three previous
  complete months, frozen when the month opens. Each month is judged against its
  own target: `monthly.history` lists every month since the goal was created
  with its `target`, `saved`, signed `difference` and `status` (`met`, `missed`,
  `in_progress`, `partial` or `archived`); nothing carries over between months,
  and a transaction tagged late recalculates the month it belongs to. A
  `partial` month (the goal was created in the month's last 5 days) and an
  `archived` one (the goal was archived during it) show what was saved but
  have no verdict: they are left out of `months_met`, `months_closed`, the
  streaks and `cumulative_difference`, so never report them as met or missed.
  A savings account can feed only one running monthly goal's auto-tag rule. A goal's kind is fixed, and
  editing a monthly target only changes the month in progress and later ones.
  A transaction counts towards a goal once it carries the goal's `label_id`
  (`label_transaction`). `create_savings_goal` can also add an automation rule
  that tags incoming transfers to one of the user's savings accounts
  (`auto_tag_account_id`), including the ones already there since the start of
  the month; the response reports the rule and how many it tagged. Archiving
  and deleting a goal happen in the app only.
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
WriteTool policy. A read-only Sanctum token can analyse data but never change it. Manual
transactions can be created on any account, bank-connected ones included — a sync never
removes them. Bank/imported transactions keep their core fields protected: only a
manually-created one can change its description, amount, date, currency, account or
counterparty names, and only a manually-created one can be deleted. Their notes and
category are not locked — `update_transaction` writes those on any transaction — and you
can label any transaction, and split any accessible transaction without changing its
ledger fields. Balances can only be
recorded on non-connected accounts, since a connected account's balances come from the bank
and would be overwritten (`list_balances` reads any account's, `delete_balance` removes a
manual one recorded by mistake). `split_transaction` replaces all category postings at once;
the amounts must sum exactly to the parent amount. Use `splits: []` with a
`fallback_category_id` to remove a split. Labels remain fields of the parent.
Accounts come in two kinds and the difference decides what can be written.
`create_account` opens a manual account of any of the eight types — a loan or a
property takes its own details (interest rate and term, purchase price and date)
and, given a balance, gets the monthly history in between generated for it, the
older part in the background. A bank-connected account cannot be created at all:
one is only born when the user goes through their bank's consent flow in the
Whisper Money app, so ask them to connect the bank there rather than trying to
build one.
`update_account` changes only the fields it is passed. On a manual account that
is everything: name, type, currency, bank, ownership share and the loan/property
details. On a connected account only the name and the ownership fields can
change — the currency is what its whole synced history is denominated in, the
bank comes from the connection, and the type can only move between the four
types that keep a transaction ledger (checking, credit_card, savings, others),
since the others would leave the sync nowhere to write. Neither tool archives,
hides or deletes an account; the user does that in the app. Retyping a loan or a
property to something else keeps its details (interest rate, purchase price) and
any mortgage link on file but stops using them, so confirm it with the user
first.
`ownership_percentage` below 100 means the user only owns that share of the
account, and the app counts only that slice of it in their figures — changing it
also reweighs the budgets that already counted the account.
When a credit card row carries `credit_card_statement`, it is an estimate of the
next charge (`is_final` false means the cycle is still open), computed from the
statement dates in `credit_card_detail`; it is not a transaction. Those dates are
one cycle, and later cycles repeat monthly or weekly from them, as
`statement_frequency` says.
`credit_card_usage` is how much of the card is in use: `used` adds up what is
still to be charged between `period_from` and today (the pending statement plus
the open cycle, or the calendar month when the card has no statement dates),
less the payments made on those statements (none is subtracted without
statement dates), never below 0. `available` is `limit` minus it, negative when
over the limit, null while `credit_limit` is not set. A credit card has no balance:
`create_account` refuses one for it and `create_balance` rejects the card.
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
     *
     * @api Read by Laravel MCP's Server when it builds the tools/list page,
     *      never by application code.
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
        ListAutomationRules::class,
        ListBalances::class,
        ListBudgets::class,
        ListCategories::class,
        ListLabels::class,
        ListRecurringSeries::class,
        ListSavingsGoals::class,
        ListSpaces::class,

        // Write
        CreateAccount::class,
        UpdateAccount::class,
        CreateTransaction::class,
        UpdateTransaction::class,
        DeleteTransaction::class,
        CategorizeTransaction::class,
        SplitTransaction::class,
        LabelTransaction::class,
        CreateBalance::class,
        DeleteBalance::class,
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
        UpdateBudgetPeriod::class,
        DeleteBudget::class,
        CreateSavingsGoal::class,
        UpdateSavingsGoal::class,
    ];
}
