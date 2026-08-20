# Capacity Model

Lightweight, assumption-labelled load math used to validate indexes and job design. Nothing here is a measured benchmark unless marked **[measured]**; everything else is **[assumption]** chosen to be conservative for a mid-size Iranian reseller.

## 1. Representative reseller profile [assumption]

| Parameter | Value |
|---|---|
| Customers | 1,000 (Stage A ceiling) |
| Active services / customer | 2 → 2,000 services |
| Product mix | 60% cloud server, 25% storage, 15% CDN |
| Orders/day | 30 (plus 10 abandoned) |
| Payment callbacks/day | 40–80 (incl. duplicates/replays) |
| Top-ups/day | 50 |
| Usage sync cadence | hourly, closed periods only |

## 2. Derived volumes

| Table | Rows/day | Rows/year | Notes |
|---|---|---|---|
| `usage_records` | 2,000 × 24 = **48,000** | ≈17.5M | the only fast-growing table |
| `ledger` | 48,000 (usage) + ~200 (commerce) | ≈17.6M | 1:1 with usage rows by design |
| `orders` (+events ×4) | 40 / 160 | 14.6k / 58k | negligible |
| `jobs` | ~100 | 36k | `done` rows are prunable (roadmap: 30-day sweep) |
| `notifications` | ≤ customers × types / cooldown ≈ 200 | 73k | cooldown caps this |
| `audit_log` | ~300 | 110k | prunable by age |

Storage: usage+ledger row ≈ 250 B incl. index → **≈9 GB/year** at Stage A ceiling — within any managed MySQL. Stage B (10× customers) → 90 GB/year → monthly rollups before that point (SCALABILITY Stage C trigger).

## 3. Request rates

| Flow | Peak assumption | Served by |
|---|---|---|
| Storefront views | 10 req/s burst | cached catalog, no external HTTP [verified in code] |
| Checkout POST | 1/min | 3 indexed queries + 1 insert |
| Callback POST | bursts of duplicates | 1 SELECT + 1 atomic UPDATE + 2 INSERT IGNORE |
| Usage sync run | 2,000 services / hourly | grouped per product → ≤3 provider calls (demo) / N bucketed calls (future real API); 48k×(1 INSERT IGNORE + 1 ledger insert)/24 per run ≈ 4k writes/run |
| Arvan API calls | provisioning ≈ orders/day + sync | far below any plausible upstream limit; client retries bounded ×3 |

## 4. Peak jobs/minute

Worst case: 30 orders land in one hour → 30 `provision_order` jobs; runner batch = 5/min → ≤6 min drain with inline attempts already having handled the happy path. Acceptable; batch size is one constant if not.

## 5. What was actually measured [measured]

On the development sandbox (Windows, PHP 8.5 CLI, SQLite integration — weaker than any production MySQL):
- Full E2E scenario (42 checks: 3 orders, 2 payments + replays, 96 usage ingestions ×2 runs, policy staging, REST dispatches): **≈8 s** wall clock including WP bootstrap per `wp eval-file`.
- Unit suite: 46 tests / 158 assertions in **1.5 s**.
- Double usage-sync of 48 periods: second run ingests 0 rows (idempotency verified, not assumed).

These validate correctness at small scale; they are NOT throughput benchmarks. Production benchmarking belongs in `docs/performance/` once a MySQL environment is provisioned (methodology template included there).
