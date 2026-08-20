# Performance evidence

Reproducible measurements only — no vague claims. What exists today, and the template for adding MySQL benchmarks.

## Measured on this build (dev sandbox)

Environment: Windows 11, PHP 8.5 CLI, WordPress latest + official SQLite integration (a *slower* substrate than production MySQL — treat these as upper bounds for correctness-path cost, not throughput claims).

| Operation | Result |
|---|---|
| Unit suite (46 tests / 158 assertions) | 1.5 s |
| Full E2E scenario (42 checks: license, 3 orders, payments + replays, 2×48-period usage sync, policy, REST dispatches) | ≈8 s incl. WP bootstrap |
| Usage re-sync of 48 already-ingested periods | 0 rows ingested (idempotency measured, not assumed) |
| Duplicate payment callback | 0 additional ledger rows / services (measured) |

## Adding a MySQL benchmark (template)

1. Environment: record host, PHP, MySQL, `innodb_buffer_pool_size`.
2. Dataset: seed via a loop over `tests/integration/e2e.php` primitives (N customers × M services × K usage days) — record N/M/K.
3. Operations to time (spec'd hot paths): order lookup by ref, customer service listing (paginated), ledger balance aggregate, usage `INSERT IGNORE` batch, duplicate-period re-run, job claim under 4 concurrent runners, admin customer list.
4. Method: ≥5 warm runs, report median + p95; `EXPLAIN` each query and attach.
5. Store results as a dated file here (`2026-xx-mysql8.md`); never overwrite old runs.
