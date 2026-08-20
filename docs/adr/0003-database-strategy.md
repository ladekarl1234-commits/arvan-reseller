# ADR-0003 — Custom tables for transactional data; options/meta for config only

## Status
Accepted

## Context
Ledger entries, orders, usage records and jobs are high-integrity, query-heavy, potentially high-volume rows. WordPress offers options, meta, CPTs — or custom tables.

## Decision Drivers
Uniqueness constraints as the idempotency mechanism · indexed aggregates (balances, revenue) · volume (usage grows per service-hour) · migration control.

## Options Considered
1. `wp_options` / usermeta / CPT+meta for everything
2. **Custom tables (11) via dbDelta, versioned migrations**
3. External datastore

## Decision
Option 2: `credentials, orders, order_events, services, ledger, usage_records, jobs, audit_log, notifications, customer_rules, base_costs`, all prefixed `arvrs_`, every FK/status/created_at indexed, business-unique keys on `ledger(ref_type,ref_id,type)`, `usage(service,period)`, `services(order_id)`, `orders(payment_ref)`. `wp_options` keeps a single whitelisted settings array; usermeta keeps only the derived policy stage. Schema version in an option; `dbDelta` migrations are diff-based and idempotent.

## Why
The entire replay-safety story (HC-7) rests on database uniqueness + `INSERT IGNORE` — impossible in meta. Balance derivation is one indexed aggregate instead of unserializing rows in PHP.

## Consequences
Easier: correctness, reporting, pagination. Harder: we own migrations (versioned; `maybe_migrate` on boot covers updates), and multisite table-per-blog considerations (prefix-based, works per-site).

## Risks
`dbDelta` quirks on exotic MySQL forks. Mitigated by conservative column types and the SQLite-integration test run.

## Revisit Trigger
Usage ingestion beyond ~10⁷ rows/site → partitioning/rollups per SCALABILITY Stage C.
