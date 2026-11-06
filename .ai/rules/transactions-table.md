---
paths:
  - resources/js/pages/transactions/index.tsx
  - resources/js/components/transactions/transaction-list.tsx
  - resources/js/components/transactions/transaction-table.tsx
  - resources/js/components/transactions/transaction-columns.tsx
  - resources/js/components/transactions/transaction-table-row.tsx
  - resources/js/components/transactions/transaction-row-dialogs.tsx
  - resources/js/components/transactions/transaction-delete-dialogs.tsx
  - resources/js/components/transactions/transaction-filter-summary.tsx
  - resources/js/components/transactions/transaction-list-row.tsx
  - resources/js/components/transactions/transaction-day-header.tsx
  - resources/js/components/transactions/transaction-list-skeleton.tsx
  - resources/js/components/transactions/responsive-transaction-table.tsx
  - resources/js/hooks/use-transaction-row-editing.ts
  - resources/js/hooks/use-transaction-bulk-actions.ts
  - resources/js/hooks/use-in-memory-transaction-source.ts
  - resources/js/hooks/use-server-transaction-source.ts
  - resources/js/types/transaction-table-source.ts
  - resources/js/lib/compact-list-style.ts
  - resources/js/lib/transaction-day-groups.ts
  - app/Services/TransactionDayTotals.php
  - app/Services/TransactionFilterSummary.php
---

# Transactions table

## One table, two sources
Every list of transactions is TransactionTable: rows with their menus and dialogs, selection, bulk actions, day headers, table or compact list, skeleton, "load more". Where the rows come from is a TransactionTableSource: useServerTransactionSource (/transactions: filters in the URL, cursor pages, day figures from the server, moved only by local edits; only it has matchingFilters and refreshRows) or useInMemoryTransactionSource (TransactionList on the account, budget and savings goal pages: rows the page holds, filtered, ordered and sliced in the browser). Both answer countMatching(filters, signal), the "See N transactions" of the phone's sheet: rows the list would show, never the export's count, which is per split line. The server one asks api/transactions/listed-count, which reads no total and no rate, so a count on every pause never reaches the rates provider. A page-only difference is a prop, not a branch on the source. Rows are DecryptedTransaction here. A row action goes in getTransactionRowActions() and useTransactionRowEditing, never inline in a menu; there is no split or merge row action, a split is edited from the edit dialog. On a phone the compact list has no row menu, so the edit dialog's "More actions" offers the same actions, Edit aside.

## Split transactions are lines, not rows
A split is TransactionSplit lines on one transaction, never child rows (upstream's split_parent_id, isSplitPart and split parts do not exist here). It is one row of the list and counts once, however many lines match. Under a filter that names a category, a category type, a label or a cashflow block it adds only the lines that filter keeps (owner decision D3): TransactionDayTotals, TransactionFilterSummary, the CSV and the analysis drawer must agree, so the narrowing lives once, in TransactionCsvExporter::constrainLines() and postings(), and TransactionDayTotals::narrowedSplitTransactions() is how a figure reads it. A label keeps every line, labels living on the transaction; a type then narrows them further. A split listed for its own category_id with no matching line counts and adds 0. A split matches a type through its own category or any line. Without those filters it stands at its own amount, in the one grouped query. createRowTotalAmount() is the client's twin for local edits and the in-memory source: change them together. Under a cashflow block the page cannot value a split, so a local edit leaves that day's total out until the next load.

## Day totals and summaries: one bounded attempt at the provider
Each currency is summed per day, then converted once: never row by row, the rounding drifts a cent from the server's figure. Amounts in another currency go through ExchangeRateService::convertManyOrNull(), never convert() or the default path: it reads stored rates in one query, asks the provider one date at a time, stops at the first failure or once the budget is spent, and leaves the rest null. A day with no rate shows no total rather than a figure that skipped it, and a summary with such a day has no total or average. The default path's walk, its cache and its TTLs stay as they are, so nothing a failed attempt learned reaches them. A summary pays one attempt for the total and one for the trend. Anything new that shows a converted figure while someone waits takes the same path.

## Always newest first, always by day
There is no sort: transaction_date desc then id desc (sortedForList(), compareNewestFirst in memory), under a header per day, with no Date column. The server ignores a sort query param, and IndexTransactionRequest::listCursor() reads a bookmarked cursor from a sort that is gone as the first page, so old URLs still load. Do not bring back sortable headings, a sort menu or a flat list without that being decided.

## Filters
Desktop is the chips toolbar, applied at once; a phone is the search, Filters and the full-screen sheet, whose edits stay a draft until "See N transactions". A facet lives in lib/transaction-filter-facets.ts, FACET_MENU and the sheet, in both or neither. The cashflow block only exists over a whole date range and drops with its dates (withoutDatelessCashflowBlock). The category filter reaches subcategories and split lines through the fork's own lib/transaction-category-filter.ts, on both sources. New filter fields default when read: a saved filter or a page in the browser's history may predate them, and filtersFingerprint() adds a field only when set, because stored overrides key on it.

## Compact list and React Compiler
Below md the list is design D's frame, 16px from the screen's edges (PHONE_FRAME and PHONE_INSET in lib/compact-list-style.ts). They pull it into a p-6 page, so a table in a card or a dialog passes framedOnPhone. There the list never scrolls inside its own maxHeight box. Colours and sizes come from that file: check a change at 390px, light and dark. DataTable and the compact list in ResponsiveTransactionTable opt out of the compiler ('use no memo') because they read stable objects that change underneath; hand them state as React state. TransactionTable is compiled, so it never hands the TanStack table to a page: give the page what it needs ready-made, as columnPicker.
