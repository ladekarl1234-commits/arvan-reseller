# ADR-0007 — Append-only ledger; balances derived; uniqueness = idempotency

## Status
Accepted

## Context
Customer money must survive races (duplicate callbacks, concurrent syncs, admin actions) and stay auditable. A mutable `balance` column is the classic corruption point.

## Decision Drivers
Replay-safe crediting/debiting · auditability · reconciliation across customers · simple mental model for future engineers.

## Options Considered
1. Mutable balance column + transaction log
2. **Append-only single-table ledger, derived balances**
3. Full double-entry (accounts, journals, postings)

## Decision
Option 2, double-entry-inspired: every row has type/direction/amount/ref; `UNIQUE(ref_type, ref_id, type)` + `INSERT IGNORE` makes each business event creditable exactly once regardless of concurrency; `available/reserved/consumed/topup_total` are derived (pure `Ledger::derive`, unit-tested). A purchase settles as a `payment` credit + `purchase` debit pair on the same `payment_ref`. No code path deletes or mutates ledger rows.

## Why
Option 1 re-creates the race we must kill; option 3 (chart of accounts) models the reseller's own books — valuable someday, overkill for per-customer wallets now. Option 2 delivers double-entry's replay/audit benefits at single-table cost, and its rows can be re-posted into a real accounting system later.

## Consequences
Easier: HC-7 proof, reconciliation view, "why is my balance X" answerable from rows. Harder: reads aggregate per request (fine at reseller scale; rollup path in SCALABILITY).

## Risks
Balance query cost at extreme row counts → periodic checkpoint rows (documented, not built — YAGNI).

## Revisit Trigger
>10⁶ ledger rows per customer-set, or the reseller needs statutory accounting exports.
