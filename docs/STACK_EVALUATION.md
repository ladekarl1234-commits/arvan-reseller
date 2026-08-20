# Stack Evaluation

Written **before** implementation was finalized, per the engineering addendum. Every choice below is judged against this project's actual constraints — standalone WP plugin, financial correctness, Persian RTL UI, hackathon delivery window, future commercial potential — not against fashion.

## Constraints that drive everything

1. Must install on an arbitrary WordPress host (shared hosting included) with no Node/Composer at runtime.
2. Financial rows (ledger, orders, usage) need uniqueness constraints, indexes and aggregates.
3. External APIs (Arvan, gateway) fail; business-critical operations need durable retry.
4. One developer, hackathon timeline; future engineers must onboard fast.
5. RTL Persian UI with a design-system look, at small bundle size.

## 1. Backend architecture

| Option | Verdict |
|---|---|
| Procedural WP plugin (hooks + functions) | Rejected: financial invariants (state machine, ledger idempotency) need cohesive modules and testable pure logic. |
| **OO modular monolith, thin WP adapter layer** | **Selected.** Domain modules (`Pricing`, `Orders`, `Wallet`, `Policies`) are pure or near-pure PHP; WordPress touches the edges (hooks, `$wpdb`, REST). |
| Full hexagonal (ports/adapters + DI container) | Rejected: repository interfaces + container for a single-deployment plugin is abstraction theater; the two boundaries that genuinely vary (Arvan, payments) DO get interfaces. |
| WP plugin + external backend service | Rejected: violates "standalone installable" hard constraint; doubles ops burden. |
| Microservices | Rejected: nothing here justifies a network hop; see SCALABILITY for the extraction seams kept open. |

## 2. Frontend architecture — the decision matrix

Candidates: (A) server-rendered PHP + vanilla JS/CSS, (B) React + TypeScript via `@wordpress/scripts`, (C) Vue 3 + Vite, (D) Vanilla TypeScript + Vite build.

Weights per the addendum (adjusted +2 to WordPress compatibility from Developer availability, justification: the single highest-risk failure mode for this deliverable is "doesn't run on the judge's WordPress").

| Criterion (weight) | A: SSR PHP + vanilla | B: React+TS | C: Vue | D: Vanilla TS |
|---|---|---|---|---|
| WP compatibility (17) | **10** — no build, no version drift | 7 — wp-scripts solid but React 18/19 drift inside wp-admin is real | 6 — second framework in admin | 8 — build output only |
| Security (15) | **9** — escaping at sink, WP nonce model native | 8 | 8 | 8 |
| Maintainability (12) | **9** — one language, templates next to services | 7 — two toolchains | 6 | 7 |
| Scalability of UI complexity (12) | 6 — SPA-grade interactivity would get painful | **9** | 8 | 7 |
| Dev speed for THIS scope (10) | **9** — pages are forms + tables | 6 — component/bundle setup tax | 6 | 7 |
| Testability (10) | 7 — server logic unit-tested; JS thin enough to review | **9** — RTL/jest ecosystem | 8 | 8 |
| Operational simplicity (8) | **10** — zero build at install AND develop | 6 | 6 | 7 |
| Performance (7) | **9** — ~9 KB JS, server-rendered | 6 — 45 KB+ react-dom before app code | 7 | 8 |
| Developer availability (3) | 8 — any WP dev | **9** | 7 | 7 |
| Bundle/runtime footprint (3) | **10** | 5 | 6 | 8 |
| Future extensibility (3) | 7 — REST API already exists for a future SPA | **9** | 8 | 7 |
| **Weighted total /1000** | **860** | 734 | 690 | 750 |

**Decision: A — server-rendered PHP + vanilla JS/CSS.**

