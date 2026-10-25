---
paths:
  - 'app/Services/Cashflow*.php'
---

# Services

## Cashflow figures: SQL aggregates, split parents hydrated
Cashflow figures (summary, sankey, trend, breakdown, forMonths, the expense split) come from CashflowAggregateQuery: unsplit rows are summed in SQL; split parents are still hydrated and expanded by effectiveTransactions(), so line conversion, the residue on the last line and the owner's share stay exact. Groups merge in first-seen (transaction_date, id) order, compared as bytes. Budgets' label tracking needs the tracked label ids passed to the query (CashflowBudgetReserveService::trackedLabelIds()); stand-ins only carry those labels. Any change here must keep tests/Feature/CashflowEquivalenceTest.php and its golden file passing unchanged.
