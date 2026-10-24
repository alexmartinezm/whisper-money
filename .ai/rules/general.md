---
paths:
  - '**'
---

# General

## Upstream sync: changes this fork never takes
Sync with whisper-money/whisper-money by `cherry-pick -x` per upstream PR, in upstream merge order; the PR body lists taken / adapted / not taken. Never take: pricing tiers, trial, price-increase mails and billing UI; the ChatGPT reviewer account and MCP "overwrites are destructive" (#1065, #1130: here only irreversible tools are destructive); achievements; the notification bell (#924); the docs site (`resources/docs`, `public/docs`) and plugin docs; the landing video; FUNDING; upstream's new onboarding (#884, #981, #1000, #1119, #1120) and what is built only on it, such as the BalanceLookup walk-back (#1158); upstream's in-context split UI (#1047); the monthly summary email (#1085); `database/schema/mysql-schema.sql` and `tests/.pest/shards.json`; client-side `betaUntil` (beta providers live in `config/banking.php` `beta_banks`); TypeSafe routing and `JevCategorizationBackend` changes; legacy-encryption removal. Privacy copy or privacy-policy changes (#1150, #1151) need the owner's OK first.

## Upstream sync: fixed adaptation points
Port upstream changes onto the fork's own model; never take these files from upstream. Splits are `TransactionSplit` lines (`Transaction::splits()`, `effectiveTransactions()`), never `split_parent_id`. Files that always need a hand port: `CashflowSummaryService`, `CashflowBlockQuery`, `BudgetTransactionService`, `BudgetManagementService` and `BudgetPeriod` (mutable tracking, one-off periods), `BalanceLookup` (carry-forward only), `HandleInertiaRequests` (fork props such as `betaProviders` and the flags), `WhisperMoneyServer.php`, `edit-transaction-dialog.tsx`, `transactions/index.tsx`, the dashboard cards and `recurring/*`. Keep the flags `TransactionSplitting`, `RecurringTransactions` and `CashflowAvailableFree`. A fork write on a watched table must bump `DataVersion` or be justified in `tests/Fixtures/data-version-guard-allowlist.json`.