Why the matrix is honest: B genuinely wins on "UI complexity ceiling" and "testability", and if this product grows a live-updating operations console, the REST API (`arvan-reseller/v1`) is already the seam where a React admin can attach without touching the domain. For the actual scope — configuration forms, catalog cards, tables, one payment flow — an SPA buys nothing the judges or resellers would see, and costs a build pipeline, a Node dependency chain and RTL wrangling in a component library. Uncertainty: if two candidates had landed within ~5%, we would have prototyped both; 860 vs 750 is not close.

Full rationale: [ADR-0002](adr/0002-frontend-stack.md).

## 3. Persistence

| Option | Verdict |
|---|---|
| `wp_options` | Config only (single `arvrs_settings` array + tiny stores). Serialized blobs cannot index or constrain financial rows. |
| User meta | Identity extras only (`arvrs_policy_stage`). Meta queries for ledger aggregation would be O(horrible). |
| CPT + post meta | Rejected for orders: no uniqueness constraints, JOIN-through-meta for every aggregate, `wp_posts` bloat, no `INSERT IGNORE` idempotency. |
| **11 custom tables** | **Selected** for credentials, orders (+events), services, ledger, usage, jobs, audit, notifications, rules, base costs. Real indexes, `UNIQUE` business keys (the core of replay-safety), fast aggregates. |
| External DB/service | Rejected: violates standalone constraint. |

Schema + index rationale: [DATA_MODEL.md](DATA_MODEL.md), [ADR-0003](adr/0003-database-strategy.md).

## 4. Background processing

| Option | Verdict |
|---|---|
| Synchronous only | Rejected: a provisioning timeout would strand paid orders. |
| WP-Cron alone (scheduled events as state) | Rejected: events are lost on plugin edge cases; no retry/backoff/attempts semantics; invisible to admins. |
| **Durable jobs table + WP-Cron runner (+ inline first attempt)** | **Selected.** Payment→provision runs inline for instant UX; the pre-enqueued job is the crash-safety net; the table carries attempts/backoff/dead-letter; "Run now" makes it demoable. |
| Action Scheduler (bundled) | Seriously considered — proven at WooCommerce scale. Rejected for footprint (≈13k LOC dependency) versus our ~200-line runner covering the exact semantics needed. Revisit trigger in ADR-0004. |
| Real server cron / external queue | Documented as the production scaling path — zero code change needed (same runner, better trigger). |

## 5. Arvan API architecture

Two extremes rejected: raw `wp_remote_*` calls in controllers (untestable, unswappable), and a generated OpenAPI client per product (thousands of lines, mostly unused, drift risk since Arvan's ECC spec itself drifts).

**Selected:** one `ArvanClient` (HTTP concerns: auth header, timeouts, bounded retries, error normalization, correlation IDs, redacted logging) + one `ProviderInterface` per concern-set with exactly two implementations (`RealProvider`, `DemoProvider`) + small DTOs (`Plan`, `RemoteResource`, `UsageRow`). The interface is the demo-mode seam, the test seam, and the future multi-cloud seam simultaneously — one abstraction, three jobs. [ADR-0005](adr/0005-arvan-api-boundary.md).

## 6. Quality tooling

- **PHPUnit 12** for pure-domain units (46 tests) with a 100-line WP shim — no WP install needed in CI.
- **wp-cli + SQLite integration** for a full end-to-end run in development (see TESTING.md) — chosen over Docker because judges/devs on any OS can run it with PHP alone; wp-env remains documented for those with Docker.
- **GitHub Actions**: syntax lint on PHP 7.4/8.2/8.3 (7.4 = minimum runtime), unit tests on 8.2/8.3, secret scan.
- PHPCS/WPCS: deliberately not gating the hackathon CI (rule-noise vs value under deadline); config recommended in ROADMAP.

## Dependency discipline

Runtime Composer dependencies: **zero**. Bundled assets: Vazirmatn (SIL OFL, 150 KB, 3 weights) — required because Sorkhab's canonical Persian font is commercial. Dev-only: PHPUnit. That is the entire third-party surface; nothing to audit, nothing to abandon.
