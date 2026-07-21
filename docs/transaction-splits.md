# Transaction splits

Transaction splits classify one transaction across multiple categories without creating additional ledger entries.

## Data model

`transactions` remains the account ledger and source of truth for amount, date, currency, account, description, notes, labels, and counterparties. `transaction_splits` stores only category postings: category, signed amount, and position.

A split transaction:

- has at least two non-zero lines;
- has lines with the same sign as the parent;
- has line amounts summing exactly to the parent amount in minor units;
- uses categories belonging to the same user and space;
- uses one category type across all lines (`expense` or `income`);
- has a null parent `category_id` and cleared AI/rule provenance.

Positive refunds may use expense categories. They reduce expense analytics and budget consumption.

## API contract

Pass `splits` when creating or updating a transaction:

```json
{
  "category_id": null,
  "splits": [
    { "category_id": "food-uuid", "amount": -6000 },
    { "category_id": "home-uuid", "amount": -4000 }
  ]
}
```

Omitting `splits` leaves existing lines unchanged. An empty array removes existing lines and requires an explicit valid `category_id`. One-line splits are rejected; use a normal category instead.

Responses and incremental transaction sync include ordered `splits`, `is_split`, and `split_count`.

## Financial behavior

- Account balances and bank synchronization always use the parent amount once.
- Category analytics use effective postings: the parent category/amount for a normal transaction, or split lines for a split transaction, never both.
- Transaction counts remain parent counts.
- Largest-expense results use the parent amount and display `Split` as the category.
- Category filters match parent and split categories while returning each parent once.
- Category-filtered analysis includes only matching postings.
- Category budgets consume matching split portions. Label matches consume the full parent and take precedence over category matches.
- Catch-all budgets consume unclaimed expense postings only.

## Write protections

Bulk category changes, automation rules, and AI categorization skip split transactions. MCP category and amount writes reject split transactions; MCP reads expose their lines. Notes and labels remain parent fields and may still be changed.

Bank importers do not create or modify splits. Deleting a parent cascades to its lines. Editing split lines touches the parent so incremental clients refresh their embedded split data.

## Deployment and rollback

Before deploying hardened split writes, run the read-only integrity gate:

```bash
php artisan transactions:audit-splits --json --fail-on-invalid
```

The JSON artifact contains only IDs, anomaly codes, and counts. Resolve every anomaly before deployment. Rebuild historical budget assignments and advance parent delta-sync cursors only after a clean audit:

```bash
# Required preview; performs no writes.
php artisan transactions:reconcile-splits

# Explicit, resumable execution in short chunks.
php artisan transactions:reconcile-splits --execute --chunk=200
```

Reconciliation never changes split line IDs, categories, amounts, positions, or timestamps. Re-running it is safe: budget rows use their existing unique identity and parent cursors advance once per execution.

The migration is additive and sparse; no backfill is required. Deploy backend and frontend together. After deployment, monitor for:

- split sums differing from parent amounts;
- parents having both `category_id` and split rows;
- budget totals failing to reconcile.

Code rollback is transparent only before split data exists. Flattening existing splits loses classification detail and must be an explicit operation.
