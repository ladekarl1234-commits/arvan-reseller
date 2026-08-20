# Scalability

Concrete answer to "is this architecture scalable?" — stages, numbers, bottlenecks, and the exact triggers that demand change. Assumption labels refer to [CAPACITY_MODEL.md](CAPACITY_MODEL.md).

## Stage A — Hackathon / small reseller (now)

WordPress + PHP-FPM + MySQL/MariaDB, jobs table + WP-Cron, Arvan APIs. Zero extra infrastructure.

Comfort envelope (assumption-based, CAPACITY_MODEL §2): **≤1,000 customers, ≤2,000 active services, ≤50k usage rows/day** — every hot query is a single indexed lookup or a bounded aggregate.

### What scales already at Stage A
- Every FK/status/created_at column indexed; list endpoints paginate server-side.
- Idempotency via unique keys means retries add zero rows, so failure storms don't amplify writes.
- Catalog cached 6h; no external HTTP in any page render.
- Ledger balance = one indexed aggregate per customer (`customer_id` key).

## Stage B — Growing reseller (configuration, not code)

Apply in order as load appears:

1. **Real cron**: `define('DISABLE_WP_CRON', true)` + system cron `*/1 * * * * wp cron event run --due-now`. Removes traffic dependency for jobs/usage. (First thing any production install should do.)
2. **Persistent object cache** (Redis/Memcached drop-in): transients (catalog cache, rate-limit buckets) become memory-speed and cluster-wide automatically — the plugin uses only WP cache APIs.
3. **Batch tuning**: `JobRunner::BATCH` and usage-sync scope are single constants.
4. **DB**: verify `innodb_buffer_pool_size` covers the ledger+usage working set; add read replica for admin reporting if reports get heavy.
5. **Monitoring**: System Health page + `arvrs_jobs.status='dead'` count are the two numbers worth alerting on.

Envelope: **≈10,000 customers / 20,000 services / 500k usage rows/day** (A-3).

## Stage C — High volume (extraction along existing seams)

The modular monolith's seams are the service boundaries; both workers consume the SAME tables, so extraction adds processes, not rewrites:

```
WordPress plugin (UI, REST, admin)          Extracted later:
        │                                   ┌──────────────────────┐
        ├── arvrs_jobs table  ◄─────────────┤ Provisioning worker   │  claims jobs w/ SKIP LOCKED
        ├── arvrs_usage_records ◄───────────┤ Usage-ingestion worker│  writes periods + ledger debits
        └── REST (checkout/callback)        └──────────────────────┘
```

Candidates in extraction order: (1) usage ingestion (highest row volume), (2) provisioning worker (latency isolation from PHP-FPM), (3) notification delivery, (4) analytics rollups.

## Scale-axis analysis

| Axis | 100 | 1k | 10k | 100k customers |
|---|---|---|---|---|
| Customer lists/detail | trivial | trivial | fine (paginated, indexed) | fine; admin search should move to dedicated index columns |
| Ledger balance reads | trivial | trivial | fine (≤~10³ rows/customer aggregate) | add periodic checkpoint rows (documented in ADR-0007) |
| Usage rows | 2.4k/day | 24k/day | 240k/day | 2.4M/day → Stage C worker + monthly rollup/archival table |
| Concurrent callbacks | n/a | rare | atomic claims already correct; contention is row-level | same; DB write throughput is the ceiling, not the code |
| Credentials | 1 | few | tens — selection query trivial | hundreds — selection stays O(n) on an in-memory list; add caching if ever measured |

### API failure scale
Arvan degradation → `ProviderError` per call; ≤2 client retries with backoff; job retries at 1/2/5/15/30 min cap the retry amplification of N stuck orders at ~N×5 calls over an hour; dead-letter + admin notification stops infinite loops. Rate-limit (429) honours Retry-After ≤5s in-request, then defers to job backoff.

## Concurrency & state
- All coordination is database-atomic (single-row optimistic UPDATEs, unique keys) — **no PHP-process state**, so horizontal PHP scaling (more FPM workers/nodes) is free.
- Sessions are WordPress cookie auth — also horizontal-safe.
- The only per-node state is the transient rate limiter without an object cache (noted in SECURITY.md; fixed by Stage B step 2).

## What breaks first?

**Usage ingestion via WP-Cron PHP requests.** At ~20k services × hourly rows, `sync_all()` in a web-request context exceeds sane execution time before anything else hurts. It is also the least harmful failure: ingestion lags, nothing corrupts (idempotent periods), balances catch up on the next run.

## What we do next

Chunk `sync_all()` by service-ID ranges into multiple `usage_sync` jobs (the jobs table already supports it — ~20 lines), run under real cron. Cost: hours, not a redesign.

## When the architecture must change

Measured triggers, not vibes:
- `arvrs_jobs` pending backlog persistently > 500 or job wait p95 > 5 min → extract the provisioning worker (Stage C).
- Usage ingestion wall-clock > 60 s/run after chunking → extract the ingestion worker.
- Ledger aggregate p95 > 100 ms on hot paths → checkpoint rows.
- Admin reporting queries visible in slow-query log → read replica.

Until a trigger fires, adding infrastructure is cost without benefit.
