# ADR-0004 — Durable jobs table + WP-Cron runner, inline first attempt

## Status
Accepted

## Context
Provisioning and usage sync must survive crashes, retry with backoff, and be observable — on hosts where the only scheduler is traffic-triggered WP-Cron.

## Decision Drivers
Crash-safety of paid-but-unprovisioned orders · instant post-payment UX · shared-host installability · a scaling path that needs no rewrite.

## Options Considered
1. Synchronous only
2. WP-Cron scheduled events as the state
3. **Jobs table (status/attempts/run_at/backoff/dead-letter) + minutely WP-Cron runner + inline first attempt**
4. Action Scheduler (bundle)
5. External queue/worker

## Decision
Option 3. `PaymentService` enqueues `provision_order` BEFORE attempting it inline: the happy path is instant, the job is the net. Runner claims atomically (`UPDATE … WHERE status='pending'`), backs off 1/2/5/15/30 min, dead-letters after 5 attempts with admin notification, and exposes "Run now" + retry buttons.

## Why
WP-Cron's weakness (traffic-triggered) only delays execution when the durable state lives in a table — it can never lose it. Action Scheduler provides the same semantics at ~13k LOC; our runner is ~200 lines against the exact requirements, and its table schema is what an external worker would consume anyway.

## Consequences
Easier: observability (health page reads the table), production hardening (`DISABLE_WP_CRON` + real cron `*/1` — zero code change), later extraction (a worker polls the same table). Harder: sub-minute latency guarantees on idle sites (accepted: inline attempt covers the demo-critical path).

## Risks
Long-running jobs vs PHP max_execution_time. Batch size 5 keeps runs short. A worker that dies mid-claim (not a clean failure) used to strand the row in `running` forever with no operator path back — `JobRunner::reap_stale()` plus the System Health "release stranded jobs" action now close that; see `docs/RUNBOOK.md` § Jobs stranded in `running`.

## Revisit Trigger
>1000 jobs/hour or multi-worker competition → swap claim to `SELECT … FOR UPDATE SKIP LOCKED` semantics / Action Scheduler / external worker per SCALABILITY.
