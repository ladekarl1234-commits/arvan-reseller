# Issue Backlog — Expert Panel Findings

Every finding raised by the [expert evaluation panel](../EXPERT_REVIEW.md), with the evidence that proves it and the fix that closes it. Ordered by severity, then by the weight of the dimension that raised it. IDs are stable — cite them in commits and pull requests.

**Status legend:** `open` — not yet addressed · `accepted-risk` — understood and deliberately not fixed, with a reason · `fixed` — closed, with the evidence that verifies it (not merely claimed).

**v1.1.0 remediation round:** 37 of 141 findings verified fixed against the current source (each carries a **Closed** note with the evidence). This is not a full re-review — the remaining 104 are unchanged from the original panel pass and have not been individually re-checked; treat their `open` status as "not verified in this round," not as "confirmed still broken." `EX-050` was investigated and is deliberately left `open` rather than marked fixed: the documentation now states the real precondition, but the underlying code gap (no detection of DB-stored vs. file-defined WordPress salts) is unaddressed.

| Total | Fixed | Accepted-risk | Open |
|---:|---:|---:|---:|
| 141 | 37 | 0 | 104 |

By severity (unchanged by status — a fix does not change how severe the original finding was):

| Critical | High | Medium | Low |
|---:|---:|---:|---:|
| 6 | 43 | 64 | 28 |

## Index

| ID | Sev | Finding | Dimension | Effort | Status |
|---|---|---|---|---|---|
| [EX-001](#ex-001) | 🔴 critical | Non-idempotent POSTs are auto-retried on timeout while the provider ignores the idempotency key — duplicate paid-for remote resources | Reliability | M | `fixed` |
| [EX-002](#ex-002) | 🔴 critical | Payment page tells the customer the service is ready even when provisioning failed | UX & usability | M | `fixed` |
| [EX-003](#ex-003) | 🔴 critical | Ledger::balance() fetches every ledger row for a customer into PHP, on every front-end page render | Scalability | S | `fixed` |
| [EX-004](#ex-004) | 🔴 critical | No renewal or recurring billing anywhere — a 'monthly package' is charged exactly once, forever | Business viability | L | `fixed` |
| [EX-005](#ex-005) | 🔴 critical | The entire wallet/usage/credit-policy subsystem is dead code in real mode | Business viability | L | `fixed` |
| [EX-006](#ex-006) | 🔴 critical | ArvanClient retries non-idempotent POSTs on timeout/5xx, defeating the documented single-invocation guarantee | Integration honesty | S | `fixed` |
| [EX-007](#ex-007) | 🟠 high | RealProvider discards the idempotency key while ArvanClient blindly retries POSTs — duplicate billable upstream resources | Security | M | `fixed` |
| [EX-008](#ex-008) | 🟠 high | Durable job queue has no reaper: a crashed worker strands a job in 'running' forever | Architecture | S | `fixed` |
| [EX-009](#ex-009) | 🟠 high | Orders can become permanently stuck in 'provisioning' with an orphaned upstream resource | Architecture | M | `fixed` |
| [EX-010](#ex-010) | 🟠 high | Up to 15s of blocking sleep plus synchronous email inside the payment callback request | Architecture | M | `open` |
| [EX-011](#ex-011) | 🟠 high | Leaving Demo Mode disables all selling — checkout, top-up and the payment page all hard-fail with no admin warning | Product completeness | M | `open` |
| [EX-012](#ex-012) | 🟠 high | Real-mode Cloud Server storefront renders empty: API flavor IDs have no base-cost rows and nothing detects it | Product completeness | M | `open` |
| [EX-013](#ex-013) | 🟠 high | An order stuck in `provisioning` is unrecoverable: no admin action exists and the retry job reports success | Reliability | M | `fixed` |
| [EX-014](#ex-014) | 🟠 high | Jobs left in `running` are never reclaimed — no reaper, not selectable, not retryable | Reliability | S | `fixed` |
| [EX-015](#ex-015) | 🟠 high | Ledger write failure on a settled payment is swallowed with no repair path — money with no record | Reliability | M | `open` |
| [EX-016](#ex-016) | 🟠 high | Every admin alert is written to the DB and rendered nowhere — $notices is dead | UX & usability | S | `fixed` |
| [EX-017](#ex-017) | 🟠 high | Mid-payment network error strands the customer on a permanent spinner with both buttons disabled | UX & usability | S | `open` |
| [EX-018](#ex-018) | 🟠 high | Abandoned payment is a dead end: the pending-orders page is unreachable from the UI | UX & usability | S | `open` |
| [EX-019](#ex-019) | 🟠 high | Order-payment replay test short-circuits before the DB idempotency guard it claims to prove | Testing & QA | S | `open` |
| [EX-020](#ex-020) | 🟠 high | sandbox_blocked() — the guard that stops a self-verifiable sandbox proof settling real money — has zero coverage | Testing & QA | S | `open` |
| [EX-021](#ex-021) | 🟠 high | The only real data migration (v3→v4 ledger is_demo back-stamp) is unreachable from every test | Testing & QA | M | `open` |
| [EX-022](#ex-022) | 🟠 high | Two of the five License tests assert nothing about the license code; one is named for an invariant it never exercises | Testing & QA | M | `open` |
| [EX-023](#ex-023) | 🟠 high | Job runner decides retry-vs-success by substring-matching English prose from Provisioner | Code quality | S | `fixed` |
| [EX-024](#ex-024) | 🟠 high | Admin alerts are write-only: notifications are stored, passed to the dashboard, and never rendered | Operational readiness | S | `fixed` |
| [EX-025](#ex-025) | 🟠 high | Jobs stranded in `running` are invisible and unrecoverable from the UI | Operational readiness | S | `fixed` |
| [EX-026](#ex-026) | 🟠 high | Credential health is manual-only; a revoked token keeps showing "connected" until someone clicks Test | Operational readiness | M | `open` |
| [EX-027](#ex-027) | 🟠 high | Admin customer list N+1s the unbounded balance query — 20 full ledger scans per page render | Scalability | S | `fixed` |
| [EX-028](#ex-028) | 🟠 high | Catalog cache miss makes a blocking upstream call inside page render, with no negative caching and no stampede guard | Scalability | S | `fixed` |
| [EX-029](#ex-029) | 🟠 high | Hourly usage sync rescans the entire ledger twice — the real first bottleneck, and not the one the docs name | Scalability | S | `open` |
| [EX-030](#ex-030) | 🟠 high | sync_all() is unbounded and unchunked in one request; the demo provider materialises every usage row in memory first | Scalability | M | `open` |
| [EX-031](#ex-031) | 🟠 high | Usage debits are posted at raw upstream cost — the metered revenue path earns zero margin | Business viability | S | `open` |
| [EX-032](#ex-032) | 🟠 high | Real-mode cloud-server plans are unsellable out of the box; the wizard reports pricing as complete anyway | Business viability | M | `open` |
| [EX-033](#ex-033) | 🟠 high | Reported margin is notional — nothing reconciles it against what the reseller actually owes ArvanCloud | Business viability | M | `open` |
| [EX-034](#ex-034) | 🟠 high | Brand-color token does not propagate to the surfaces that carry the brand | Visual design | M | `open` |
| [EX-035](#ex-035) | 🟠 high | Token scale defined then bypassed: grad-dark unused while its exact value is inlined twice; 32 literal radii vs 3 token uses | Visual design | M | `open` |
| [EX-036](#ex-036) | 🟠 high | Three of five semantic status pills fail WCAG AA contrast at 11.5px bold | Visual design | S | `open` |
| [EX-037](#ex-037) | 🟠 high | Admin declares Vazirmatn but never loads it - every admin page falls back to system fonts | Visual design | S | `open` |
| [EX-038](#ex-038) | 🟠 high | Customer wallet balance and ledger history ignore `is_demo` — demo money counts as real | Data & analytics | S | `fixed` |
| [EX-039](#ex-039) | 🟠 high | `negative_since_days` measures the last debit, not the start of the negative period — RESTRICTED is unreachable | Data & analytics | M | `fixed` |
| [EX-040](#ex-040) | 🟠 high | Derived balance pulls the whole ledger into PHP, and the customers list does it N+1 times | Data & analytics | M | `open` |
| [EX-041](#ex-041) | 🟠 high | No time dimension in any report — revenue, cost and margin are lifetime sums only | Data & analytics | M | `fixed` |
| [EX-042](#ex-042) | 🟠 high | Real-mode cloud-server plans are keyed by upstream flavor IDs, but BaseCosts is seeded only with demo plan IDs — the headline product is unsellable in real mode | Integration honesty | M | `open` |
| [EX-043](#ex-043) | 🟠 high | Inline provisioning with up to 15 s of sleep() in the payment callback can strand an order in `provisioning` with a live upstream resource | Integration honesty | M | `open` |
| [EX-044](#ex-044) | 🟠 high | Customer-supplied region/image are never validated against the offered catalog, and flavor IDs are region-scoped | Integration honesty | S | `open` |
| [EX-045](#ex-045) | 🟠 high | The headline E2E check count is stated as two different numbers across nine documents, and neither matches the code | Documentation | S | `fixed` |
| [EX-046](#ex-046) | 🟠 high | Traceability matrix contains three provable errors, including a claim the code explicitly refutes | Documentation | S | `fixed` |
| [EX-047](#ex-047) | 🟠 high | No admin form field is label-associated: `<label>` and `<input>` are siblings with no `for` | Accessibility & i18n | M | `open` |
| [EX-048](#ex-048) | 🟠 high | Shared status-tag palette fails WCAG AA in both stylesheets — success pill is 2.89:1 | Accessibility & i18n | S | `open` |
| [EX-049](#ex-049) | 🟠 high | Plugin never emits `lang`, and `dir="rtl"` is hardcoded — translation cannot change direction | Accessibility & i18n | S | `open` |
| [EX-050](#ex-050) | 🟡 medium | THREAT_MODEL S5 "DB dump alone is useless" is false on any install without salt constants in wp-config.php | Security | S | `open` |
| [EX-051](#ex-051) | 🟡 medium | POST /me/topup is unrate-limited and writes a permanent wp_options row per call | Security | S | `open` |
| [EX-052](#ex-052) | 🟡 medium | Refund credits the wallet without confirming the original payment was ever ledgered | Security | S | `open` |
| [EX-053](#ex-053) | 🟡 medium | Two SECURITY.md control claims are not true of the code (get_owned has no production callers; not every route has an args schema) | Security | S | `fixed` |
| [EX-054](#ex-054) | 🟡 medium | Composition root is a service locator that seven modules depend on, creating the cycles ARCHITECTURE.md denies | Architecture | M | `fixed` |
| [EX-055](#ex-055) | 🟡 medium | Presentation layer runs raw SQL against another module's table to fetch an encrypted secret | Architecture | S | `open` |
| [EX-056](#ex-056) | 🟡 medium | Jobs (Infrastructure) hard-codes dispatch into Application modules, inverting the declared layer direction | Architecture | S | `open` |
| [EX-057](#ex-057) | 🟡 medium | Demo-mode check issues an uncached DB query on every ledger write and catalog read | Architecture | S | `open` |
| [EX-058](#ex-058) | 🟡 medium | `credit_limit` is an admin form field that is persisted and never read by any decision path | Product completeness | S | `open` |
| [EX-059](#ex-059) | 🟡 medium | Per-product credential routing can hand a product-restricted credential to the wrong product | Product completeness | S | `open` |
| [EX-060](#ex-060) | 🟡 medium | No service-termination or status-refresh path exists; `ProviderInterface::delete()`/`status()` are dead code | Product completeness | M | `open` |
| [EX-061](#ex-061) | 🟡 medium | Provisioner ignores the service-insert result and transitions to ACTIVE regardless | Reliability | S | `open` |
| [EX-062](#ex-062) | 🟡 medium | A failed claim is always reported to the caller as a successful replay, including on amount mismatch | Reliability | S | `open` |
| [EX-063](#ex-063) | 🟡 medium | The entire idempotency model rests on unique indexes that migration never verifies, plus an unguarded full-table backfill | Reliability | M | `open` |
| [EX-064](#ex-064) | 🟡 medium | Payment callbacks are rate-limited per client IP at 30/5min — a real gateway is one IP | Reliability | S | `open` |
| [EX-065](#ex-065) | 🟡 medium | Any logged-in non-customer (the reseller previewing their own store) is trapped in an auth → dashboard → auth loop | UX & usability | S | `open` |
| [EX-066](#ex-066) | 🟡 medium | No password reset anywhere, and a failed registration returns the user to the login tab with all fields lost | UX & usability | S | `open` |
| [EX-067](#ex-067) | 🟡 medium | All dates shown to Persian users are raw Gregorian UTC strings; usage periods are rendered by substring surgery | UX & usability | M | `open` |
| [EX-068](#ex-068) | 🟡 medium | The post-purchase service card shows raw English snake_case keys as labels | UX & usability | S | `open` |
| [EX-069](#ex-069) | 🟡 medium | Product navigation vanishes on phones with no replacement | UX & usability | S | `open` |
| [EX-070](#ex-070) | 🟡 medium | Three E2E checks cannot fail or assert something weaker than their own label | Testing & QA | S | `open` |
| [EX-071](#ex-071) | 🟡 medium | No negative authentication or authorization tests anywhere: isolation is only ever tested between two logged-in customers | Testing & QA | M | `open` |
| [EX-072](#ex-072) | 🟡 medium | SQLite-only integration structurally hides the exact MySQL failure mode Ledger::append is written to defend against | Testing & QA | M | `open` |
| [EX-073](#ex-073) | 🟡 medium | Zero concurrency tests for a design whose entire correctness argument is about races | Testing & QA | M | `open` |
| [EX-074](#ex-074) | 🟡 medium | JobRunner (durable queue, backoff, dead-lettering) is untested, and the enqueue+inline double-provision path is never exercised | Testing & QA | M | `open` |
| [EX-075](#ex-075) | 🟡 medium | Default brand color is defined six times with two different values | Code quality | S | `open` |
| [EX-076](#ex-076) | 🟡 medium | ~100 lines of dead provider and helper surface that must still be maintained and reviewed | Code quality | M | `open` |
| [EX-077](#ex-077) | 🟡 medium | Credential selection has a redundant branch that defeats its own stated preference | Code quality | S | `open` |
| [EX-078](#ex-078) | 🟡 medium | No automated test covers any `$wpdb` path — the money and idempotency code has no regression net | Code quality | L | `open` |
| [EX-079](#ex-079) | 🟡 medium | Blocking sleeps inside the synchronous checkout/callback request path | Code quality | S | `open` |
| [EX-080](#ex-080) | 🟡 medium | Adding a product means editing ~10 sites, including hard-coded product lists that bypass Catalog::PRODUCTS | Code quality | M | `open` |
| [EX-081](#ex-081) | 🟡 medium | The audit log cannot be investigated: no filter by object/user/date, fixed 100 rows, no export, no index, no retention | Operational readiness | M | `open` |
| [EX-082](#ex-082) | 🟡 medium | Correlation-ID trace breaks at the order boundary; successful upstream calls are never logged | Operational readiness | S | `open` |
| [EX-083](#ex-083) | 🟡 medium | No order lookup by payment reference or ID — the most common support entry point | Operational readiness | S | `open` |
| [EX-084](#ex-084) | 🟡 medium | Dead-job list shows no payload, no order link, and truncates the error to 12 words with no detail view | Operational readiness | S | `open` |
| [EX-085](#ex-085) | 🟡 medium | Services page is entirely read-only — no resync, suspend, or reconcile against upstream state | Operational readiness | M | `open` |
| [EX-086](#ex-086) | 🟡 medium | Jobs stuck in 'running' are never reclaimed and never surfaced; claimed_at is written but never read | Scalability | S | `open` |
| [EX-087](#ex-087) | 🟡 medium | Admin dashboard runs six uncached full-table aggregates per render, including two whole-ledger scans and count_users() | Scalability | S | `open` |
| [EX-088](#ex-088) | 🟡 medium | Pricing N+1: one customer_rules query per plan, repeated for every plan on every storefront and product render | Scalability | S | `open` |
| [EX-089](#ex-089) | 🟡 medium | Plugin::demo_mode() runs an uncached credentials query per call, including once per ledger row during ingestion | Scalability | S | `open` |
| [EX-090](#ex-090) | 🟡 medium | Financial reporting is lifetime-cumulative only — no MRR, no period, no churn | Business viability | M | `open` |
| [EX-091](#ex-091) | 🟡 medium | Two of three products still require manual reseller intervention to deliver credentials | Business viability | M | `open` |
| [EX-092](#ex-092) | 🟡 medium | Global .arvrs-card margin-bottom fights every flex/grid container, so declared gaps are never the real gaps | Visual design | S | `open` |
| [EX-093](#ex-093) | 🟡 medium | Three different page gutters at 390px because the shared container primitive is dead code | Visual design | S | `open` |
| [EX-094](#ex-094) | 🟡 medium | No mobile navigation, and the fixed-height header overflows in the 640-760px band | Visual design | M | `open` |
| [EX-095](#ex-095) | 🟡 medium | Decorative overlay paints on top of hero and auth content because of stacking order | Visual design | S | `open` |
| [EX-096](#ex-096) | 🟡 medium | Semantic color misused for a positive claim, and .arvrs-alert-body markup differs across four call sites | Visual design | S | `open` |
| [EX-097](#ex-097) | 🟡 medium | Declared weight hierarchy is fictional, and 49 inline styles carry four off-palette hexes past existing tokens | Visual design | M | `open` |
| [EX-098](#ex-098) | 🟡 medium | Index set does not match the query set: `audit_log.level` unindexed, four declared indexes unused | Data & analytics | S | `open` |
| [EX-099](#ex-099) | 🟡 medium | `usage_records` carries neither `is_demo` nor a cost/price split — the recurring stream has no reportable margin | Data & analytics | M | `fixed` |
| [EX-100](#ex-100) | 🟡 medium | Four unbounded tables with no retention or pruning path | Data & analytics | M | `open` |
| [EX-101](#ex-101) | 🟡 medium | Dashboard order counts skip the demo filter applied two lines above them | Data & analytics | S | `open` |
| [EX-102](#ex-102) | 🟡 medium | Object Storage region choice is advertised by RealProvider but silently discarded before it reaches the API | Integration honesty | S | `open` |
| [EX-103](#ex-103) | 🟡 medium | Zero test coverage of the real integration path — every test runs through DemoProvider | Integration honesty | M | `open` |
| [EX-104](#ex-104) | 🟡 medium | docs/API_INTEGRATION.md claims 402 error semantics are handled; ArvanClient has no 402 branch and classifies it as retryable | Integration honesty | S | `fixed` |
| [EX-105](#ex-105) | 🟡 medium | "No endpoint in this plugin is invented" is asserted absolutely but is unverifiable from the artifact, and at least one endpoint/field shape looks unusual | Integration honesty | M | `fixed` |
| [EX-106](#ex-106) | 🟡 medium | spec.md — the self-declared source of truth — has drifted from the code in five places | Documentation | S | `fixed` |
| [EX-107](#ex-107) | 🟡 medium | README's stack table and project tree name classes and modules that do not match src/ | Documentation | S | `fixed` |
| [EX-108](#ex-108) | 🟡 medium | No troubleshooting/runbook, and the E2E script's fresh-database requirement has no documented reset step | Documentation | S | `fixed` |
| [EX-109](#ex-109) | 🟡 medium | Customer-facing ArvanCloud error messages bypass `__()` entirely | Accessibility & i18n | S | `open` |
| [EX-110](#ex-110) | 🟡 medium | `Domain Path: /languages` points at a directory that does not exist; no .pot ships | Accessibility & i18n | S | `open` |
| [EX-111](#ex-111) | 🟡 medium | White-on-teal gradients that ignore the brand variable fail AA — hero and stat text at ~2:1 | Accessibility & i18n | M | `open` |
| [EX-112](#ex-112) | 🟡 medium | Payment success destroys keyboard focus and relies on reveal-to-announce live regions | Accessibility & i18n | M | `open` |
| [EX-113](#ex-113) | 🟡 medium | Brand color is user-settable to any hex with no contrast guard, and drives all CTA/focus colors | Accessibility & i18n | M | `open` |
| [EX-114](#ex-114) | ⚪ low | Public registration silently overrides the site's membership setting and leaks account existence | Security | S | `open` |
| [EX-115](#ex-115) | ⚪ low | arvrs_notice / arvrs_error are attacker-controlled reflected text in both admin and front notices | Security | S | `open` |
| [EX-116](#ex-116) | ⚪ low | Test harness ships inside the plugin with no ABSPATH guard | Security | S | `open` |
| [EX-117](#ex-117) | ⚪ low | Rate limiter is a non-atomic read-modify-write, so burst limits are bypassable | Security | S | `open` |
| [EX-118](#ex-118) | ⚪ low | The documented module dependency table is stale in at least four places | Architecture | S | `fixed` |
| [EX-119](#ex-119) | ⚪ low | Only one extension point exists in the entire plugin; no lifecycle events at all | Architecture | S | `open` |
| [EX-120](#ex-120) | ⚪ low | The claim that WordPress-freeness is "enforced by the unit suite" is not mechanically true | Architecture | S | `fixed` |
| [EX-121](#ex-121) | ⚪ low | Documentation cites code paths and counts that do not match the repo | Product completeness | S | `fixed` |
| [EX-122](#ex-122) | ⚪ low | Declared-but-unused surfaces: `usage_sync` job type, `orders.credential_id`, `usage_records.raw`, reservation ledger types | Product completeness | S | `open` |
| [EX-123](#ex-123) | ⚪ low | Fixed 48-hour usage window with no per-service watermark; suspended services stop syncing while still running | Reliability | M | `open` |
| [EX-124](#ex-124) | ⚪ low | Top-up intents are written to wp_options and never deleted or expired | Reliability | S | `open` |
| [EX-125](#ex-125) | ⚪ low | Untranslated internals leak into the Persian admin, and detail pages have no back-link | UX & usability | S | `open` |
| [EX-126](#ex-126) | ⚪ low | No automated coverage of the browser layer; the .playwright-cli directory holds manual session dumps, not tests | Testing & QA | L | `open` |
| [EX-127](#ex-127) | ⚪ low | Three concrete comment defects: a docblock attached to the wrong function, a stale @return, and a duplicated const comment | Code quality | S | `open` |
| [EX-128](#ex-128) | ⚪ low | UsageSync::apply_policy is an 80-line multi-responsibility function with a duplicated `global $wpdb` | Code quality | M | `open` |
| [EX-129](#ex-129) | ⚪ low | Admin order action relies on a helper's hidden exit() for control flow, with no return statements | Code quality | S | `open` |
| [EX-130](#ex-130) | ⚪ low | Redaction is key-name based only; upstream error text and job errors are stored verbatim | Operational readiness | S | `open` |
| [EX-131](#ex-131) | ⚪ low | Scheduled usage syncs record a timestamp but no result counts, so a silently empty run looks healthy | Operational readiness | S | `open` |
| [EX-132](#ex-132) | ⚪ low | Composite indexes do not match the actual sort orders; OFFSET pagination degrades on deep pages | Scalability | S | `open` |
| [EX-133](#ex-133) | ⚪ low | Declared model surface that no code path writes: `orders.credential_id`, and 4 of 10 ledger types | Data & analytics | S | `open` |
| [EX-134](#ex-134) | ⚪ low | No FKs and no application-level referential cleanup — deleting a credential orphans its services | Data & analytics | S | `open` |
| [EX-135](#ex-135) | ⚪ low | Documented client behavior overstates two details: a 5 s connect timeout that WP ignores, and "key-redacted request/response logging" that does not exist | Integration honesty | S | `fixed` |
| [EX-136](#ex-136) | ⚪ low | The admin UI offers "sync usage now" in real mode, where it can only ever produce zero rows | Integration honesty | S | `open` |
| [EX-137](#ex-137) | ⚪ low | Internal AI-agent tooling artifact leaked into a shipped engineering document | Documentation | S | `fixed` |
| [EX-138](#ex-138) | ⚪ low | "i18n-ready" is claimed but no translation catalog or translator guidance ships | Documentation | S | `fixed` |
| [EX-139](#ex-139) | ⚪ low | The JS bundle size cited in the README badge and the stack decision matrix is overstated by ~45% | Documentation | S | `fixed` |
| [EX-140](#ex-140) | ⚪ low | `role="tablist"` has no arrow-key navigation, and admin focus ring is downgraded to a failing 1px | Accessibility & i18n | S | `open` |
| [EX-141](#ex-141) | ⚪ low | Shortcode output nests `<main>` inside the theme's `<main>` and adds a second `<h1>` | Accessibility & i18n | S | `open` |

---

## Details

### EX-001 — Non-idempotent POSTs are auto-retried on timeout while the provider ignores the idempotency key — duplicate paid-for remote resources

*🔴 critical · Reliability · effort M · status `fixed`*

**Closed (v1.1.0):** `ArvanClient::request()` now branches retry eligibility on verb (`IDEMPOTENT` verbs only); POST/PATCH that time out or 5xx raise `timeout_indeterminate` instead of retrying, and `RealProvider` names every created resource deterministically (`remote_name()`) so the caller reconciles by lookup instead of re-POSTing. `ArvanClientTest`, e2e.
**Where:** `src/Arvan/ArvanClient.php:65-104 and src/Arvan/RealProvider.php:137-149`

**Evidence:** `ArvanClient::request()` retries on `is_wp_error($response)` for any method: `$retryable = true; ... if ($attempt <= self::RETRIES && $retryable) { usleep(250000 * $attempt); }` — the same loop that serves GETs also serves `POST .../servers` (RealProvider.php:190), `POST /domains/dns-service` (line 231) and `POST /buckets` (line 262). Meanwhile `create(string $product, string $plan_id, array $config, string $idempotency_key)` never references `$idempotency_key` — no header, no body field, not even logged; ProviderInterface.php:32 concedes it is "recorded for diagnostics". Provisioner.php:15-21 nevertheless claims "A refresh, replayed callback or retried job can never create a second remote resource."

**Impact:** A `POST /servers` that succeeds upstream but exceeds the 20s read timeout is re-sent up to twice more, creating two or three cloud servers for one order. Only one remote_id is ever stored, so the extras are invisible to the plugin and bill the reseller's ArvanCloud account indefinitely. For CDN/bucket the retry instead returns 4xx (already exists) → ProviderError('invalid') → non-retryable → order lands in `provision_failed` with the resource actually created, and every admin retry fails the same way. The three local idempotency layers guard against duplicate *local invocations*; nothing guards an in-flight upstream write.

**Fix:** Retry only idempotent methods (GET/PUT/DELETE) in ArvanClient; for POST, either send the idempotency key as a header the API honors, or on timeout do a reconcile GET (list servers/domains/buckets filtered by the generated name) before deciding whether to re-issue. At minimum stop retrying POST and surface the timeout as an ambiguous state requiring reconciliation.

### EX-002 — Payment page tells the customer the service is ready even when provisioning failed

*🔴 critical · UX & usability · effort M · status `fixed`*

**Closed (v1.1.0):** the payment callback result now carries the real post-payment provisioning state (`'payment result reports a truthful provisioning state'`, e2e), not an unconditional "ready".
**Where:** `src/Payments/PaymentService.php:92-99, templates/front/payment.php:53-63, src/Provisioning/Provisioner.php:61-72`

**Evidence:** handle_order_callback() calls Provisioner::provision() inside a try/catch and DISCARDS the result (`} catch (\Throwable $e) { Audit::error('provision.inline_deferred', ...); }`), then unconditionally returns `['ok' => true, ... 'message' => 'پرداخت تأیید شد.']`. front.js:103 branches only on `res.json.ok`, so payment.php:57 renders its hardcoded headline 'پرداخت تأیید شد؛ سرویس شما آماده است.' plus the CTA 'مشاهده سرویس در پیشخوان' (payment.php:62) whatever happened. On failure Provisioner.php:63 fires `Notifier::admin('provision_failed', ...)` only — grep for provision_failed shows no Notifier::customer call anywhere in src/. The Persian customer-safe strings in src/Arvan/DTO.php:87-97 ('پیکربندی انتخابی در حال حاضر قابل ارائه نیست. گزینه دیگری را انتخاب کنید.') are returned by Provisioner but no customer-facing caller reads them. The demo path reaches this: a server named `demo-fail` throws a retryable ProviderError (DemoProvider.php:76-84).

**Impact:** The customer pays money, is told the service is ready, clicks through to a services tab that is empty (dashboard.php:109-113 shows 'هنوز سرویسی ندارید.'), receives no notification and no email, and the only trace is 'خطا در راه‌اندازی' buried in the orders tab with no explanation, no retry, no refund request and no support link. This is the core journey and it misreports a paid transaction.

**Fix:** Return the provisioning outcome in the callback payload (ok/pending/failed + Provisioner's customer_message()), branch payment.php on it into three states (ready / 'در حال راه‌اندازی — چند لحظه دیگر بررسی کنید' / failed-with-support-CTA), and add Notifier::customer($cid, 'provision_failed', ...) in the ProviderError branch — Notifier.php:36 already whitelists that type for email.

### EX-003 — Ledger::balance() fetches every ledger row for a customer into PHP, on every front-end page render

*🔴 critical · Scalability · effort S · status `fixed`*

**Closed (v1.1.0):** `Ledger::balance()`/`balances()` are now a single indexed `GROUP BY` SQL aggregate (`Ledger.php::aggregate()`), object-cached — not a per-row PHP sum.
**Where:** `src/Wallet/Ledger.php:123-133, src/Front/Shortcodes.php:48`

**Evidence:** `balance()` runs `SELECT direction, amount, type FROM ...ledger WHERE customer_id = %d` with no aggregate and no LIMIT, then sums the rows in PHP via `derive()`. `Shortcodes::ctx()` — the shared context for storefront, product, checkout, dashboard, auth and payment templates — calls `'balance' => $uid ? Ledger::balance($uid) : null` on every render. SCALABILITY.md:15 claims "Ledger balance = one indexed aggregate per customer" and the scale table (:48) rates it "fine (≤~10³ rows/customer aggregate)" at 10k customers.

**Impact:** CAPACITY_MODEL.md:22 puts ledger growth at 48,000 rows/day across 1,000 customers = ~48 rows/customer/day, so the ≤10³ figure is exceeded in three weeks and a one-year-old customer's every page view loads ~17,500 rows into PHP memory. Nothing about this is an indexed aggregate; it is an O(rows-per-customer) fetch on the most-executed path in the product, and it degrades continuously and permanently because the ledger is append-only with no checkpointing.

**Fix:** Replace the row fetch with a single SQL aggregate: `SELECT SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) ...` plus the three type-scoped sums, keeping `derive()` for the unit tests. Add the checkpoint rows already contemplated in ADR-0007 once that flattens out, and correct the SCALABILITY.md claim to match whichever ships.

### EX-004 — No renewal or recurring billing anywhere — a 'monthly package' is charged exactly once, forever

*🔴 critical · Business viability · effort L · status `fixed`*

**Closed (v1.1.0):** `Billing\Renewals` charges each service's own term clock (`renews_at`/`term_days`/`renewal_price`) on a daily job, three-layer idempotent. e2e: `'renewal charged'`, `'the billing clock advanced by one term'`, `'a replayed renewal is recognised, not re-charged'`.
**Where:** `src/Install/Schema.php:84-103, src/Jobs/JobRunner.php:111-118`

**Evidence:** The `services` table has no `expires_at`, `renews_at`, `period_start` or `next_charge_at` column — only `created_at`/`updated_at` (Schema.php:84-103). `JobRunner::dispatch()` handles exactly two job types: `case 'provision_order'` and `case 'usage_sync'` (JobRunner.php:111,118). A grep for `renew|recurring|subscription|expires_at|next_billing` across src/ returns zero business-logic hits. ROADMAP.md lists no renewal work in v1.1/v1.2/v1.3.

**Impact:** The reseller sells a monthly package (BaseCosts seeds are explicitly 'monthly base costs', BaseCosts.php:56-75) and collects one payment. ArvanCloud keeps charging the reseller's upstream account hourly forever. Month 2 onward every active service is pure loss at 100% of upstream cost. At the documented Stage A profile (2,000 services, CAPACITY_MODEL.md:9-11) the reseller's upstream bill compounds monthly against a one-time revenue event. This is not a feature gap, it is the business model's revenue engine missing.

**Fix:** Add `services.period_end` + a `renew_service` job that re-quotes at the current base cost, charges wallet-first (falling back to a gateway top-up link), and transitions to a `suspended`/`expired` state on non-payment. The state machine, ledger uniqueness keys and job runner already support this — it is a new job type and one column, not a rewrite.

### EX-005 — The entire wallet/usage/credit-policy subsystem is dead code in real mode

*🔴 critical · Business viability · effort L · status `fixed`*

**Closed (v1.1.0):** recurring revenue in real mode now comes from `Billing\Renewals` (a real wallet debit), which makes the credit-policy ladder reachable in real mode. Metered usage sync itself remains demo-only because ArvanCloud still publishes no usage API — documented, not hidden, in `API_INTEGRATION.md`.
**Where:** `src/Arvan/RealProvider.php:317-320`

**Evidence:** `public function usage(string $product, array $remote_ids, string $since): array { return []; }`. `UsageSync::sync_all()` iterates `Plugin::arvan($product)->usage(...)` (UsageSync.php:51), so with a real credential it ingests zero rows and writes zero `usage_debit` entries. Order settlement writes a matched pair — `Ledger::append(..., 'payment', $amount ...)` and `Ledger::append(..., 'purchase', $amount ...)` (PaymentService.php:76-79) — which nets to zero on the wallet. Top-ups are credits only (PaymentService.php:132).

**Impact:** In production a customer's `available` balance can only ever be >= 0, so `PolicyEngine::stage()` returns HEALTHY permanently and the warning/critical/grace/restricted ladder, the negative-balance dashboard panel (templates/admin/dashboard.php:31-45), the reconciliation view and the usage tab never fire. Four of the eleven tables and roughly a third of the advertised feature surface ('Usage', 'Wallet', 'Policies' rows in the README capability table) do nothing for a paying reseller. README.md:211 admits usage 'is not fetchable' but does not state that the credit-policy product therefore has no real-mode function.

**Fix:** Either (a) drive usage from what the plugin already knows — synthesize periodic charges from the sold plan's base cost, which is exactly what `DemoProvider::usage()` does at DemoProvider.php:158-159 and would make the policy engine live — or (b) state plainly in README's limitations that wallet/usage/policy are demo-only in 1.0. Shipping it as a headline capability while it is inert upstream is the kind of gap a first commercial customer finds in week one.

### EX-006 — ArvanClient retries non-idempotent POSTs on timeout/5xx, defeating the documented single-invocation guarantee

*🔴 critical · Integration honesty · effort S · status `fixed`*

**Closed (v1.1.0):** same fix as EX-001 — verb-aware retry policy in `ArvanClient::request()`.
**Where:** `src/Arvan/ArvanClient.php:64-104`

**Evidence:** The retry loop is method-agnostic: `is_wp_error($response)` sets `$retryable = true` unconditionally, and any `$code >= 500` also sets `$retryable = true`; the loop then re-issues the identical `wp_remote_request($url, $args)` up to 3 times total. `ProviderInterface::create()` (ProviderInterface.php:29-36) states create "MUST be treated as non-idempotent upstream — callers guarantee single invocation per order", and no upstream idempotency key is ever sent (the `$idempotency_key` argument is unused in `RealProvider::create`, RealProvider.php:137-149).

**Impact:** A response timeout on `POST /regions/{region}/servers` (cURL 28 after Arvan already accepted the create) causes a second and third identical create. The customer is billed for one server; the reseller's upstream account is billed for up to three, and only one `remote_id` is ever recorded — the orphans are invisible to the plugin and can only be found in the Arvan panel. The same applies to `POST /domains/dns-service` and `POST /buckets`. DemoProvider makes this unreachable in demo (`DemoProvider::create` is a local `update_option`, no HTTP, no retry), so the failure mode cannot surface in any demo or test.

**Fix:** Restrict the retry loop to idempotent methods: retry only when `in_array($method, ['GET','HEAD','DELETE','PUT'], true)`, and for POST either fail fast to the job runner or add a pre-flight GET (list servers by the generated `name`, list domains, HEAD bucket) before re-issuing.

### EX-007 — RealProvider discards the idempotency key while ArvanClient blindly retries POSTs — duplicate billable upstream resources

*🟠 high · Security · effort M · status `fixed`*

**Closed (v1.1.0):** `RealProvider` now threads `idempotency_key` into every create call (`ArvanClient.php` `Idempotency-Key` header) and additionally names the resource deterministically, so a duplicate is caught by lookup even if the header is ignored upstream.
**Where:** `src/Arvan/RealProvider.php:137 + src/Arvan/ArvanClient.php:64-104`

**Evidence:** `public function create(string $product, string $plan_id, array $config, string $idempotency_key)` receives the key and never references it again — no header, no body field, no local record (grep for `idempotency` shows hits only in DemoProvider and the interface docblock). Meanwhile `ArvanClient::request` retries the *same* request on any 5xx or WP_Error: `if ($attempt <= self::RETRIES && $retryable) { usleep(...); }` inside `do { $response = wp_remote_request($url, $args); } while ($attempt <= self::RETRIES)` — with `RETRIES = 2` and `TIMEOUT = 20`, applied uniformly to `POST /servers`, `POST /domains/dns-service` and `POST /buckets`. The only test asserting "retried create must not mint a second resource" (tests/unit/UsageAndRedactionTest.php:45-51) runs against DemoProvider, whose determinism comes from `md5($idempotency_key)` — it proves nothing about the real path.

**Impact:** ArvanCloud accepts `POST /regions/{r}/servers`, the response times out at 20s or returns 502, the client re-POSTs, and a second server is created upstream. Only the first `remote_id` returned is stored in `arvrs_services`, so the duplicate is invisible to the plugin: it is never listed, never usage-synced, never deleted on cancel, and bills the reseller's Arvan account indefinitely. The three local idempotency layers in `Provisioner` cannot see it because the duplication happens below them, inside a single `create()` call. SECURITY.md's "A browser refresh, duplicated callback, or twice-delivered job cannot create two remote resources" is true as written but reads as a retry-safety guarantee the code does not provide.

**Fix:** Send the key upstream as an idempotency header on create calls, and separately stop retrying non-idempotent methods: gate the retry loop on `in_array($method, ['GET','HEAD'], true)` (or on an explicit `$retry_safe` flag passed by the caller) so POST failures surface as `ProviderError('unknown')` for the job runner to reconcile rather than being re-fired. At minimum, on a POST timeout do a GET reconciliation by name/tag before the retry.

### EX-008 — Durable job queue has no reaper: a crashed worker strands a job in 'running' forever

*🟠 high · Architecture · effort S · status `fixed`*

**Closed (v1.1.0):** `JobRunner::reap_stale()` requeues or dead-letters jobs stuck in `running` past their claim window; runs on `arvrs_minutely` and from a System Health admin action.
**Where:** `src/Jobs/JobRunner.php:56, 73-77`

**Evidence:** `run_due()` selects `WHERE status = 'pending' AND run_at <= %s`; `run_one()` flips the row to `'running'` before executing. Nothing anywhere resets `running` rows — grep for `running` across src/ returns only the claim, the stats bucket and a UI label. `Actions::job_retry` only requeues `WHERE ... status = 'dead'` (JobRunner.php:153).

**Impact:** A PHP fatal, OOM or max_execution_time kill mid-job (the common case, since provisioning does blocking HTTP + sleeps) leaves the job permanently in `running`: never re-run, never dead-lettered, no admin alert, and invisible on the health page except as a stuck counter. For `provision_order` that means a paid order silently never provisions — the exact failure ADR-0004 claims the table design eliminates ("crash-safety of paid-but-unprovisioned orders").

**Fix:** Add a stale-claim sweep at the top of `run_due()`: `UPDATE jobs SET status='pending' WHERE status='running' AND claimed_at < UTC now minus N minutes`. The `claimed_at` column already exists and is populated for exactly this purpose.

### EX-009 — Orders can become permanently stuck in 'provisioning' with an orphaned upstream resource

*🟠 high · Architecture · effort M · status `fixed`*

**Closed (v1.1.0):** `Provisioner::reclaim_stale()` + an admin "reclaim" action on the order-detail page (`Admin\Actions::order_action`, `do=reclaim`) resolve an order stuck in `provisioning` either to `active` (resource exists) or `provision_failed` (unlocking retry/refund).
**Where:** `src/Provisioning/Provisioner.php:44-48, src/Jobs/JobRunner.php:112-116`

**Evidence:** `provision()` claims the order paid→provisioning (or provision_failed→provisioning) and only then calls the provider. If the process dies after `Plugin::arvan()->create()` succeeds but before `Services::create_for_order()` (Provisioner.php:74), the order stays `provisioning`. The retry path claims only from `PAID` or `PROVISION_FAILED`, so it returns `'order not claimable (state: provisioning)'` — and JobRunner.php:114 explicitly treats a message containing `not claimable` as job success, marking the job `done`.

**Impact:** A single crash or timeout in the provisioning window produces: a paid customer with no service, an order frozen in a non-terminal state, a real ArvanCloud resource created and billed with no local `services` row (so it is invisible to usage sync and to `Ledger::reconciliation_by_credential`), and a job queue that reports success. No code path recovers it; the admin "retry" button hits the same not-claimable branch.

**Fix:** Treat `provisioning` as reclaimable after a timeout (add it to the claim disjunction with an `updated_at` age guard), and reconcile against the provider before re-creating — `ProviderInterface::status()` already exists for exactly this. At minimum, do not report `not claimable` as job success without checking that a service row exists.

### EX-010 — Up to 15s of blocking sleep plus synchronous email inside the payment callback request

*🟠 high · Architecture · effort M · status `open`*

**Where:** `src/Arvan/RealProvider.php:200-218, src/Payments/PaymentService.php:91-97`

**Evidence:** `create_server()` runs `for ($i = 0; $i < 5 && $ip === ''; $i++) { sleep(3); ... }` — a 15-second synchronous poll. `PaymentService::handle_order_callback` calls `Provisioner::provision()` inline (line 93) on the gateway callback request, and `Notifier::customer()` (line 84) calls `wp_mail` synchronously. `ArvanClient.php:97` adds another `sleep(max(1,$after))` on 429.

**Impact:** The payment callback — the one request that must return fast and idempotently — can block for 15-25s plus SMTP. Real gateways retry on callback timeout; the replay path is safe, but the customer-visible request stalls. Worse, this directly defeats ADR-0004's stated mitigation of its own named risk ("Long-running jobs vs PHP max_execution_time. Batch size 5 keeps runs short"): five queued `provision_order` jobs at ~15s+HTTP each blow past a 30s `max_execution_time`, and the killed run leaves rows in the un-reapable `running` state above.

**Fix:** Return `status='creating'` from `create_server()` immediately (the `RemoteResource` already models it, line 220) and let a follow-up `status()` job fill in the address, instead of polling in-request. Enqueue notification email rather than calling `wp_mail` on the payment path.

### EX-011 — Leaving Demo Mode disables all selling — checkout, top-up and the payment page all hard-fail with no admin warning

*🟠 high · Product completeness · effort M · status `open`*

**Where:** `src/Payments/PaymentService.php:33-36`

**Evidence:** `sandbox_blocked()` returns `Plugin::payments()->id() === 'sandbox' && !Plugin::demo_mode()`, and Sandbox is the only shipped adapter (`Plugin::payments()`, Plugin.php:76-82). It gates `/checkout` (Rest/Routes.php:135-137 → 503 «درگاه پرداخت واقعی هنوز پیکربندی نشده است»), `/me/topup` (Routes.php:93), `handle_order_callback` (PaymentService.php:44) and the gateway page (Shortcodes.php:195). Wizard step `arvan` sets `Options::set('demo_mode', false)` the moment a real token passes the connection test (Wizard.php:200).

**Impact:** The documented happy path — reseller enters a real ArvanCloud token in the wizard — produces a storefront where every purchase and every wallet top-up returns "contact support". Nothing surfaces this: `Wizard::validation_checks()` (Wizard.php:112-135) checks license/arvan/pricing/pages but not the gateway, and the System Health page prints the provider label with no danger badge (templates/admin/health.php:45). The reseller discovers the store is dead only from a customer complaint.

**Fix:** Add a gateway readiness check to `Wizard::validation_checks()` and a red row on System Health when `sandbox_blocked()` is true, and either ship one real PSP adapter or make the real-mode block an explicit, documented admin setting rather than an invisible side effect of leaving Demo Mode.

### EX-012 — Real-mode Cloud Server storefront renders empty: API flavor IDs have no base-cost rows and nothing detects it

*🟠 high · Product completeness · effort M · status `open`*

**Where:** `src/Arvan/RealProvider.php:71-75`

**Evidence:** Real plans are built with `BaseCosts::get($product, $id)` where `$id` is the upstream ECC size id from `GET /regions/{r}/sizes`, but `BaseCosts::seed_defaults()` only seeds the demo plan ids (`g1-1-1-25`, `g1-2-2-25`, …, BaseCosts.php:61-74). `Shortcodes::product` drops any plan with `base_cost <= 0` ("unpriced plans are not sellable", Shortcodes.php:94-95) and `OrderService::create` rejects them with `unpriced` (OrderService.php:72-74).

**Impact:** After a successful real-credential onboarding, /cloud-server shows the «پلن‌ها موقتاً در دسترس نیستند» error card until the admin hand-adds a base-cost row for every upstream flavor id. The wizard's pricing check passes regardless because it only asserts `count(BaseCosts::all()) > 0` (Wizard.php:126), so onboarding reports green on a catalog that cannot sell.

**Fix:** After a credential test succeeds, fetch the real plan list and flag any plan with no base cost in the Pricing screen and in the wizard's validation step; the Pricing page already has the `new_plan_id` input to fix them, it just needs to name the missing rows.

### EX-013 — An order stuck in `provisioning` is unrecoverable: no admin action exists and the retry job reports success

*🟠 high · Reliability · effort M · status `fixed`*

**Closed (v1.1.0):** same fix as EX-009 — `reclaim_stale()` + the order-detail admin action.
**Where:** `src/Provisioning/Provisioner.php:44-48, src/Jobs/JobRunner.php:113-117, templates/admin/order-detail.php:39-63`

**Evidence:** `provision()` can only claim from PAID or PROVISION_FAILED; from `provisioning` both transitions fail and it returns `'order not claimable (state: ' . $order['status'] . ')'`. JobRunner::execute treats that string as success — `if (!$result['ok'] && strpos($result['message'], 'not claimable') === false ...) throw` — so the job is marked `done`. The admin retry button renders only `if (in_array($order['status'], ['provision_failed','paid'], true))`, refund only for `['paid','active','provision_failed']`, cancel only for `['pending_payment','payment_processing']`. Nothing anywhere calls the legal PROVISIONING→PROVISION_FAILED transition except the ProviderError catch inside provision() itself (Provisioner.php:62).

**Impact:** Any interruption after the PAID→PROVISIONING claim strands the order permanently: customer paid, no service, no retry, no refund, no cancel, and the durable job silently self-completes. The trigger is realistic, not theoretical — inline provisioning runs in the REST callback and `create_server` alone can burn 15s of `sleep(3)` polling (RealProvider.php:200-218) on top of four HTTP calls at 20s each, so PHP's max_execution_time fatal lands squarely inside this window.

**Fix:** Add a reaper that moves orders sitting in `provisioning` past a timeout (e.g. updated_at older than 15 min) back to `provision_failed`, and allow the retry/refund buttons for `provisioning`. Also stop treating 'not claimable' as job success — requeue with backoff instead.

### EX-014 — Jobs left in `running` are never reclaimed — no reaper, not selectable, not retryable

*🟠 high · Reliability · effort S · status `fixed`*

**Closed (v1.1.0):** same fix as EX-008 — `JobRunner::reap_stale()`, plus per-job retry/kill on the job-detail admin page.
**Where:** `src/Jobs/JobRunner.php:73-105, 137-156`

**Evidence:** `run_one` flips the row to `'running'` and only writes a terminal status inside the try/catch. A PHP fatal, OOM or request timeout during `execute()` skips both branches. `run_due` selects `WHERE status = 'pending'` only; `failed()` lists `status = 'dead' OR (status = 'pending' AND attempts > 0)`; `retry()` updates `WHERE id = %d AND status = 'dead'`. The `claimed_at` column exists in the schema (Schema.php:148) but is never read back anywhere.

**Impact:** A worker killed mid-job leaves the row `running` forever. It is invisible to the queue, absent from the failed-jobs list, and the admin retry button rejects it. health.php:52 prints the `running` count so the operator can see the number grow but has no control to act on it — recovery requires manual SQL. For a `provision_order` job this compounds finding #2: the order is stuck and its recovery job is stuck too.

**Fix:** In `run_due`, also reclaim `status='running' AND claimed_at < NOW() - INTERVAL 10 MINUTE` back to `pending` (attempts already incremented, so the dead-letter cap still applies), and let `retry()` accept `running` as well as `dead`.

### EX-015 — Ledger write failure on a settled payment is swallowed with no repair path — money with no record

*🟠 high · Reliability · effort M · status `open`*

**Where:** `src/Payments/PaymentService.php:75-82`

**Evidence:** After the order is atomically claimed PAID, both ledger appends sit in a `try { ... } catch (\Throwable $e) { Audit::error('ledger.payment_append_failed', ...); }` and execution continues to provisioning. The comment states "the discrepancy surfaces in reconciliation + audit", but `Ledger::reconciliation()` (Ledger.php:192) only aggregates rows that exist — it cannot show a payment that was never written — and no admin action back-fills a missing payment/purchase pair. Contrast UsageSync.php:106-119, where the same class of crash *is* back-filled.

**Impact:** A transient DB error (deadlock, connection drop) at that instant produces an order in PAID/ACTIVE whose money exists nowhere in the ledger. Downstream this silently corrupts `consumed`, so the `spending_limit` gate at OrderService.php:88-94 under-counts and lets the customer exceed their cap; `total_credit()` and per-customer reconciliation are both wrong, and the only trace is an `error`-level audit row nobody is alerted on.

**Fix:** Either enqueue a durable `ledger_backfill` job carrying (customer, order, ref, amount) when the append throws — the unique key makes it replay-safe — or add an admin reconciliation view that diffs orders in PAID/ACTIVE against ledger rows with matching `ref_id` and offers a one-click repair.

### EX-016 — Every admin alert is written to the DB and rendered nowhere — $notices is dead

*🟠 high · UX & usability · effort S · status `fixed`*

**Closed (v1.1.0):** `Admin\Flash` renders stored notices; `templates/admin/partials/notices.php` is included on the relevant admin screens.
**Where:** `src/Admin/Menu.php:113, templates/admin/dashboard.php`

**Evidence:** Menu::dashboard() passes `'notices' => Notifier::for_user(0, 6)` (the admin feed: provision_failed, job_dead, usage_sync_failed, customer_at_risk, credential_failed). A repo-wide grep for `$notices` returns no matches in any template. The only 'notices' the dashboard includes is partials/notices.php, which reads the flash query args $arvrs_notice/$arvrs_error — an unrelated mechanism.

**Impact:** The reseller's entire alerting surface is invisible. A failed provisioning, a dead job or a broken ArvanCloud credential produces a notification row nobody can ever see; the operator only learns of it by noticing a KPI counter (dashboard.php:27-28) or by opening the health page.

**Fix:** Render the $notices feed as a panel on templates/admin/dashboard.php (title, body, time, unread styling), and make the 'راه‌اندازی ناموفق' / 'وظایف متوقف' KPI cards link to admin.php?page=arvan-reseller-orders&status=provision_failed and to the health page.

### EX-017 — Mid-payment network error strands the customer on a permanent spinner with both buttons disabled

*🟠 high · UX & usability · effort S · status `open`*

**Where:** `assets/js/front.js:96-113 and 136-154`

**Evidence:** The gateway handler does `callback().then(function (res) {...})` with no `.catch()`, after having set `$('#arvrs-pay-progress').hidden = false; payOk.disabled = true; fail.disabled = true;`. The wallet top-up handler is the same shape: `topupBtn.disabled = true; topupBtn.textContent = ARVRS.i18n.processing;` then `api('me/topup', ...).then(...)` with no catch — unlike the order form, which does have one (front.js:79-82). A failed response there is also surfaced by `alert()` (front.js:109), the only native dialog in an otherwise fully designed alert system.

**Impact:** A dropped connection or 5xx at the moment of payment leaves 'در حال تأیید پرداخت…' spinning forever with no way to retry or cancel — the worst possible place to dead-end. The same failure on top-up leaves the button stuck on 'در حال پردازش…' with no error shown.

**Fix:** Add `.catch()` to both handlers restoring button state and showing ARVRS.i18n.error in the existing alert markup (#arvrs-pay-status / #arvrs-topup-error) instead of alert(); on the gateway, keep the ref visible so the customer can be told 'وضعیت پرداخت نامشخص است — پیشخوان را بررسی کنید'.

### EX-018 — Abandoned payment is a dead end: the pending-orders page is unreachable from the UI

*🟠 high · UX & usability · effort S · status `open`*

**Where:** `templates/front/checkout.php, templates/front/dashboard.php:138-159, src/Front/Shortcodes.php:112-125`

**Evidence:** A grep for `checkout` across all of templates/ returns no matches — no header nav item, no dashboard link, no CTA anywhere points at PageFactory::url('checkout'). The only navigation to it is front.js:131, the gateway's 'انصراف' button. The dashboard orders tab renders status only ('در انتظار پرداخت' via Helpers::status_tag) with no pay action, while Shortcodes::checkout() builds a fresh pay_url for exactly those orders.

**Impact:** A customer who closes the gateway tab, or returns tomorrow, has no route in the interface to pay an order they already configured. The page titled 'سفارش‌های در انتظار پرداخت' exists, works, and is orphaned.

**Fix:** Add a 'پرداخت' button in the dashboard orders row for pending_payment/payment_processing (reuse Plugin::payments()->start()), and surface a header chip or dashboard banner when pending orders exist.

### EX-019 — Order-payment replay test short-circuits before the DB idempotency guard it claims to prove

*🟠 high · Testing & QA · effort S · status `open`*

**Where:** `tests/integration/e2e.php:86-92`

**Evidence:** The replay is issued after provisioning, so the order status is 'active'. PaymentService.php:49 returns early — `if (!in_array($order['status'], StateMachine::payable(), true)) { return [... 'replay' => true ...]; }` — before verify(), before `claim_paid()`, and before either `Ledger::append()`. The follow-up SQL checks ('exactly one payment ledger row', 'exactly one service') therefore only confirm that no second write happened on a path that never attempted one.

**Impact:** The three real duplicate-payment defenses are untested by this test: the UNIQUE KEY uniq_ref (ref_type,ref_id,type) at Schema.php:119, the `rows_affected === 0` duplicate signal at Ledger.php:63, and the atomic status-guarded UPDATE in OrderService::claim_paid (OrderService.php:179-185). Drop the unique index entirely and every check in section 6 still passes. The state pre-check is also the guard that is useless under concurrency — two simultaneous callbacks both read a payable status.

**Fix:** Add a check that calls the ledger/claim layer directly on an already-settled ref: assert `Ledger::append($cust,'payment',$amt,'order',$ref,...) === 0` and `OrderService::claim_paid($ref,$amt,'tx2') === null` after settlement, so the DB-level guards are exercised independently of the status short-circuit.

### EX-020 — sandbox_blocked() — the guard that stops a self-verifiable sandbox proof settling real money — has zero coverage

*🟠 high · Testing & QA · effort S · status `open`*

**Where:** `src/Payments/PaymentService.php:33-36 (used at :44 and :124)`

**Evidence:** `grep -rn "sandbox_blocked\|demo_mode" tests/` returns exactly one line: e2e.php:52, which sets `'demo_mode' => true`. Since the guard is `payments()->id() === 'sandbox' && !Plugin::demo_mode()`, it evaluates false for the entire E2E run. No unit test references it either. TESTING.md:41 credits the adversarial review with finding 'sandbox-as-live-gateway' — the fix shipped without a regression test.

**Impact:** The highest-severity money bug the project found in review is protected by no test. A refactor that reorders the checks in handle_order_callback / handle_topup_callback, or that changes Plugin::payments() resolution, silently re-opens free provisioning against a live store, and the suite stays green.

**Fix:** Add a unit test that stubs demo_mode off with the sandbox provider active and asserts both callbacks return ok=false, plus an E2E check that flips demo_mode to false mid-run and asserts a sandbox-proof callback is refused before any ledger row is written.

### EX-021 — The only real data migration (v3→v4 ledger is_demo back-stamp) is unreachable from every test

*🟠 high · Testing & QA · effort M · status `open`*

**Where:** `src/Install/Schema.php:221-227`

**Evidence:** `if ($from_version > 0 && $from_version < 4) { ... $wpdb->query('UPDATE ' . $p . 'ledger SET is_demo = 1'); }` where `$from_version = (int) get_option('arvrs_schema_version', 0)` (Schema.php:25). e2e.php:13 states 'Requires a FRESH install (re-runs need a reset DB)', so $from_version is always 0 and the branch never executes. No unit test loads Schema at all.

**Impact:** An UPDATE that rewrites every ledger row's is_demo flag — the flag that decides whether money counts as real in reconciliation — is untested in both directions. If the `$in_demo` inference at :223 is wrong (note it defaults to demo when 'demo_mode' is absent from settings), a live reseller upgrading from v3 has their entire real ledger stamped as demo and excluded from money views, with no test to catch it.

**Fix:** Add an integration check that seeds arvrs_schema_version=3 plus a few ledger rows under both settings shapes, calls Schema::migrate(), and asserts the is_demo outcome for each — including the 'settings key absent' default.

### EX-022 — Two of the five License tests assert nothing about the license code; one is named for an invariant it never exercises

*🟠 high · Testing & QA · effort M · status `open`*

**Where:** `tests/unit/LicenseTest.php:11-27 and :29-38`

**Evidence:** test_valid_token_activates_and_stores_only_fingerprint never activates anything: it asserts `License::is_active()` is false, that a random token is rejected, then asserts `is_active()` is false again — the comment at :23 says 'Verify storage shape after simulating a successful activation' but no activation is simulated. test_activation_against_temp_allowlist admits at :31-33 it cannot redirect the allowlist, then asserts `password_verify($token, password_hash($token, PASSWORD_BCRYPT))` — a test of PHP's stdlib, containing zero plugin symbols.

**Impact:** The claimed invariant in TESTING.md:19 ('storage shape, no-plaintext-in-repo guarantee, bcrypt round-trip') is only half real. Nothing verifies that a *successful* activation stores only a fingerprint and never the plaintext token — the exact leak the design exists to prevent. Both tests are green by construction and can never fail on a regression in License.

**Fix:** Make the allowlist injectable (constructor arg, filter, or a settable static) so a test can activate a known token and assert the persisted option contains a fingerprint and not the token. Delete the password_hash test or replace it with one that calls License::verify().

### EX-023 — Job runner decides retry-vs-success by substring-matching English prose from Provisioner

*🟠 high · Code quality · effort S · status `fixed`*

**Closed (v1.1.0):** `Jobs\Handlers::provision_order` branches on the typed `$result['kind']` returned by `Provisioner::provision()`, not on message-text substring matching (see the doc comment on the method for the before/after).
**Where:** `src/Jobs/JobRunner.php:114`

**Evidence:** `if (!$result['ok'] && strpos($result['message'], 'not claimable') === false && strpos($result['message'], 'not found') === false) { throw new \RuntimeException($result['message']); }` — matched against literals produced at src/Provisioning/Provisioner.php:32 ('order not found') and :47 ('order not claimable (state: …)').

**Impact:** Two failure modes. (1) Rewording either Provisioner string — a natural refactor, they are untranslated debug prose — silently converts a benign 'already handled' result into a thrown exception, so the job retries 5 times and lands in `dead` with an admin alert. (2) Non-retryable ProviderError kinds (`auth`, `invalid`) return `$e->customer_message()`, a Persian string (src/Arvan/DTO.php:88) that can never match either English needle, so a permanently misconfigured credential burns all 5 attempts and 53 minutes of backoff before surfacing.

**Fix:** Return a machine-readable outcome from `Provisioner::provision()` — e.g. add `'code' => 'not_found'|'not_claimable'|'provisioned'|'failed'` to the returned array (the shape is already documented in its `@return` annotation) — and switch `JobRunner::execute()` on that code. The message field then stays free to be reworded or translated.

### EX-024 — Admin alerts are write-only: notifications are stored, passed to the dashboard, and never rendered

*🟠 high · Operational readiness · effort S · status `fixed`*

**Closed (v1.1.0):** same fix as EX-016 — `Admin\Flash`.
**Where:** `src/Admin/Menu.php:113 + templates/admin/dashboard.php (69 lines, no `$notices`)`

**Evidence:** `Menu::dashboard()` passes `'notices' => \ArvanReseller\Notifications\Notifier::for_user(0, 6)` but `templates/admin/dashboard.php` is 69 lines long and never references `$notices` (verified by grep across all of `templates/`: the only `notices` hits are `include partials/notices.php`, which renders the `?arvrs_notice=` flash query-arg, not the table). `Notifier::admin()` (Notifier.php:41-44) calls only `push()` — unlike `Notifier::customer()` it never calls `wp_mail()`.

**Impact:** Every operator-facing alert the system raises — `job_dead`, `provision_failed`, `usage_sync_failed`, `customer_at_risk` — is inserted into `wp_arvrs_notifications` with `customer_id = 0` and is then visible in exactly zero places, in-app or by email. An operator learns about a failed provisioning only by noticing a red counter tile on the dashboard or scrolling the audit page. The whole admin notification subsystem is dead weight at runtime.

**Fix:** Render `$notices` as a panel in `dashboard.php` (the data is already being fetched), and add an unread-count badge on the plugin menu via `add_menu_page`'s title. For anything at `job_dead`/`provision_failed` severity, also `wp_mail()` the site admin from `Notifier::admin()` on the same cooldown mechanism already implemented in `push()`.

### EX-025 — Jobs stranded in `running` are invisible and unrecoverable from the UI

*🟠 high · Operational readiness · effort S · status `fixed`*

**Closed (v1.1.0):** same fix as EX-008/014 — reaper + job-detail retry/kill UI make a stranded `running` job visible and actionable.
**Where:** `src/Jobs/JobRunner.php:73-77,137-156`

**Evidence:** `run_one()` writes `status='running', claimed_at=%s` but nothing ever reads `claimed_at` again (grep across `src/`: only Schema.php:148 and this UPDATE). `run_due()` selects `WHERE status='pending'`; `retry()` only matches `WHERE id=%d AND status='dead'`; `failed()` selects `WHERE status='dead' OR (status='pending' AND attempts > 0)` — `running` matches none of them. There is no reaper cron (only `arvrs_run_jobs` and `arvrs_usage_sync` are scheduled, Activator.php:17,20).

**Impact:** A PHP fatal, OOM or request timeout mid-`execute()` is not a `\Throwable` the catch block sees, so the row stays `running` forever. That order never provisions, the job does not appear in the health page's failed list, and no admin button can move it. The only signal is the `running: N` counter on the health page, which looks identical to a healthy in-flight job. Recovery requires a manual `UPDATE` in the database.

**Fix:** Add a stale-claim reset at the top of `run_due()` — one statement: `UPDATE ... SET status='pending', run_at=NOW() WHERE status='running' AND claimed_at < NOW() - INTERVAL 15 MINUTE` — and include `running` rows older than that threshold in `failed()` so they surface with a retry button.

### EX-026 — Credential health is manual-only; a revoked token keeps showing "connected" until someone clicks Test

*🟠 high · Operational readiness · effort M · status `open`*

**Where:** `src/Arvan/Credentials.php:134 (callers: src/Admin/Actions.php:206,211 and src/Onboarding/Wizard.php:198 only)`

**Evidence:** `record_test()` has exactly three callers, all human-initiated (the Test button and the setup wizard). The runtime path does not touch it: `ArvanClient::handle()` throws `ProviderError('auth', ...)` on 401/403 (ArvanClient.php:123-125) and `Provisioner` catches it (Provisioner.php:61-71) without marking the credential. `health.php:98` then evaluates `$ok((bool)$credential['last_ok_at'] && !$credential['last_error'], ...)` — a token that authenticated once and was later revoked renders as متصل (connected) indefinitely. The intent existed: `Notifier::COOLDOWN_TYPES` (Notifier.php:20) declares a `credential_failed` type that is never emitted anywhere in the codebase.

**Impact:** The single most common upstream incident — an expired or revoked ArvanCloud API token — produces a health page that says everything is fine while every provisioning silently fails. The operator's first-look diagnostic actively misleads them.

**Fix:** Call `Credentials::record_test($credential_id, false, $e->getMessage())` from the `ProviderError` catch in `Provisioner` and `UsageSync` whenever `$e->kind === 'auth'`, and emit the already-declared `credential_failed` admin notification there. Requires threading the selected `$credential['id']` (already computed at Provisioner.php:52) into the catch.

### EX-027 — Admin customer list N+1s the unbounded balance query — 20 full ledger scans per page render

*🟠 high · Scalability · effort S · status `fixed`*

**Closed (v1.1.0):** the admin customer list now calls `Ledger::balances($ids)` once for the visible page (`Admin\Menu.php`, comment: "Ledger::balance() per row — twenty unbounded ledger scans per render" marks the old code it replaced) instead of `Ledger::balance()` per row.
**Where:** `src/Admin/Menu.php:170-179`

**Evidence:** `foreach ($query->get_results() as $user) { $balance = Ledger::balance($user->ID); ... }` over a 20-row `WP_User_Query`. SCALABILITY.md:47 rates "Customer lists/detail" as "fine (paginated, indexed)" all the way to 10k customers.

**Impact:** Each iteration is the unbounded fetch from finding #1, so the customers screen loads 20 × (that customer's entire ledger history) into PHP per render — ~350,000 rows at the capacity model's own one-year volumes. The pagination is real but it bounds the wrong axis: rows-per-page is capped, rows-per-row is not. This is the exact pattern the doc claims is fine.

**Fix:** One grouped query for the visible page: `SELECT customer_id, SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) FROM ledger WHERE customer_id IN (...) GROUP BY customer_id`, keyed back onto the user rows.

### EX-028 — Catalog cache miss makes a blocking upstream call inside page render, with no negative caching and no stampede guard

*🟠 high · Scalability · effort S · status `fixed`*

**Closed (v1.1.0):** `Arvan\Catalog` now has a single-refresher lock (`LOCK_TTL`), a 60 s negative cache on upstream failure, and a stale-serve fallback past TTL — a miss no longer lets every concurrent viewer block on an uncached upstream call, and a failure is no longer retried on every view.
**Where:** `src/Arvan/Catalog.php:47-55, src/Arvan/ArvanClient.php:17-19`

**Evidence:** `plans()` on a transient miss calls `Plugin::arvan($product)->plans($product)`; on `ProviderError` it `return []` **without** calling `set_transient`, so failures are never cached. `ArvanClient` uses `TIMEOUT = 20`, `RETRIES = 2`, and for `cloud_server` `RealProvider::plans()` first calls `default_region()` — a second HTTP request. SCALABILITY.md:15 states "Catalog cached 6h; no external HTTP in any page render" and CAPACITY_MODEL.md:34 rates storefront views "cached catalog, no external HTTP **[verified in code]**".

**Impact:** The claim is false on the miss path. Every 6h expiry sends the first N concurrent storefront/product requests upstream simultaneously (no lock, no stale-while-revalidate). During an ArvanCloud degradation it is worse: because errors are never cached, *every* page view retries — up to 3 attempts × 20s + backoff per call, ×2 calls for cloud_server ≈ 120s of blocked PHP-FPM worker per visitor. A provider outage becomes a full storefront outage through worker exhaustion, and the "[verified in code]" label makes a reviewer trust the wrong thing.

**Fix:** Cache the failure short (`set_transient($key, [], 60)`) so an outage costs one upstream call per minute, serve the stale value past TTL while one request refreshes it, and drop or qualify the "no external HTTP in any page render" claim.

### EX-029 — Hourly usage sync rescans the entire ledger twice — the real first bottleneck, and not the one the docs name

*🟠 high · Scalability · effort S · status `open`*

**Where:** `src/Usage/UsageSync.php:83-85, :131-143, src/Wallet/Ledger.php:136-153`

**Evidence:** `sync_all()` ends with `foreach (array_keys($touched) as $customer_id) { self::apply_policy($customer_id); }`. `apply_policy()` calls `Ledger::balance($customer_id)` (:131) and then `Ledger::negative_since_days($customer_id)` (:142), whose first line is `$bal = self::balance($customer_id);` — the same unbounded fetch a second time, plus a `MAX(created_at)` aggregate.

**Impact:** Every customer who ingested any usage in the hour is touched, so a run loads roughly 2× the entire ledger table into PHP every hour, and that cost grows with total ledger history forever — ~35M rows/hour after year one at the documented Stage A ceiling. SCALABILITY.md:63 names "usage ingestion via WP-Cron" as what breaks first and prescribes chunking by service-ID range (:67); chunking does not help here, because the policy pass cost is proportional to accumulated ledger size, not to the batch. The documented fix does not address the documented-away bottleneck's larger sibling.

**Fix:** Have `negative_since_days()` accept the already-computed balance instead of recomputing it, and move balance derivation to SQL (finding #1). Then re-run the "what breaks first" analysis — the answer changes.

### EX-030 — sync_all() is unbounded and unchunked in one request; the demo provider materialises every usage row in memory first

*🟠 high · Scalability · effort M · status `open`*

**Where:** `src/Usage/UsageSync.php:36-89, src/Services/Services.php:84-95, src/Arvan/DemoProvider.php:146-174`

**Evidence:** `Services::active_for_sync()` is `SELECT ... WHERE status IN ('active','at_risk') ORDER BY id ASC` with no LIMIT. `sync_all()` groups all of them and calls `->usage($product, array_keys($map), ...)` in one shot; `DemoProvider::usage()` loops `for ($t = $start; $t < $end_of_closed; $t += HOUR_IN_SECONDS)` per remote id, accumulating every `UsageRow` into one `$rows` array before returning. The hook is wired straight to WP-Cron hourly (src/Install/Activator.php:20), not through the jobs table.

**Impact:** At the documented Stage A ceiling of 2,000 services that is ~96,000 objects built in memory before a single row is written, followed by up to 96,000 INSERT IGNOREs plus 96,000 ledger appends in one PHP request with no time budget and no resume point. There is no checkpoint, so a timeout at 90% wastes the run (idempotency makes it safe, not cheap). The docs acknowledge this precisely (:63-67), which is worth real credit — but the shipped code is still the unchunked version, so the Stage A envelope of "≤2,000 active services" is an untested claim about code that provably allocates 96k objects at that number.

**Fix:** Stream: yield rows per service rather than returning one array, and enqueue `usage_sync` chunks by service-ID range as the doc already plans (it is the ~20 lines SCALABILITY.md:67 estimates). Add a wall-clock budget that re-enqueues the remainder.

### EX-031 — Usage debits are posted at raw upstream cost — the metered revenue path earns zero margin

*🟠 high · Business viability · effort S · status `open`*

**Where:** `src/Usage/UsageSync.php:116,123`

**Evidence:** `Ledger::append($customer_id, 'usage_debit', max(0, $row->cost), 'usage', ...)` — `$row->cost` is the provider's cost value verbatim. `Pricing::quote()` is called only from Shortcodes.php:72,97, OrderService.php:80 and Rest/Routes.php:113; it is never invoked on the usage path. `DemoProvider::usage()` derives cost as `round($monthly / 720)` from `BaseCosts::get()` (DemoProvider.php:158-159), i.e. the reseller's own cost.

**Impact:** On the consumption model the plugin is architecturally built for, the reseller resells at exactly cost: 0% gross margin, and `orders.margin` does not capture it either because no order row is created. The demo therefore shows a customer's wallet being drained by the full upstream monthly cost on top of a marked-up package payment already collected at checkout — the customer is charged roughly twice for the same resource while the reseller's books record margin only once. The money model is not internally consistent between its two halves.

**Fix:** Run usage cost through `Pricing::quote()` (or at minimum the global/product markup) before the ledger debit, and record the cost/price delta so metered margin lands in the same reporting path as order margin. Then decide explicitly whether checkout is a package sale OR a prepaid deposit — the current code does both simultaneously.

### EX-032 — Real-mode cloud-server plans are unsellable out of the box; the wizard reports pricing as complete anyway

*🟠 high · Business viability · effort M · status `open`*

**Where:** `src/Pricing/BaseCosts.php:59-64 vs src/Arvan/RealProvider.php:66-77`

**Evidence:** `RealProvider::plans('cloud_server')` builds plan ids from the live API: `$id = (string) ($size['id'] ?? '')` off `GET /regions/{region}/sizes`, then `BaseCosts::get($product, $id)`. The seeded ids are demo-shaped strings `g1-1-1-25`, `g1-2-2-25`, `g1-4-4-50`, `g1-8-8-100` (BaseCosts.php:61-64), which will not match Arvan's flavor ids. Unpriced plans are filtered out of the storefront: `if ((int) $plan['base_cost'] <= 0) continue; // unpriced plans are not sellable` (Shortcodes.php:94-95) and rejected at checkout with `'unpriced'` (OrderService.php:72-74). Yet the wizard's readiness check is `'ok' => count(BaseCosts::all()) > 0` (Wizard.php:126), which `seed_defaults()` guarantees true (Wizard.php:213).

**Impact:** A real reseller finishes onboarding, is told «راه‌اندازی کامل شد. فروشگاه شما آماده است!» (Wizard.php:224), and lands on an empty cloud-server page. Recovery is hand-transcribing every Arvan flavor id and its price into the one-row-at-a-time form at templates/admin/pricing.php:52-58. Time-to-first-sale goes from minutes to an hour of error-prone data entry, and a mistyped base cost silently mis-prices every future order since margin is derived from it.

**Fix:** Add an 'import plans from Arvan' action that inserts a `base_costs` row per live flavor with cost 0, and make the wizard's pricing check assert that at least one plan from the *active provider's* catalog has a non-zero base cost. That converts a silent empty storefront into an explicit checklist item.

### EX-033 — Reported margin is notional — nothing reconciles it against what the reseller actually owes ArvanCloud

*🟠 high · Business viability · effort M · status `open`*

**Where:** `src/Admin/Menu.php:86-88, src/Pricing/BaseCosts.php:31-40`

**Evidence:** `SUM(margin)` on orders where margin = `customer_price - base_cost` (PricingEngine.php:68), and `base_cost` is whatever an admin typed, stamped `'admin @ ' . gmdate('Y-m-d')` (Admin/Actions.php:125) or seeded from a transcription of the public pricing page (BaseCosts.php:58). Because no billing API exists (API_INTEGRATION.md:56), no code anywhere reads the reseller's actual upstream invoice or spend.

**Impact:** The 'سود ناخالص' figure on the dashboard is a modelled number, not a P&L. If Arvan's real prices drift, a promotion applies, or a server runs 45 days instead of 30, the dashboard keeps reporting healthy margin while the reseller is losing money. A reseller cannot use this to decide markup levels, which is the single most important decision the tool exists to support.

**Fix:** Label the figure as estimated in the UI, add a `base_costs.updated_at` staleness warning on the dashboard (the column already exists and is shown on the pricing page), and add a manual 'upstream invoice this month' input so actual COGS can be diffed against modelled cost. Cheap, and it converts a vanity metric into a decision metric.

### EX-034 — Brand-color token does not propagate to the surfaces that carry the brand

*🟠 high · Visual design · effort M · status `open`*

**Where:** `assets/css/front.css:9-45,89-93,146,198,216,328,343,365,375,405; assets/css/admin.css:7`

**Evidence:** Assets.php:51 injects ':root{--arvrs-brand:<color>;}' and front.css:10-12 comments that 'the rest of the brand ramp derives from --arvrs-brand, so changing the reseller's brand color in settings recolors the whole UI coherently.' It does not. The hero (:146 'linear-gradient(130deg,#0a4a47 0%,#0c8a80 55%,#14bfb4 100%)'), auth aside (:375, same literal), gateway head (:405), the brand stat card (:343), active tab (:328, var(--arvrs-dark)), nav hover/active tints (:89 #e9f6f4, :90 #e6f8f6), header chip (:93 #eefaf8/#c9e8e4), product icon (:198 #e6f8f6), feature mark (:216), unread notification (:365 #f6fdfc) and the primary-button glow (:39 rgba(20,191,180,.32)) are all hardcoded teal. admin.css:7 hardcodes --brand:#14bfb4 and is never handed the reseller color at all.

**Impact:** White-labelling is the product's core promise. A reseller who picks purple gets purple primary buttons and plan radios sitting inside a teal hero, teal nav pills, a teal header chip, teal product icons and a teal shadow under the purple button - a visibly broken two-palette UI on the very first screen, and a wp-admin that stays teal regardless.

**Fix:** Replace every literal teal with color-mix derivations of --arvrs-brand (--arvrs-brand-surface for the #e6f8f6/#eefaf8 tints, --arvrs-grad-dark for the three hero/gateway gradients, --arvrs-dark derived from brand), and emit the same :root override on admin pages from Menu::assets so admin.css inherits it.

### EX-035 — Token scale defined then bypassed: grad-dark unused while its exact value is inlined twice; 32 literal radii vs 3 token uses

*🟠 high · Visual design · effort M · status `open`*

**Where:** `assets/css/front.css:34-45 vs :146,:343,:405 and 32 border-radius declarations`

**Evidence:** --arvrs-grad-dark: linear-gradient(135deg,#0a4a47,#0c8a80) is declared at :45 and referenced zero times, while :343 and :405 spell out that identical gradient literally. Radius: 4 tokens declared (:34-37), 3 total var(--arvrs-radius*) uses in the file; --arvrs-radius and --arvrs-radius-xl are used zero times, --arvrs-brand-3 zero times. Meanwhile 32 literal border-radius values appear spanning nine sizes (10/12/13/14/16/18/20/22/26px). There are no spacing or typography tokens at all: 22 distinct literal font-size values including five half-pixel sizes (11.5/12.5/13.5/14.5/15.5px). HACKATHON_READINESS.md:22 claims 'Sorkhab-verified tokens (radius 8/12, 40px buttons)' - grep finds no 8px radius on any rendered element (the only one is in dead .arvrs-wizard-check .num, admin.css:111) and no 40px control anywhere (buttons 46px :100, hero 48px, tabs 42px, inputs 46px).

**Impact:** The :root block reads as a design system but does not govern the stylesheet, so there is no single place to change rhythm, radius or type scale, and the documented token claim is falsifiable by a judge with ctrl-F. Radii drift by 1-2px between visually sibling components (button 13px, input 12px, tab 10px, card 18px, product card 20px, gateway 22px, hero 26px).

**Fix:** Collapse to one radius scale and use the tokens; add --space-* and --text-* tokens and replace the 22 literal sizes; either use --arvrs-grad-dark at :343/:405 or delete it; correct the readiness-doc token claim to what the CSS actually does.

### EX-036 — Three of five semantic status pills fail WCAG AA contrast at 11.5px bold

*🟠 high · Visual design · effort S · status `open`*

**Where:** `assets/css/front.css:30-33,300-305`

**Evidence:** Computed contrast for .arvrs-tag (font-size 11.5px, font-weight 800, so not 'large text'): success #12a150 on #ecfdf3 = 3.21:1; danger #e3305a on #fdeef2 = 3.86:1; info #0e7fd1 on #e8f4fd = 3.78:1. All below the 4.5:1 AA threshold. (warning #b45309 on #fffaeb = 4.62:1 passes; default passes.) spec.md:157 claims 'Accessibility: semantic HTML, labels, focus states, contrast per Sorkhab tokens' and HACKATHON_READINESS.md:25 marks accessibility basics as done.

**Impact:** These pills are rendered by Helpers::status_tag on every order row, service card, job row and health check in both the customer dashboard and wp-admin - the single most-repeated component in the product fails the accessibility claim the docs make for it.

**Fix:** Darken the three foregrounds to hit 4.5:1 on their own backgrounds (roughly #0a7c3d, #c1234a, #0a5f9e) or raise .arvrs-tag to 12.5-13px; re-check the same pairs in admin.css:61-66 where the tag is 11px.

### EX-037 — Admin declares Vazirmatn but never loads it - every admin page falls back to system fonts

*🟠 high · Visual design · effort S · status `open`*

**Where:** `assets/css/admin.css:26; src/Admin/Menu.php:68`

**Evidence:** admin.css:26 sets 'font-family: Vazirmatn, -apple-system, "Segoe UI", Tahoma, sans-serif' but the only @font-face rules in the repo are front.css:49-51, and Menu::assets (:63-69) enqueues 'arvrs-admin' => assets/css/admin.css with an empty dependency array and nothing else. front.css is enqueued only on plugin front pages (Front/Assets.php:33).

**Impact:** The whole reseller-facing admin - eight pages plus the 7-step onboarding wizard that the readiness doc leads with as UI/UX evidence - renders Persian in Segoe UI/Tahoma while the storefront renders Vazirmatn. The two halves of the product do not share typography, and the wizard's 24px/900-weight display headings (admin.css:97) fall back to a face that has no comparable weight.

**Fix:** Move the three @font-face declarations into a shared stylesheet enqueued by both entry points, or duplicate them at the top of admin.css.

### EX-038 — Customer wallet balance and ledger history ignore `is_demo` — demo money counts as real

*🟠 high · Data & analytics · effort S · status `fixed`*

**Closed (v1.1.0):** `Ledger::balance()`/`entries()` both take `$include_demo` (default: `Plugin::demo_mode()`) and filter `is_demo` in SQL. e2e: `'demo credit is excluded from the real-money view'`.
**Where:** `src/Wallet/Ledger.php:126-133 (and :160-164)`

**Evidence:** `Ledger::balance()` runs `SELECT direction, amount, type FROM ledger WHERE customer_id = %d` with no `is_demo` predicate; `Ledger::entries()` is likewise unfiltered. Only `reconciliation()` (:195) and `total_credit()` (:211) accept `$include_demo`. Schema.php:216-220 states the intent explicitly: "so demo money never counts as real once the reseller goes live."

**Impact:** Every per-customer money view is demo-blind: the front-end wallet card (templates/front/dashboard.php:75, shell-top.php:35), the REST balance endpoint (src/Rest/Routes.php:179-184), the admin customer list and customer-detail balances (src/Admin/Menu.php:157,171), the spending-limit gate (OrderService.php:89) and the entire policy staging chain (UsageSync::apply_policy → Ledger::balance). A site that demos with fake top-ups and then goes live shows those top-ups as spendable real credit forever. The `is_demo` dimension therefore covers 3 admin aggregates and 0 of the ~7 customer-facing money views.

**Fix:** Give `balance()`/`entries()` the same `$include_demo` parameter and default it to `Plugin::demo_mode()`, exactly as the dashboard already does at Menu.php:90 — one `WHERE is_demo = 0` clause reused across all callers.

### EX-039 — `negative_since_days` measures the last debit, not the start of the negative period — RESTRICTED is unreachable

*🟠 high · Data & analytics · effort M · status `fixed`*

**Closed (v1.1.0):** `Ledger::negative_since_days()` now walks the ledger backwards to the true crossing point instead of reporting the age of the newest debit. e2e: `'negative_since_days measures the crossing point, not the newest debit'`.
**Where:** `src/Wallet/Ledger.php:143-153`

**Evidence:** `SELECT MAX(created_at) ... WHERE customer_id = %d AND direction = 'debit'` — the comment concedes it is "days since the last entry that brought the balance to/below zero — the newest debit." `PolicyEngine::stage` only returns RESTRICTED when `$negative_since_days > $grace_days` (PolicyEngine.php:37-40), and usage sync is scheduled `hourly` (Activator.php:20) appending a `usage_debit` per closed hour.

**Impact:** Any customer with a live service gets a fresh debit every hour, so `MAX(created_at)` is always ~now and the function returns 0. The stage pins at GRACE indefinitely and never reaches RESTRICTED — which means `block_purchases` and `suspend_service` (PolicyEngine.php:65) never fire for exactly the population they exist for. The credit-policy enforcement tail is dead code driven by a data-model gap: nothing records when the balance crossed zero.

**Fix:** Either store a `negative_since` timestamp on `customer_rules` (set/cleared whenever the sign of the derived balance flips in `apply_policy`), or compute the true crossing point with a running-sum window query over the ledger. The current one-line approximation is not merely imprecise — it inverts the policy outcome.

### EX-040 — Derived balance pulls the whole ledger into PHP, and the customers list does it N+1 times

*🟠 high · Data & analytics · effort M · status `open`*

**Where:** `src/Wallet/Ledger.php:123-133; src/Admin/Menu.php:170-180`

**Evidence:** `balance()` fetches every ledger row for a customer (`SELECT direction, amount, type ... WHERE customer_id = %d`, no LIMIT) and sums in PHP via `derive()`. `Menu::customers()` calls `Ledger::balance($user->ID)` inside `foreach ($query->get_results() as $user)` — 20 users per page. `apply_policy` calls `balance()` then `negative_since_days()` which calls `balance()` again.

**Impact:** With hourly `usage_debit` rows, one service accrues ~8,760 ledger rows/year; a 3-year customer with 5 services is ~130k rows deserialized into PHP arrays on every wallet render, REST call, checkout spending-limit check, and once per row on the admin customers page. The same aggregate is already computed correctly in SQL in `reconciliation()` (:196-204), so the slow path is a choice, not a constraint. There is no snapshot/checkpoint row to bound the scan.

**Fix:** Add a SQL aggregate variant (`SUM(CASE WHEN direction='credit' ...)` grouped by type) for the hot path and keep `derive()` for unit testing; batch the customers-list balances into one `GROUP BY customer_id` query. If history growth matters, add a periodic checkpoint row and derive forward from it.

### EX-041 — No time dimension in any report — revenue, cost and margin are lifetime sums only

*🟠 high · Data & analytics · effort M · status `fixed`*

**Closed (v1.1.0):** `Reports\Reports::period/by_product/mrr/churn` give the admin dashboard a date-range selector and grouped queries; the ledger/orders CSV export from the same finding's "fix" is not yet built (tracked in `ROADMAP.md`).
**Where:** `src/Admin/Menu.php:86-88`

**Evidence:** `SELECT COALESCE(SUM(amount),0) FROM orders WHERE status IN ('paid','provisioning','active')` — with no date predicate, no `GROUP BY product`, no `GROUP BY customer_id`. The declared `KEY created_at` on `orders` (Schema.php:69) is referenced by zero queries in `src/` (verified by grep: only Notifier.php:53 and Ledger.php:146 filter on any `created_at`). There is no CSV/export code anywhere in `src/` or `templates/` (grep for csv|export: no hits).

**Impact:** A reseller cannot answer the questions a reseller must answer: revenue this month vs last, margin by product line, top customers by spend, growth trend, or receivables aging. The three KPI cards are cumulative-since-install numbers that only ever go up, and no data can leave the plugin for accounting or tax — the ledger is a well-built journal with no way to produce a statement.

**Fix:** Add a date-range selector driving `WHERE created_at BETWEEN` on the existing (currently unused) `orders.created_at` index, plus `GROUP BY product` and a top-customers-by-revenue query, and a CSV export of `ledger` and `orders` for a period. The schema already supports all of this; only the reporting layer is missing.

### EX-042 — Real-mode cloud-server plans are keyed by upstream flavor IDs, but BaseCosts is seeded only with demo plan IDs — the headline product is unsellable in real mode

*🟠 high · Integration honesty · effort M · status `open`*

**Where:** `src/Pricing/BaseCosts.php:59-63 vs src/Arvan/RealProvider.php:67-75`

**Evidence:** `RealProvider::plans('cloud_server')` builds each `Plan` with `$id = (string) ($size['id'] ?? '')` taken straight from `GET /regions/{region}/sizes`, then calls `BaseCosts::get($product, $id)`. `BaseCosts::seed_defaults()` seeds only `g1-1-1-25`, `g1-2-2-25`, `g1-4-4-50`, `g1-8-8-100` — the exact literal IDs hardcoded in `DemoProvider::plans()` (DemoProvider.php:27-30). Any upstream flavor ID that is not one of those four returns `0`, and `Front\Shortcodes.php:94-96` then skips it: "unpriced plans are not sellable — never advertise them". `OrderService.php:72` rejects them too.

**Impact:** The moment an operator switches to real mode, every cloud-server plan whose upstream ID is not literally `g1-1-1-25`-style disappears from the storefront; the product page renders an empty plan list and the storefront card shows `from = null`. CDN and Object Storage survive only because RealProvider hardcodes the same IDs the seed uses. This is exactly the drift the demo hides, and it is not listed in the README's "Known limitations" — which says base costs are "seeded from the public pricing page" without noting the seeded keys only match the demo catalog.

**Fix:** Either key cloud-server plans on `$size['name']` (which matches the `g1-*` naming the seed uses) with a documented fallback, or surface unpriced-but-live upstream plans in the admin Pricing screen as a "needs a base cost" list so the operator is told what to fill in instead of silently losing the catalog.

### EX-043 — Inline provisioning with up to 15 s of sleep() in the payment callback can strand an order in `provisioning` with a live upstream resource

*🟠 high · Integration honesty · effort M · status `open`*

**Where:** `src/Payments/PaymentService.php:91-97, src/Arvan/RealProvider.php:199-218, src/Provisioning/Provisioner.php:44-46`

**Evidence:** `PaymentService` enqueues the job then calls `Provisioner::provision()` inline in the callback request. In real mode that path performs `GET /networks`, `GET /securities`, `POST /servers`, then loops `for ($i = 0; $i < 5 && $ip === ''; $i++) { sleep(3); ... }` — up to 15 s of blocking sleep plus five more HTTP calls, each with `'timeout' => 20` and up to 3 attempts. `Provisioner` claims the order into `PROVISIONING` *before* the create call, and `OrderService::transition` can only re-claim from `PAID` or `PROVISION_FAILED` (Provisioner.php:44-45). `StateMachine::PROVISIONING => [ACTIVE, PROVISION_FAILED]` (StateMachine.php:27) and nothing in the codebase reaps a stale `provisioning` row.

**Impact:** If PHP's max_execution_time (commonly 30 s) kills the request after `POST /servers` succeeded but before `Services::create_for_order`, the order is permanently stuck in `provisioning`: the queued job returns "not claimable", which `JobRunner::execute` (JobRunner.php:113-116) explicitly treats as job success, and the admin's `retry_provision` action (Admin/Actions.php:268-271) routes through the same unclaimable path. Result: customer paid, server exists and bills upstream, no service row, no admin recovery short of SQL. DemoProvider returns instantly with no network and no sleep, so neither the e2e script nor any demo can reach this state.

**Fix:** Drop the inline call in real mode (or drop the `sleep()` poll and return status `creating`, letting `status()` complete it), and add a reaper that moves `provisioning` orders older than N minutes back to `provision_failed` after checking for an existing remote resource.

### EX-044 — Customer-supplied region/image are never validated against the offered catalog, and flavor IDs are region-scoped

*🟠 high · Integration honesty · effort S · status `open`*

**Where:** `src/Orders/OrderService.php:36-39 vs src/Arvan/RealProvider.php:64, 153`

**Evidence:** `sanitize_config` only does character filtering — `$out['region'] = sanitize_key($raw['region'] ?? '')` and `$out['image'] = preg_replace('/[^a-z0-9\.\-]/', '', strtolower(...))` — with no membership check against `Catalog::options($product)`. Meanwhile `plans()` fetches sizes from a single `default_region()` (`GET /regions/{region}/sizes`, RealProvider.php:64) while `options()` lists *every* region (RealProvider.php:113-115) and `create_server` honours `$config['region']` (RealProvider.php:153).

**Impact:** A customer can legitimately select a region other than the one the flavor list came from, pay, and only then have `POST /regions/{B}/servers` rejected because `flavor_id` belongs to region A — landing in `provision_failed` after money changed hands. A forged/mangled `image` id does the same. Neither is reachable in demo mode: `DemoProvider::create` writes `$config['image']`/`$config['region']` into the connection blob without validating anything, so every combination "works".

**Fix:** Validate `region` and `image` against `Catalog::options($product)` in `sanitize_config` (reject unknown values before creating the order), and either scope the region selector to the region the plan list was fetched from or fetch sizes per selected region.

### EX-045 — The headline E2E check count is stated as two different numbers across nine documents, and neither matches the code

*🟠 high · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** the headline counts are now stated once, in `TESTING.md`, which every other document points at instead of restating; `e2e.php` prints its own `check()` count at runtime (`$GLOBALS['checks']`) so the number cannot silently drift from what the script did.
**Where:** `docs/REQUIREMENTS_TRACEABILITY.md:3, HACKATHON_READINESS.md:9 vs :53, TESTING.md:32, DEVELOPMENT.md:79, docs/CAPACITY_MODEL.md:47, docs/performance/README.md:12, CHANGELOG.md:22, README.md:9`

**Evidence:** tests/integration/e2e.php contains exactly 53 top-level `check(...)` invocations (`grep -c '^check(' → 53`; the 54th `check(` match is the `function check(...)` declaration at line 30) and no loops. README badge, TESTING.md:32 ("**54 checks**"), HACKATHON_READINESS.md:9 ("54-check E2E") and CHANGELOG.md:22 say 54. DEVELOPMENT.md:79 ("42 checks"), REQUIREMENTS_TRACEABILITY.md:3 ("42 checks passing"), CAPACITY_MODEL.md:47, performance/README.md:12 and HACKATHON_READINESS.md:53 ("46U+42E") say 42. HACKATHON_READINESS.md contradicts itself within one file.

**Impact:** The most-cited evidence number in the submission is unverifiable: a reviewer who runs the script gets 53 and finds two competing published figures, which retroactively casts doubt on the other measured claims (158 assertions, 8s wall clock) that TESTING.md:3 promises "was actually executed". It is a self-inflicted credibility hit on documentation that is otherwise accurate.

**Fix:** Count the calls programmatically, publish that one number, and replace the nine hardcoded literals with it. Better: have e2e.php print `TOTAL n CHECKS` at line 234 and quote that output verbatim in TESTING.md so the number cannot drift again.

### EX-046 — Traceability matrix contains three provable errors, including a claim the code explicitly refutes

*🟠 high · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** `docs/REQUIREMENTS_TRACEABILITY.md` rewritten — wizard row now says 7 stages; the credit_limit/spending_limit row states the real split (spending_limit gates checkout, credit_limit does not); all E:-range citations replaced with grep-verifiable check names so the header count and the citations cannot disagree again.
**Where:** `docs/REQUIREMENTS_TRACEABILITY.md:9,16,3`

**Evidence:** Row 9 says "Onboarding wizard (8 stages, validated, Back/Continue)" but src/Onboarding/Wizard.php:23 defines `const STEPS = ['welcome','license','identity','arvan','pricing','pages','ready']` — 7, as README.md:27, ADR-0002 and CHANGELOG.md:11 all correctly state. Row 16 says "spending_limit & credit_limit enforced in `OrderService::create`", but src/Orders/OrderService.php:84 states: "credit_limit is NOT a checkout gate" and only `spending_limit` is checked (lines 89-94). The header declares "42 checks" while rows 16 and 27 cite evidence "E:43–44" and "E:45–46".

**Impact:** This is the document a judge uses to spot-check honesty. Two of its cells are false and a third contradicts its own header, so the ✅ column stops being trustworthy exactly where trust is the deliverable — and the credit_limit row asserts an enforcement the code deliberately does not perform.

**Fix:** Fix row 9 to 7 stages; rewrite row 16 as "spending_limit enforced at checkout (`OrderService::create`); credit_limit governs the policy engine's grace→restricted band, not checkout" — the code comment already contains the correct wording; reconcile the header count with the E: references.

### EX-047 — No admin form field is label-associated: `<label>` and `<input>` are siblings with no `for`

*🟠 high · Accessibility & i18n · effort M · status `open`*

**Where:** `templates/admin/branding.php:22-39, credentials.php:85-96, policies.php:17-24, pricing.php:20-27, customer-detail.php:39-64`

**Evidence:** Every admin field follows the pattern `<div><label>نام فروشگاه</label>\n  <input type="text" name="brand_name" /></div>` (branding.php:22-23). The label neither wraps the input nor carries `for`. A `rg -c "<label[^>]*for=" templates/admin/` returns exactly one file — `wizard.php:9` — while `<label>` appears in branding (9), customer-detail (10), credentials (8), policies (5) and pricing (3). `pricing.php:50-54` is worse still: `<select name="new_product">` and two inputs have no label element at all, only a `placeholder`.

**Impact:** A screen-reader user in wp-admin hears "edit, blank" for the brand name, support email, API token, markup percentage, warning threshold, negative-credit cap and spending limit. Roughly forty controls that configure pricing, credit policy and ArvanCloud credentials are unidentifiable without sighted context, and clicking the label text does not focus the field. This directly falsifies HACKATHON_READINESS.md:25, which claims "labels on every field ✅".

**Fix:** Add `id` to each admin input and `for` to its label, exactly as `templates/admin/wizard.php` and every front template already do. The wrapped checkboxes (`credentials.php:90,94`, `policies.php:40`) are already fine via implicit association and need no change.

### EX-048 — Shared status-tag palette fails WCAG AA in both stylesheets — success pill is 2.89:1

*🟠 high · Accessibility & i18n · effort S · status `open`*

**Where:** `assets/css/front.css (.arvrs-tag-* block), assets/css/admin.css:60-66`

**Evidence:** `.arvrs-tag-success { background: #ecfdf3; color: #12a150 }` computes to 2.89:1. `.arvrs-tag-info { #e8f4fd / #0e7fd1 }` = 3.78:1. `.arvrs-tag-danger { #fdeef2 / #e3305a }` = 3.87:1. `.arvrs-tag-default { #eef4f3 / #5c7876 }` = 4.29:1. All are rendered at `font-size: 11.5px` (front) / `11px` (admin) with `font-weight: 800` — normal text, so the AA floor is 4.5:1. Only `.arvrs-tag-warning` (#fffaeb/#b45309 = 4.81:1) passes. The same failure repeats on `.arvrs-amount-credit { color: #12a150 }` at 3.03:1 on white, 13.5px.

**Impact:** These pills are the sole carrier of order and service state — `Helpers::status_tag()` emits them for all twenty statuses including `pending_payment`, `provision_failed`, `suspended` and `blocked` (src/Support/Helpers.php:38-59), and they appear in every front and admin table. Low-vision users and anyone on a dim or glare-affected screen cannot reliably read whether an order failed or a service is suspended. spec.md:157 claims "contrast per Sorkhab tokens".

**Fix:** Darken the four failing foregrounds against their tinted backgrounds — e.g. success `#0a7038`, info `#0a5c99`, danger `#a81f42`, default `#405957` all clear 4.5:1 on the existing backgrounds with no layout change. Fix once in each `:root`/`.arvrs-admin` token block, since both stylesheets duplicate the same five values.

### EX-049 — Plugin never emits `lang`, and `dir="rtl"` is hardcoded — translation cannot change direction

*🟠 high · Accessibility & i18n · effort S · status `open`*

**Where:** `templates/front/partials/shell-top.php:14, front/auth.php:7, front/payment.php:14, admin/{wizard,services,pricing,policies}.php`

**Evidence:** Every root wrapper is a literal `<div class="arvrs-app" dir="rtl">`. A repo-wide search for `lang=`, `is_rtl()` or `get_locale()` across `templates/`, `assets/` and `src/` returns zero hits — the only matches are the `dir="ltr"` data-field overrides. `assets/css/front.css` reinforces this with `.arvrs-app { text-align: right }` and `.arvrs-table th/td { text-align: right }`.

**Impact:** Two distinct failures. (a) WCAG 3.1.1/3.1.2: on a site whose `<html lang>` is `en-US` (the WordPress default), every Persian string is spoken by an English voice — the whole storefront becomes unintelligible to a screen-reader user. (b) The plugin is not actually internationalized: translate it to English or Arabic-vs-Persian and the layout is still forcibly RTL with right-aligned tables, so the `__()` wrapping buys nothing.

**Fix:** Emit `dir="<?php echo is_rtl() ? 'rtl' : 'ltr'; ?>" lang="<?php echo esc_attr(str_replace('_','-',get_locale())); ?>"` on each `.arvrs-app`/`.arvrs-admin` wrapper, and swap the two `text-align: right` rules for `text-align: start` — the file already uses `margin-inline`, `padding-inline-start` and `margin-inline-start` elsewhere, so the logical-property habit exists.

### EX-050 — THREAT_MODEL S5 "DB dump alone is useless" is false on any install without salt constants in wp-config.php

*🟡 medium · Security · effort S · status `open`*

**Investigated, not closed (v1.1.0):** `SECURITY.md` and `docs/THREAT_MODEL.md` S5 have been corrected to state the real precondition (file-defined vs. DB-fallback salts) instead of asserting the stronger claim unconditionally. The code fix this finding actually asks for — detect the DB-fallback case and surface it on the System Health page, refuse/warn on credential save when it's true — has not been made; `src/Support/Crypto.php` is unchanged. Tracked in `ROADMAP.md` v1.2.

**Where:** `src/Support/Crypto.php:22-26`

**Evidence:** `$material = wp_salt('auth') . '|' . wp_salt('secure_auth'); return hash_hmac('sha256', self::CONTEXT, $material, true);` — the key is derived entirely from `wp_salt()`. WordPress's `wp_salt()` only reads the `AUTH_KEY`/`AUTH_SALT`/`SECURE_AUTH_KEY`/`SECURE_AUTH_SALT` constants when they are defined and not the placeholder value; otherwise it falls back to `get_site_option("{$scheme}_key")` / `_salt` and, if absent, generates and persists them with `update_site_option()` — i.e. into `wp_options`. SECURITY.md:45 states the key is "never stored on disk" and THREAT_MODEL.md:41 states the residual risk requires "BOTH the DB and `wp-config.php` salts".

**Impact:** On a WordPress install where salts were not written into wp-config.php (managed hosts that auto-provision, hand-rolled installs, restored-from-migration sites), the sodium key material and the `token_enc` ciphertext live in the *same* database. A single DB dump — the exact M3 adversary the threat model names — decrypts every stored ArvanCloud API token, which grants full control of the reseller's upstream account. The plugin never checks or surfaces which case it is in; the Health page (src/Admin/Menu.php:252) reports `sodium` availability only.

**Fix:** Add a `defined('AUTH_SALT') && AUTH_SALT !== 'put your unique phrase here'` check, surface it as a red row on the Health page next to the sodium check, and refuse to save new credentials (or loudly warn) when it fails. Correct the SECURITY.md/THREAT_MODEL wording to say the key derives from salts *when they are file-defined constants*.

### EX-051 — POST /me/topup is unrate-limited and writes a permanent wp_options row per call

*🟡 medium · Security · effort S · status `open`*

**Where:** `src/Rest/Routes.php:89-99 + src/Payments/PaymentService.php:107-115`

**Evidence:** The `/me/topup` route body checks `PaymentService::sandbox_blocked()` and calls `start_topup(...)` with no `Helpers::rate_limit()` guard — unlike `/checkout` (`rate_limit('checkout:'.$customer_id, 20, 300)`, Routes.php:126) and `/payment/callback` (`rate_limit('callback:'.client_ip(), 30, 300)`, Routes.php:156). `start_topup` then does `add_option('arvrs_topup_' . $ref, [...], '', false)` for every call, and nothing ever deletes those rows — the only cleanup is the `DELETE … LIKE 'arvrs\_topup\_%'` in uninstall.php:24. SECURITY.md:71 lists the rate-limited endpoints as "callback, checkout, login/register", omitting this one.

**Impact:** Any registered customer (registration is open and self-service) can loop `POST /me/topup` and add unbounded permanent rows to `wp_options` — one per request, never garbage-collected, surviving until plugin uninstall. That is authenticated storage exhaustion plus degradation of every `get_option`/`wp_load_alloptions` path on the site, from an endpoint whose neighbours are all throttled. The intent rows also accumulate silently after every successful top-up, so even normal use leaks rows.

**Fix:** Add `Helpers::rate_limit('topup:'.get_current_user_id(), 10, 300)` to the route callback, and `delete_option('arvrs_topup_'.$ref)` in `handle_topup_callback` once the ledger row is written (the ledger unique key already carries replay safety, so the intent row is not needed afterwards). Sweep stale intents older than a day in the existing cron job.

### EX-052 — Refund credits the wallet without confirming the original payment was ever ledgered

*🟡 medium · Security · effort S · status `open`*

**Where:** `src/Admin/Actions.php:286-292 + src/Payments/PaymentService.php:75-82`

**Evidence:** In `handle_order_callback` the payment/purchase ledger pair is wrapped in `try { Ledger::append(...payment...); Ledger::append(...purchase...); } catch (\Throwable $e) { Audit::error('ledger.payment_append_failed', ...); }` — the comment states "a transient DB failure here must NOT strand a paid order: log it and continue", so the order reaches PAID/ACTIVE with no ledger entries. `Actions::order_action` then refunds purely on order state: `if (!StateMachine::can($from, StateMachine::REFUNDED))` … `Ledger::append((int)$order['customer_id'], 'refund', (int)$order['amount'], 'order_refund', (string)$order['payment_ref'], ...)` — it never checks that a matching `('order', $payment_ref, 'payment')` row exists.

**Impact:** Because the normal payment pair nets to zero on the wallet (credit `payment` + debit `purchase`), a refund is a net `+amount` wallet credit that assumes the pair was written. If the pair was dropped by the swallowed exception (deadlock, disk-full, connection loss — exactly the conditions the catch exists for), refunding that order mints wallet credit backed by nothing, which the customer can then spend on further provisioning. The audit trail records the discrepancy but nothing blocks the action.

**Fix:** Before appending the refund, require the settlement row: `SELECT id FROM ledger WHERE ref_type='order' AND ref_id=%s AND type='purchase'`; if absent, refuse with a notice pointing the admin at the reconciliation view. Alternatively make the payment-pair failure fatal to the callback (return `ok=false`) so the gateway retries — the ledger unique key already makes that retry safe.

### EX-053 — Two SECURITY.md control claims are not true of the code (get_owned has no production callers; not every route has an args schema)

*🟡 medium · Security · effort S · status `fixed`*

**Closed (v1.1.0):** `Services::get_owned()` now has production callers (`Rest\Routes::me_service`, `Front\FormActions::cancel_service`), not just tests; `/me/summary` declares `'args' => []` explicitly and the two id-taking routes declare the shared `ID_ARG` schema.
**Where:** `SECURITY.md:17,70 vs src/Services/Services.php:63 and src/Rest/Routes.php:74-77,101-107`

**Evidence:** SECURITY.md:17 lists under Object-level authorization: "`Services::get_owned()` centralizes row-ownership checks." Grepping the whole repo, the only callers of `get_owned` are `tests/integration/e2e.php:129-130` — no file in `src/` calls it. SECURITY.md:70 states "Every route declares a `permission_callback` **and an `args` schema with types/enums/ranges**"; `/me/summary` (Routes.php:74-77) and `/me/notifications/(?P<id>\d+)/read` (Routes.php:101-107) declare no `args` key at all. THREAT_MODEL.md:33 and README.md:148 repeat the `get_owned` claim.

**Impact:** Neither gap is currently exploitable — the ownership guarantee actually comes from session-derived IDs in `me_list` and the SQL-scoped `mark_read` — but a reviewer who trusts the control inventory will believe a centralized ownership helper is enforcing single-row access. The first future endpoint that takes a service ID (a "view service" or "cancel service" route) will be written against a document that says the check is already centralized, when in fact nothing calls it. A false entry in a control inventory is a latent authorization bug.

**Fix:** Either wire `get_owned` into a real single-row path or delete the claim from SECURITY.md/THREAT_MODEL/README and say plainly that all customer reads are list-scoped by session ID. Add `args` to the two routes (`page`-less `/me/summary` needs none, so soften the claim to "every route that accepts parameters") — the `(?P<id>\d+)` regex plus `(int)` cast is adequate, but the doc should match.

### EX-054 — Composition root is a service locator that seven modules depend on, creating the cycles ARCHITECTURE.md denies

*🟡 medium · Architecture · effort M · status `fixed`*

**Closed (v1.1.0, doc):** `ARCHITECTURE.md` no longer claims "no circular dependencies" — it now names `Plugin` as a composition root that is also a service locator Application modules call back into, and the ownership table lists the real (`Plugin`, cross-module) edges instead of the intended ones. The underlying code still has the coupling described (not a code fix), which is now what the document says.
**Where:** `src/Plugin.php:60-82 (consumers: Wallet/Ledger.php:47, Arvan/Catalog.php:42,50,60,66, Orders/OrderService.php:110, Provisioning/Provisioner.php:52,55, Usage/UsageSync.php:51, Payments/PaymentService.php:35,58,114, Admin/Menu.php:85)`

**Evidence:** ARCHITECTURE.md:49 states "No circular dependencies". But `Plugin::arvan()` constructs `Arvan\RealProvider`/`DemoProvider` while `Arvan\Catalog` calls `Plugin::arvan()` and `Plugin::demo_mode()` — Plugin ↔ Arvan. Likewise `Plugin::payments()` constructs `Payments\SandboxProvider` while `Payments\PaymentService` calls `Plugin::payments()` — Plugin ↔ Payments. ADR-0001's stated mitigation is also stale: "only `Plugin::arvan()`/`Plugin::payments()` exist; everything else is explicit" — yet `Plugin::demo_mode()` is consumed in six modules including the ledger write path.

**Impact:** Nothing below Presentation can be constructed or tested without the root and its ambient global state (demo mode is re-derived from options + DB at every call site). This is exactly the "static accessors rot into hidden globals" risk ADR-0001 names, and it is why the unit suite covers only the four pure classes plus Crypto/License — `Ledger::append`, `OrderService::create`, `Catalog` and `Provisioner` are structurally untestable without a full WP install.

**Fix:** Pass the provider into `Catalog`/`Provisioner`/`UsageSync` as an argument (they all already know the product), and move the demo flag to an explicit `Support\Mode::is_demo()` in Support so no Application/Infrastructure module imports the root. Then update ARCHITECTURE.md:49 or make the claim true.

### EX-055 — Presentation layer runs raw SQL against another module's table to fetch an encrypted secret

*🟡 medium · Architecture · effort S · status `open`*

**Where:** `src/Admin/Actions.php:202-204, src/Admin/Menu.php:80-89`

**Evidence:** `Actions::credential_test()` does `global $wpdb; $enc = $wpdb->get_var($wpdb->prepare('SELECT token_enc FROM ' . Credentials::table() . ' WHERE id = %d', $id));` then decrypts it itself — because `Credentials` exposes no token accessor (only `all()`, which strips tokens, and `select_for($product)`, which cannot target an id). `Menu::dashboard()` similarly runs five aggregate `SELECT`s against `OrderService::table()` directly.

**Impact:** Secret handling leaks out of the module that owns it, so any future change to storage/encryption must be chased into the admin layer; and the ownership rule in ARCHITECTURE.md:32-47 (`Arvan` owns credentials, `Orders` owns order rows) is not actually held by the code. It also means `Support\Crypto` decrypt calls appear in a request handler rather than behind the credential API.

**Fix:** Add `Credentials::token_for(int $id): ?string` and `OrderService::revenue_summary(bool $include_demo)`; delete `global $wpdb` from `src/Admin/` entirely (currently 3 occurrences).

### EX-056 — Jobs (Infrastructure) hard-codes dispatch into Application modules, inverting the declared layer direction

*🟡 medium · Architecture · effort S · status `open`*

**Where:** `src/Jobs/JobRunner.php:108-124`

**Evidence:** `execute()` is a `switch` that calls `\ArvanReseller\Provisioning\Provisioner::provision()` and `\ArvanReseller\Usage\UsageSync::sync_all()`. ARCHITECTURE.md:24 states the allowed direction is "Presentation → Application → Infrastructure" and places `Jobs` in Infrastructure while `Provisioning`/`Usage` are Application. The ownership table (line 43) quietly contradicts the layer rule 19 lines above it.

**Impact:** The queue cannot be extracted or reused without dragging the business modules with it — which undercuts ADR-0011's Stage C claim that `Jobs` is a clean extraction seam ("a worker polls the same table"). Adding a job type requires editing an infrastructure file.

**Fix:** Register handlers at boot from the owning modules (`JobRunner::handle('provision_order', [Provisioner::class,'provision'])` called from `Plugin::boot`), or expose a filter. The composition root that should own this wiring already exists.

### EX-057 — Demo-mode check issues an uncached DB query on every ledger write and catalog read

*🟡 medium · Architecture · effort S · status `open`*

**Where:** `src/Plugin.php:49-57, src/Wallet/Ledger.php:47, src/Usage/UsageSync.php:116-125`

**Evidence:** `Plugin::demo_mode()` returns early only when the option is `true`; in live operation it falls through to `Credentials::has_verified_credential()`, which is `$wpdb->get_var('SELECT id FROM ... WHERE enabled = 1 AND last_ok_at IS NOT NULL LIMIT 1')` — not memoized and not cached (unlike `get_option`). `Ledger::append()` calls it for every single row, inside the `UsageSync::ingest` loop.

**Impact:** A usage sync ingesting N records issues N extra credential queries; `Menu::dashboard()` alone triggers it three times per page load; `Catalog::plans/options` twice per product. Cheap per call, but it puts an unbounded uncached query in the money write path for no functional reason.

**Fix:** Memoize in a static on `Plugin` for the request (mode cannot change mid-request), or cache `has_verified_credential()` in a transient invalidated by `Credentials::record_test`/`save`/`delete`.

### EX-058 — `credit_limit` is an admin form field that is persisted and never read by any decision path

*🟡 medium · Product completeness · effort S · status `open`*

**Where:** `src/Customers/Rules.php:58`

**Evidence:** `credit_limit` is written by `Rules::save`, has a column (`Schema.php:189`) and an admin input labelled «سقف اعتبار منفی (تومان)» (templates/admin/customer-detail.php:45-46). A repo-wide grep for `credit_limit` returns only the write path, the schema, the admin input, and comments saying it is *not* a checkout gate (OrderService.php:84-87) — `PolicyEngine::stage()` takes balance/thresholds/grace only and never receives it (UsageSync.php:137-143).

**Impact:** A reseller who sets a per-customer negative-credit cap gets no behaviour change whatsoever, and `docs/REQUIREMENTS_TRACEABILITY.md:16` asserts the opposite: "spending_limit & credit_limit enforced in `OrderService::create`". That is a documented-but-absent feature and a misleading traceability row.

**Fix:** Either pass the per-customer `credit_limit` into `PolicyEngine::stage()` as the restricted threshold (a few lines in `UsageSync::apply_policy`), or delete the field and its admin input and correct the traceability row.

### EX-059 — Per-product credential routing can hand a product-restricted credential to the wrong product

*🟡 medium · Product completeness · effort S · status `open`*

**Where:** `src/Arvan/Credentials.php:112-121`

**Evidence:** In `select_for()`, after the explicit product match fails, the loop runs `if ($fallback === null && empty($products)) { $fallback = $candidate; }` **followed by** an unconditional `if ($fallback === null) { $fallback = $candidate; }`. The second branch fires on the same iteration for a credential whose `products` list is non-empty and does not contain the requested product.

**Impact:** With a first-ordered credential restricted to `cdn` and a second unrestricted credential, `select_for('cloud_server')` returns the CDN-only credential — the plugin provisions a server against an account the admin explicitly scoped away from that product, and the mis-routing is then recorded in `services.credential_id` and in the per-credential reconciliation view. README advertises "per-product routing, priority and health tracking".

**Fix:** Make the restricted-credential fallback a last resort: collect unrestricted candidates first and only fall back to a restricted one if no unrestricted credential exists (or refuse and raise a ProviderError, which is safer for money-bearing routing).

### EX-060 — No service-termination or status-refresh path exists; `ProviderInterface::delete()`/`status()` are dead code

*🟡 medium · Product completeness · effort M · status `open`*

**Where:** `src/Arvan/ProviderInterface.php`

**Evidence:** Both providers implement `status()` and `delete()` (RealProvider.php:274-309, DemoProvider.php:124-139), but a grep for `->delete(`/`->status(` across `src/` matches only `$wpdb->delete` in Credentials.php:71. `Services::set_status()` (Services.php:97) is likewise never called — `UsageSync` writes service statuses with raw SQL. The admin refund action credits the wallet and moves the order to `refunded` (Admin/Actions.php:277-296) without touching the service row or the remote resource, and the cancel button is only rendered for `pending_payment`/`payment_processing` (templates/admin/order-detail.php:56).

**Impact:** spec §5.2 lists `active → cancelled (service termination)` as an authoritative transition and `Services` defines a `cancelled` status, but no UI or code path reaches either. After a refund the remote ArvanCloud resource keeps running, keeps being billed to the reseller, keeps appearing as active in the customer dashboard, and keeps accruing usage debits. The service record is also write-once — a resource deleted or failed upstream never updates locally.

**Fix:** Wire one admin action on the service/order detail page that calls `Plugin::arvan($product)->delete()` then `Services::set_status($id,'cancelled')` behind a confirm, and call it from the refund flow for `active` orders; a periodic `status()` reconcile job would close the drift loop.

### EX-061 — Provisioner ignores the service-insert result and transitions to ACTIVE regardless

*🟡 medium · Reliability · effort S · status `open`*

**Where:** `src/Provisioning/Provisioner.php:74-75, src/Services/Services.php:34-41`

**Evidence:** `$service_id = Services::create_for_order(...); OrderService::transition($order_id, PROVISIONING, ACTIVE, ...);` — the return value is never checked. `create_for_order` returns `(int) $wpdb->get_var(...)` when `rows_affected` is 0, which yields 0 for a genuine INSERT failure (disk full, deadlock, column truncation) exactly as it would for a benign duplicate; there is no `last_error` check, unlike the equivalent code in Ledger::append.

**Impact:** On a failed service INSERT the order is still marked ACTIVE with a remote resource that was really created. The customer's dashboard shows an active order and zero services; usage attribution has no row to map to; `Services::by_order()` returns null so a later retry would try to provision a *second* resource. The Provisioner also reports `['ok' => true, 'service_id' => 0]`, so the job records success.

**Fix:** Check `$service_id > 0` (and `$wpdb->last_error`) before the ACTIVE transition; on failure throw so the job retries — the UNIQUE(order_id) plus the `by_order` guard make the retry safe.

### EX-062 — A failed claim is always reported to the caller as a successful replay, including on amount mismatch

*🟡 medium · Reliability · effort S · status `open`*

**Where:** `src/Payments/PaymentService.php:64-68, src/Orders/OrderService.php:165-191`

**Evidence:** `claim_paid` returns null for two distinct reasons — the row is no longer payable (real replay) or `amount = %d` did not match the verified amount — and the caller collapses both: `if (!$claimed) { return ['ok' => true, 'replay' => true, 'message' => 'این پرداخت قبلاً پردازش شده است.', ...]; }`.

**Impact:** If the order amount ever diverges from the amount that was verified (admin edit, re-priced row, a real gateway that settles a partial amount), the customer and the gateway are told the payment succeeded and was already processed, while the order remains in `pending_payment` with no service, no ledger row and no alert. The mismatch is not even audited — only `payment.verify_failed` is logged, and that branch was not taken.

**Fix:** Have `claim_paid` distinguish the two cases (re-read the row and compare status vs amount) and return a mismatch as `ok:false` with an `Audit::error('payment.amount_mismatch', ...)` so it lands on the admin's radar instead of being reported as success.

### EX-063 — The entire idempotency model rests on unique indexes that migration never verifies, plus an unguarded full-table backfill

*🟡 medium · Reliability · effort M · status `open`*

**Where:** `src/Install/Schema.php:212-229`

**Evidence:** Replay safety depends on `UNIQUE KEY uniq_ref (ref_type,ref_id,type)` (line 119), `UNIQUE KEY order_id` (line 99) and `UNIQUE KEY uniq_period` (line 136), all created only via `dbDelta($sql)` in a bare loop with no return inspection. dbDelta cannot add a UNIQUE index to an existing table that already contains duplicates — it fails and moves on. The v3→v4 step then issues `$wpdb->query('UPDATE ' . $p . 'ledger SET is_demo = 1')` with no LIMIT, no batching and no result check, and `update_option('arvrs_schema_version', ARVRS_SCHEMA_VERSION)` runs unconditionally afterwards.

**Impact:** If any unique index silently fails to materialize on an upgraded site, `INSERT IGNORE` degrades to plain INSERT and every replay guarantee in the plugin evaporates — duplicate ledger credits, duplicate services, double-debited usage — with nothing detecting it. The schema version is stamped as migrated either way, so `maybe_migrate()` never retries. The unbounded ledger UPDATE also risks a lock/timeout on a large table, after which the version is still marked complete.

**Fix:** After the dbDelta loop, assert the critical indexes exist (`SHOW INDEX FROM ... WHERE Key_name = 'uniq_ref'`) and surface a hard admin health failure if missing; only `update_option` the version once the assertions pass. Batch the is_demo backfill with a LIMIT loop.

### EX-064 — Payment callbacks are rate-limited per client IP at 30/5min — a real gateway is one IP

*🟡 medium · Reliability · effort S · status `open`*

**Where:** `src/Rest/Routes.php:156-158`

**Evidence:** `if (!Helpers::rate_limit('callback:' . Helpers::client_ip(), 30, 300)) { return new \WP_Error('rate_limited', 'Too many callbacks', ['status' => 429]); }`. `Helpers::rate_limit` (Helpers.php:82-91) is a transient counter keyed on the caller's IP, and it is also read-then-write, so it is not atomic under concurrency.

**Impact:** Server-to-server gateway callbacks all arrive from a small set of gateway IPs, so the budget is shared across all customers: past ~6 settlements/minute, legitimate payment confirmations are rejected with 429. Money is captured at the gateway while the order stays `pending_payment` until (and unless) the gateway's own retry policy re-delivers within the window. Browser-return callbacks from behind one corporate NAT hit the same ceiling.

**Fix:** Key the limit on the payment ref rather than the IP (a few attempts per ref is the real abuse signal), or exempt verified callbacks entirely — `verify()` is already the authenticity gate the route's own comment relies on.

### EX-065 — Any logged-in non-customer (the reseller previewing their own store) is trapped in an auth → dashboard → auth loop

*🟡 medium · UX & usability · effort S · status `open`*

**Where:** `src/Front/Shortcodes.php:114, 130, 151-154; src/Front/FormActions.php:20-21, 68-72`

**Evidence:** ctx() sets customer_id only when `Customers::is_customer()` (Shortcodes.php:39). For a logged-in administrator, dashboard() renders front/require-login, which shows 'برای ادامه وارد شوید' linking to the auth page (require-login.php:13-15). auth() also sees is_customer()===false, so it renders the login form; submitting it hits the logged-in handler admin_post_arvrs_login → `already()` → redirect to the dashboard → require-login again.

**Impact:** The reseller testing their own storefront while logged into WordPress — the single most likely first-run behaviour after the wizard's 'پیش‌نمایش فروشگاه' link — hits an unbreakable loop with no message explaining that their WP account is not a customer account.

**Fix:** In require-login/auth, detect `is_user_logged_in() && !is_customer()` and render a distinct state: 'شما با حساب مدیریت وارد شده‌اید' with 'خروج و ورود به‌عنوان مشتری' and a link back to wp-admin.

### EX-066 — No password reset anywhere, and a failed registration returns the user to the login tab with all fields lost

*🟡 medium · UX & usability · effort S · status `open`*

**Where:** `templates/front/auth.php:19-65, src/Front/FormActions.php:25-29, 57-59`

**Evidence:** Grep for `lostpassword|فراموش|wp-login|wp_login_url` across the repo returns no matches — the auth card offers only ورود / ثبت‌نام / 'بازگشت به فروشگاه'. On a registration failure, `back('arvrs_error', $result->get_error_message())` redirects to the auth page carrying only the message; auth.php:20-21 always renders the login tab active with the register panel `hidden`, so 'با این ایمیل قبلاً ثبت‌نام شده است. وارد شوید.' (Customers.php:68) appears above the login form while the name/email the user typed are gone.

**Impact:** A forgotten password is a hard dead end inside the branded storefront — the customer must discover the unbranded wp-login.php on their own. And every registration error costs the user their typed values plus a tab-switch they were not told to make.

**Fix:** Add a 'فراموشی گذرواژه' link to wp_lostpassword_url() in auth.php, and pass a `tab=register` flag plus the submitted display_name/email back on the redirect so the correct panel opens pre-filled.

### EX-067 — All dates shown to Persian users are raw Gregorian UTC strings; usage periods are rendered by substring surgery

*🟡 medium · UX & usability · effort M · status `open`*

**Where:** `templates/front/dashboard.php:132, 153, 191, 213; src/Support/Helpers.php:73-76`

**Evidence:** Grep for `jalali|shamsi|date_i18n|wp_date` across the repo returns no matches. Timestamps come from `current_time('mysql', true)` (UTC) and are printed as `Helpers::fa_digits((string) $service['created_at'])` — e.g. '۲۰۲۶-۰۸-۲۰ ۱۴:۳۰:۰۰'. The usage table does `substr($row['period_start'], 5, 11) . ' → ' . substr($row['period_end'], 11, 5)` (dashboard.php:213), which renders '۰۸-۲۰ ۱۴:۳۰ → ۱۴:۳۰' and silently hides the end date whenever a period spans days.

**Impact:** An Iranian customer reading a billing screen gets a Gregorian calendar in Persian digits, shifted 3.5 hours from local time, and a usage period whose end date is truncated away — directly undermining trust in the money numbers next to it.

**Fix:** Add a Helpers::datetime() that converts to site timezone and Jalali (or at minimum wp_date() with the site offset), and replace the substr pair with two formatted dates.

### EX-068 — The post-purchase service card shows raw English snake_case keys as labels

*🟡 medium · UX & usability · effort S · status `open`*

**Where:** `templates/front/dashboard.php:125-131, src/Arvan/DemoProvider.php:91-111`

**Evidence:** The connection block prints `esc_html($conn_key)` verbatim; the provider supplies keys `ip`, `user`, `image`, `region`, `password_hint`, `ns1`, `ns2`, `bucket`, `endpoint`, `access_key_hint`. So the most important screen after payment shows a Persian card labelled 'password_hint' and 'access_key_hint'. There is no copy-to-clipboard, and no next-step guidance for a non-expert holding an IP and the user 'root'.

**Impact:** The single moment the customer is most likely to screenshot or act on reads as untranslated developer output, and a non-cloud-expert is left to work out how to connect on their own.

**Fix:** Map connection keys to Persian labels (a small array beside Helpers::status_tag), add a copy button per value, and add one line of product-specific guidance ('برای اتصال SSH: ssh root@<ip>' / 'رکوردهای NS دامنه را به ns1/ns2 تغییر دهید').

### EX-069 — Product navigation vanishes on phones with no replacement

*🟡 medium · UX & usability · effort S · status `open`*

**Where:** `assets/css/front.css:443-445`

**Evidence:** `@media (max-width: 640px) { ... .arvrs-nav { display: none; } ... }` hides the سرور ابری / CDN / فضای ابری links from the sticky header (templates/front/partials/shell-top.php:27-31). No hamburger, drawer or bottom bar replaces them; the only other cross-product route is the storefront's three cards.

**Impact:** On mobile — the dominant traffic for an Iranian retail storefront — a customer on the dashboard or a product page cannot reach another product without first noticing that the brand mark is a link back to the storefront.

**Fix:** Keep the nav as a horizontally scrollable strip below the header at ≤640px (the same overflow-x pattern already used for .arvrs-tabs, front.css:317-319), or add a details/summary disclosure menu.

### EX-070 — Three E2E checks cannot fail or assert something weaker than their own label

*🟡 medium · Testing & QA · effort S · status `open`*

**Where:** `tests/integration/e2e.php:117, :123, :232`

**Evidence:** :117 `check('policy stage computed', in_array($stage, ['healthy','warning','critical','grace','restricted'], true))` — PolicyEngine::stage() only ever returns one of these five constants, so the assertion is unfalsifiable. :123 `check('low-balance notification created once', (int) $notes >= 1)` — '>= 1' cannot detect the duplicate the label rules out. :232 `check('demo-mode ledger rows are is_demo stamped', $demo_ledger > 0)` counts rows WHERE is_demo=1 in a run where demo_mode was true throughout, and never asserts the complement `COUNT(*) WHERE is_demo = 0` is zero.

**Impact:** Three of 53 checks are decorative. Worse, the labels are what a reader (or judge) audits against: 'created once' and 'reconciliation isolation' both read as invariants that are not being tested. The cooldown is in fact tested one line later at :126, but the stamping complement is tested nowhere — demo/real ledger mixing, listed at TESTING.md:41 as a defect the review round found, has no assertion that can catch its return.

**Fix:** Assert the expected stage exactly (compute the balance and pin 'warning'/'critical'), change :123 to `=== 1`, and add `COUNT(*) FROM ledger WHERE is_demo = 0` equals 0 for the demo run.

### EX-071 — No negative authentication or authorization tests anywhere: isolation is only ever tested between two logged-in customers

*🟡 medium · Testing & QA · effort M · status `open`*

**Where:** `tests/integration/e2e.php:128-142; src/Rest/Routes.php:36; src/Front/FormActions.php:33,49,76`

**Evidence:** Every isolation check runs after `wp_set_current_user($alice)` or `($bob)`. `grep -n "wp_set_current_user(0)\|401\|403\|rest_forbidden" tests/integration/e2e.php` returns nothing. The customer permission_callback (`is_user_logged_in() && Customers::is_customer()`, Routes.php:36) is never observed rejecting anyone, and the three `check_admin_referer()` CSRF guards in FormActions (login :33, register :49, logout :76) are exercised by no test at all. No test asserts a customer cannot reach an admin action.

**Impact:** The suite proves customer B sees customer A's data as an empty list; it does not prove an anonymous or non-customer principal is refused, nor that a missing/forged nonce blocks login, registration, logout, checkout or top-up. A permission_callback accidentally set to __return_true, or a dropped check_admin_referer, ships green — and those are one-token regressions.

**Fix:** Add checks that dispatch /me/services and /me/orders with `wp_set_current_user(0)` asserting 401/403, one asserting a subscriber-without-customer-role is refused, and unit-level tests of the form handlers with an invalid nonce.

### EX-072 — SQLite-only integration structurally hides the exact MySQL failure mode Ledger::append is written to defend against

*🟡 medium · Testing & QA · effort M · status `open`*

**Where:** `src/Wallet/Ledger.php:63-71; TESTING.md:54`

**Evidence:** Ledger::append distinguishes duplicate from failure purely by `rows_affected === 0` plus `$wpdb->last_error !== ''`, commenting 'a dropped credit is silent money loss'. On MySQL, INSERT IGNORE downgrades data errors — value truncation on `ref_id varchar(191)`, invalid datetime, out-of-range int — to *warnings*, giving rows_affected=0 with last_error empty, so append returns 0 and the caller (PaymentService.php:140) reports a benign 'replay'. The SQLite integration is dynamically typed and does not truncate, so this branch is unreachable there. TESTING.md:54 frames the gap as merely 'same SQL dialect subset'.

**Impact:** The single code path that can silently swallow a customer's top-up is untestable on the platform the tests run on, and the documentation understates the gap as a dialect detail rather than a divergence in the semantics the money logic depends on. Also unverified on SQLite: utf8mb4 collation for the Persian text fields, dbDelta index-length behaviour on the 191-char unique keys, and MySQL's affected-rows-vs-matched-rows semantics used by claim_paid.

**Fix:** Add a unit test with a fake $wpdb that returns rows_affected=0 with and without last_error and pins both branches (return 0 vs throw), and run the E2E once on MySQL — or amend TESTING.md:54 to name truncation/warning semantics explicitly instead of 'same SQL dialect subset'.

### EX-073 — Zero concurrency tests for a design whose entire correctness argument is about races

*🟡 medium · Testing & QA · effort M · status `open`*

**Where:** `src/Wallet/Ledger.php:49; src/Orders/OrderService.php:179-191; src/Provisioning/Provisioner.php:35-48`

**Evidence:** The code advertises race safety in comments — 'INSERT IGNORE + unique key = atomic idempotency without SELECT-then-INSERT races' (Ledger.php:49), 'Raced replay: someone else claimed between our read and update' (PaymentService.php:66), 'Losing the claim = another worker owns it right now' (Provisioner.php:42-43). Every test in tests/ is strictly sequential; there is no parallel dispatch, no simulated interleave, and no test that two workers claiming the same order produce one service.

**Impact:** The failure the whole idempotency design targets — two gateway callbacks or two job workers arriving at once — is the one scenario never simulated. Sequential replays take the state-machine short-circuit instead (see finding 1), so the concurrent branch at PaymentService.php:65-68 (`$claimed` null) is dead code as far as the suite is concerned.

**Fix:** At minimum drive the branch deterministically: call claim_paid twice in a row and assert the second returns null, and call Provisioner::provision twice on the same paid order asserting 'already provisioned' with one row in arvrs_services. A true parallel test needs MySQL, but these two checks would at least cover the branches.

### EX-074 — JobRunner (durable queue, backoff, dead-lettering) is untested, and the enqueue+inline double-provision path is never exercised

*🟡 medium · Testing & QA · effort M · status `open`*

**Where:** `src/Jobs/JobRunner.php:74-101; tests/integration/e2e.php:154`

**Evidence:** `grep -rn "JobRunner" tests/` returns nothing. PaymentService.php:91-93 enqueues 'provision_order' and *then* provisions inline, but the E2E retry at :154 calls `Provisioner::provision()` directly ('as the admin button / job runner would'), so the queued job from the successful order at :77 is never run. Consequently the Services UNIQUE KEY order_id (Schema.php:99) and the Provisioner.php:36-40 'already provisioned' branch are never hit by a genuine second provisioning attempt. Untested backoff logic includes `self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1]` (JobRunner.php:91) and the `$dead = $attempts >= max_attempts` dead-letter transition at :90.

**Impact:** Provisioning idempotency is claimed in the header comment of e2e.php but only proven at the DemoProvider level (same idempotency_key → same remote_id, UsageAndRedactionTest.php:46-52). Nothing proves the system does not create a second billable cloud server when the queued job runs after inline provisioning succeeded — the most expensive possible regression. Retry exhaustion, backoff indexing and the admin dead-letter notification are equally unexercised.

**Fix:** In the E2E, run the queue tick after the successful order and re-assert `COUNT(*) FROM arvrs_services WHERE order_id = ...` is 1; add a small unit test over the backoff index and the attempts >= max_attempts dead-letter decision.

### EX-075 — Default brand color is defined six times with two different values

*🟡 medium · Code quality · effort S · status `open`*

**Where:** `src/Support/Options.php:28`

**Evidence:** `Options::DEFAULTS['brand_color'] => '#14bfb4'`, but every read/write fallback uses a different literal: src/Front/Assets.php:47 and :50, src/Admin/Actions.php:74, src/Admin/Menu.php:234, src/Onboarding/Wizard.php:182, templates/admin/wizard.php:68 all use `'#0c6960'`.

**Impact:** A fresh install renders with `#14bfb4` (from DEFAULTS) but the moment an admin saves branding with an empty or invalid color, `sanitize_hex_color(...) ?: '#0c6960'` writes the *other* teal — the storefront accent silently changes on an unrelated save. Changing the product's default color now requires editing six files and noticing that two of them disagree.

**Fix:** Delete the five `'#0c6960'` literals and read the single source: `Options::DEFAULTS['brand_color']` (via `Options::get('brand_color')`, which already falls back to DEFAULTS). Same treatment for the CSS token in assets/css/front.css:9.

### EX-076 — ~100 lines of dead provider and helper surface that must still be maintained and reviewed

*🟡 medium · Code quality · effort M · status `open`*

**Where:** `src/Arvan/ProviderInterface.php:36`

**Evidence:** `status()`, `delete()` and `is_real()` are declared on the interface and implemented in both providers (RealProvider.php:263-311 is ~50 lines of real ECC/CDN/Storage HTTP calls; DemoProvider.php:126-142) but a repo-wide search for callers finds only `plans()`, `options()` and `usage()` in use (Catalog.php:50, :66; UsageSync.php:51). Likewise `Services::set_status()` (src/Services/Services.php:97) has zero callers, and `Crypto::mask()` (src/Support/Crypto.php:56) is referenced only by its own test while `Credentials::all()` re-implements the same masking inline at src/Arvan/Credentials.php:88 (`'••••' . $r['token_last4']`).

**Impact:** Every future contributor reads, reviews and must keep compiling ArvanCloud delete/status endpoints that no code path can reach — including a `DELETE /servers/{id}` implementation that has never executed. New provider adapters are forced to implement three methods nothing calls. The duplicated masking means a change to the mask format silently applies in one place only.

**Fix:** Delete `status()`/`delete()`/`is_real()` from the interface and both providers (git history keeps them for when a service-management UI actually lands), drop `Services::set_status()`, and make `Credentials::all()` call `Crypto::mask()` instead of rebuilding the string.

### EX-077 — Credential selection has a redundant branch that defeats its own stated preference

*🟡 medium · Code quality · effort S · status `open`*

**Where:** `src/Arvan/Credentials.php:115`

**Evidence:** ```
if ($fallback === null && empty($products)) {
    $fallback = $candidate; // unrestricted credential
}
if ($fallback === null) {
    $fallback = $candidate;
}
```

**Impact:** The second `if` fires on exactly the rows the first one skipped, so the pair collapses to `if ($fallback === null) $fallback = $candidate;` — the documented preference for an unrestricted credential is never applied. A reseller with credential A restricted to `cdn` (priority 5) and credential B unrestricted (priority 10) gets A as the fallback for `object_storage`, contradicting the class docblock's stated selection order. The dead branch also actively misleads the next reader into believing the preference exists.

**Fix:** Either drop the first `if` (accepting first-in-priority-order as the fallback) or implement the intent with two accumulators — `$unrestricted` and `$any` — and `return $unrestricted ?? $any;`.

### EX-078 — No automated test covers any `$wpdb` path — the money and idempotency code has no regression net

*🟡 medium · Code quality · effort L · status `open`*

**Where:** `tests/bootstrap.php:79`

**Evidence:** The bootstrap ships `Arvrs_Fake_Wpdb` whose entire surface is `prepare()` (returns the query unchanged) and `get_var()` (returns a fixed `$var_result`); its docblock says "just enough for read-only lookups". Every DB-touching class — `Ledger::append` (INSERT IGNORE + `rows_affected` duplicate detection), `OrderService::claim_paid`, `UsageSync::ingest` back-fill, `JobRunner::run_one` atomic claim — is exercised only by `tests/integration/e2e.php`, which requires a fresh WordPress install and `ARVRS_DEMO_TOKEN` from DEVELOPMENT.md (e2e.php:38-42).

**Impact:** The subtlest code in the repo is the untested code. `Ledger::append`'s replay-vs-real-failure distinction (Ledger.php:66-79) is exactly the logic whose regression means silent money loss, and a refactor of it produces a green `composer test`. New contributors get no fast feedback on the paths CONTRIBUTING.md declares highest-risk.

**Fix:** Add an in-memory SQLite `$wpdb` shim to `tests/bootstrap.php` (PDO sqlite handles `INSERT … ON DUPLICATE`/`INSERT IGNORE` after a small rewrite, or run the existing schema through it) and port the five highest-value e2e checks — double callback, duplicate usage period, job claim race, refund credit, spending limit — into the unit suite so `composer test` covers them.

### EX-079 — Blocking sleeps inside the synchronous checkout/callback request path

*🟡 medium · Code quality · effort S · status `open`*

**Where:** `src/Arvan/RealProvider.php:201`

**Evidence:** `for ($i = 0; $i < 5 && $ip === ''; $i++) { sleep(3); … }` in `create_server()`, reached inline from `PaymentService::handle_order_callback()` → `Provisioner::provision()`. On the same path `ArvanClient` adds `sleep(max(1, $after))` on 429 (ArvanClient.php:97) and `usleep(250000 * $attempt)` between retries (:102).

**Impact:** A real-mode server purchase can hold the payment-callback PHP worker for 15 s of polling plus up to ~5.5 s of client retry — past many PHP `max_execution_time` and gateway callback timeouts, in which case the customer sees a failure for an order that actually succeeded. It also makes the provisioning path effectively impossible to unit-test without waiting real seconds.

**Fix:** Drop the polling loop: `create_server()` already has a defined 'creating' return, and the durable job queued at PaymentService.php:106 is the designed mechanism for completing the picture. Return immediately with `'creating'` and let a `refresh_service` job fill the IP.

### EX-080 — Adding a product means editing ~10 sites, including hard-coded product lists that bypass Catalog::PRODUCTS

*🟡 medium · Code quality · effort M · status `open`*

**Where:** `src/Arvan/Credentials.php:36`

**Evidence:** `array_intersect(array_map('sanitize_key', …), ['cloud_server', 'cdn', 'object_storage'])` — the literal list, not `Catalog::PRODUCTS`; identical duplication at src/Customers/Rules.php:62 and src/Support/Options.php:20. Beyond those, a new product also requires: `Catalog::PRODUCTS`, `Catalog::product_label()`, `DemoProvider::plans/options/create`, `RealProvider::plans/options/create`, `OrderService::sanitize_config()`, `BaseCosts::seed_defaults()`, `PageFactory::definitions()`, and a new `elseif ($product === …)` block in templates/front/product.php:60-95.

**Impact:** The two literal arrays are the dangerous ones: a fourth product added to `Catalog::PRODUCTS` would be sellable and priceable, yet silently unassignable to a credential and silently strippable from a customer's `allowed_products` — a permission bug with no error message. The remaining spread makes 'add a product' a multi-hour change with no checklist in code.

**Fix:** Replace both literals with `Catalog::PRODUCTS` (both files already sit downstream of it), and move the per-product config field definitions into one place — the `options()['fields']` shape the providers already return — so `sanitize_config()` and product.php can be driven from it rather than from parallel `if` chains.

### EX-081 — The audit log cannot be investigated: no filter by object/user/date, fixed 100 rows, no export, no index, no retention

*🟡 medium · Operational readiness · effort M · status `open`*

**Where:** `src/Audit/Audit.php:61-73; src/Admin/Menu.php:271; src/Install/Schema.php:156-169`

**Evidence:** `Audit::recent(int $limit, string $level)` is the only read API — there is no query by `object_type`/`object_id`/`user_id`/date range. `Menu::audit()` calls `Audit::recent(100, $level)` with no pagination. The table indexes `created_at` and `action` but not `level` (Schema.php:167-168), so the level filter (`WHERE level = %s ORDER BY id DESC`) scans. No prune job exists — the only scheduled events are `arvrs_run_jobs` and `arvrs_usage_sync` (Activator.php:17,20).

**Impact:** To answer "what happened to order #4127 last Tuesday" the operator can only eyeball the most recent 100 events. On a site doing meaningful volume the relevant rows have already scrolled off, and the table grows without bound alongside a filter query that gets slower as it does. README.md:154 sells this as compliance-grade auditability ("append-only audit log with IP"); the storage honours that, the retrieval does not.

**Fix:** Add `object_type`/`object_id`/`user_id`/date-range parameters plus offset pagination to `Audit::recent()`, wire them as filters on `audit.php`, add `KEY level_created (level, id)` and `KEY object (object_type, object_id)` in the next schema bump, and add a daily prune cron with an operator-set retention window.

### EX-082 — Correlation-ID trace breaks at the order boundary; successful upstream calls are never logged

*🟡 medium · Operational readiness · effort S · status `open`*

**Where:** `src/Provisioning/Provisioner.php:62,66,81; src/Arvan/ArvanClient.php:106,121`

**Evidence:** The correlation id is written only into the audit `detail` JSON on failure (`Audit::error('provision.failed', ['order' => ..., 'cid' => $e->correlation_id])`). The order-event note recorded on the same failure carries `$e->kind . ': ' . $e->getMessage()` — no cid. The success path (`Audit::log(0, 'provision.success', ...)` at line 81) records `order` and `remote` but no cid, and `ArvanClient` logs nothing at all on 2xx.

**Impact:** From order #4127's detail page an operator can see it failed with `timeout: ...` but cannot get the correlation id to hand ArvanCloud support without hunting the audit page for a matching `{"order":4127,...,"cid":"…"}` blob — and for the far more common "it succeeded upstream but looks wrong" complaint there is no upstream request record whatsoever.

**Fix:** Append the cid to the order-event note at Provisioner.php:62 and to the `provision.success` audit detail (`ProviderError` already carries it; return it from the provider `create()` result for the success case), so the order timeline itself is the trace.

### EX-083 — No order lookup by payment reference or ID — the most common support entry point

*🟡 medium · Operational readiness · effort S · status `open`*

**Where:** `src/Orders/OrderService.php:216; src/Admin/Menu.php:117-139`

**Evidence:** `list(int $customer_id = 0, string $status = '', int $page = 1, int $per_page = 20)` builds a WHERE from customer and status only. `Menu::orders()` reads `$_GET['status']`, `$_GET['paged']` and `$_GET['order']` (a direct id for the detail view) — `templates/admin/orders.php` offers a status filter and prev/next paging, no search box. The orders table has `UNIQUE KEY payment_ref` (Schema.php:66), so the index exists and is unused by any UI query.

**Impact:** A customer disputing a charge quotes a payment/tracking reference. The operator cannot search for it — they must guess the customer, open that customer's detail, and scan. For a bare order id there is no search either; only a hand-edited `&order=` URL works.

**Fix:** Add a `$search` parameter to `OrderService::list()` matching `payment_ref = %s OR id = %d`, and a search input on `orders.php`. The unique index already makes it a point lookup.

### EX-084 — Dead-job list shows no payload, no order link, and truncates the error to 12 words with no detail view

*🟡 medium · Operational readiness · effort S · status `open`*

**Where:** `templates/admin/health.php:57-74; src/Jobs/JobRunner.php:140-144`

**Evidence:** `JobRunner::failed()` selects `id, type, attempts, last_error, run_at, updated_at, status` — `payload` is deliberately excluded. The table renders `wp_trim_words((string) $job['last_error'], 12, '…')` with no expand control and no link anywhere.

**Impact:** The operator sees `provision_order | 5 attempts | Arvan API unreachable: cURL error 28: Operation timed…` and cannot tell which order it belongs to, which customer is affected, or read the rest of the message. Diagnosing a batch of dead provisioning jobs means opening the `wp_arvrs_jobs` table to read `payload`.

**Fix:** Include `payload` in the `failed()` SELECT and render the decoded `order_id` as a link to `admin.php?page=arvan-reseller-orders&order=N`; put the full `last_error` in a `title` attribute or a `<details>` block instead of hard-trimming it.

### EX-085 — Services page is entirely read-only — no resync, suspend, or reconcile against upstream state

*🟡 medium · Operational readiness · effort M · status `open`*

**Where:** `templates/admin/services.php (whole file)`

**Evidence:** The template renders id, customer, product, remote id, connection, status and created_at. It contains no `<form>`, no `admin-post.php` action and no `wp_nonce_field` — the only interactive elements are the customer link and prev/next paging. No `arvrs_service_*` action exists in `Actions::ACTIONS` (src/Admin/Actions.php:27-33). Local `status` is only ever written by `UsageSync::apply_policy()` (UsageSync.php:170-203).

**Impact:** If a resource is deleted or changed on the ArvanCloud side, or a local status is wrong after a partial failure, the plugin's view of it drifts permanently and there is no operator action to reconcile, re-fetch connection details, manually suspend a specific abusive service, or terminate one. All of it becomes a DB edit.

**Fix:** Add a per-service `refresh` action calling the provider for current remote state, plus manual `suspend`/`resume` that write the same local statuses `apply_policy()` already uses — both reuse the existing guard/audit pattern in `Actions.php`.

### EX-086 — Jobs stuck in 'running' are never reclaimed and never surfaced; claimed_at is written but never read

*🟡 medium · Scalability · effort S · status `open`*

**Where:** `src/Jobs/JobRunner.php:55-58, :73-77, :137-145`

**Evidence:** `run_due()` selects only `WHERE status = 'pending' AND run_at <= %s`. `run_one()` sets `status='running', claimed_at=%s`, but `claimed_at` appears nowhere else in src/ (grep for `'running'` returns only the claim, the stats label and a UI string). `failed()` looks for `status='dead' OR (status='pending' AND attempts > 0)` — a stale 'running' row matches neither.

**Impact:** A PHP fatal, an OOM, or a max_execution_time kill mid-provision leaves the row 'running' permanently: the order is never provisioned, no retry fires, no dead-letter notification is sent, and the System Health page cannot show it. SCALABILITY.md:25 names `status='dead'` count as one of the two numbers worth alerting on, so the failure mode that produces no 'dead' row is exactly the one monitoring misses. Probability rises with load, since long-running jobs are the ones that time out.

**Fix:** Add `OR (status='running' AND claimed_at < NOW() - INTERVAL 10 MINUTE)` to the due query (attempts is already incremented at claim time, so the existing backoff/dead-letter logic handles it unchanged).

### EX-087 — Admin dashboard runs six uncached full-table aggregates per render, including two whole-ledger scans and count_users()

*🟡 medium · Scalability · effort S · status `open`*

**Where:** `src/Admin/Menu.php:86-103`

**Evidence:** Per render: three `SUM(...)` over orders filtered on `status IN (...)` (:86-88), `COUNT(*)` over all orders and over active orders (:102-103), `Ledger::total_credit()` = `SUM(...)` over the entire ledger with an unindexed `is_demo = 0` filter (src/Wallet/Ledger.php:208-215), `Ledger::reconciliation(200)` = `GROUP BY customer_id ... ORDER BY available ASC` over the entire ledger (:192-205), plus WP's `count_users()`. The credentials page adds `reconciliation_by_credential()` — `services LEFT JOIN usage_records GROUP BY credential_id` with no date bound over the largest table (:172-185).

**Impact:** `GROUP BY customer_id ORDER BY <computed> ASC` cannot use an index for the sort, so it is a full scan plus temp table plus filesort over ~17.6M rows/year (CAPACITY_MODEL.md:22); the credential reconciliation is a full scan of the 17.5M-row usage table joined to services. `count_users()` is a known full usermeta scan. None of it is cached — grep confirms the only `set_transient`/`wp_cache_*` uses in src/ are the catalog and the rate limiter — so cost is paid per admin page view, and the dashboard is the page an operator refreshes most.

**Fix:** Wrap the dashboard payload in a 60-300s transient (the object cache Stage B installs then makes it free), bound the reconciliation queries by date, and index `is_demo` if the demo filter stays on the SUM.

### EX-088 — Pricing N+1: one customer_rules query per plan, repeated for every plan on every storefront and product render

*🟡 medium · Scalability · effort S · status `open`*

**Where:** `src/Pricing/Pricing.php:23, src/Customers/Rules.php:21-27, src/Front/Shortcodes.php:72 and :97`

**Evidence:** `Pricing::quote()` calls `Rules::pricing_rule($customer_id)`, which is `SELECT * FROM ...customer_rules WHERE customer_id = %d` — no caching, no memoisation. `Shortcodes::storefront()` calls `quote()` inside `foreach ($plans as $plan)` for each of the enabled products (:66-74), and `product()` calls it per plan again (:93-102), as does `Routes::priced_plan()` per plan on `/catalog/(product)` (src/Rest/Routes.php:113).

**Impact:** The identical single row is re-fetched once per plan: with ~20 plans across three products that is ~60 duplicate queries per storefront render for a logged-in customer, and the catalog REST endpoint repeats it per plan per call. It scales with catalog size rather than customer count, so it will not appear in the doc's customer-count scale table at all — but it is paid on the storefront, the highest-traffic page (CAPACITY_MODEL.md:33 assumes 10 req/s burst there).

**Fix:** Static-memoise `Rules::get()` per customer_id for the request — a two-line array cache in `Rules`, which also fixes the repeated calls from `can_purchase()` and `apply_policy()`.

### EX-089 — Plugin::demo_mode() runs an uncached credentials query per call, including once per ledger row during ingestion

*🟡 medium · Scalability · effort S · status `open`*

**Where:** `src/Plugin.php:50-56, src/Arvan/Credentials.php:125-131, src/Wallet/Ledger.php:47`

**Evidence:** In real operation (`demo_mode` false) `Plugin::demo_mode()` falls through to `Credentials::has_verified_credential()`, an uncached `SELECT id FROM ...credentials WHERE enabled = 1 AND last_ok_at IS NOT NULL LIMIT 1`. `Ledger::append()` calls `Plugin::demo_mode()` on line 47 to stamp `is_demo` — on every single append.

**Impact:** Doubles the query count of the ledger write path: at the capacity model's 48,000 ledger rows/day that is 48,000 extra round trips per day, concentrated inside the hourly sync request that finding #5 already shows is the tightest one. `Plugin::arvan()` and `Catalog::plans()` call it too, so it also lands on page renders.

**Fix:** Memoise the result in a static for the request (it cannot change mid-request except through the credentials admin action, which can reset it).

### EX-090 — Financial reporting is lifetime-cumulative only — no MRR, no period, no churn

*🟡 medium · Business viability · effort M · status `open`*

**Where:** `src/Admin/Menu.php:86-89`

**Evidence:** All four dashboard aggregates are unbounded by date: `SELECT COALESCE(SUM(amount),0) FROM $orders_table WHERE status IN ('paid','provisioning','active')$demo_filter` — no `created_at` predicate, no period parameter, and the template renders single scalar cards (templates/admin/dashboard.php:20-29). `orders` has a `created_at` index (Schema.php:69) that is never used for reporting.

**Impact:** For a recurring-revenue business the operator cannot answer 'what did I earn this month', 'is revenue growing', or 'how many services churned'. Combined with the missing renewal engine, the reseller has no instrument that would even reveal the month-2 revenue cliff. ROADMAP.md:16 mentions CSV export and monthly statements only at v1.2.

**Fix:** Add a month selector to the dashboard queries (the index is already there) and a services-active-by-month count. Roughly a day of work for the reporting a reseller checks daily.

### EX-091 — Two of three products still require manual reseller intervention to deliver credentials

*🟡 medium · Business viability · effort M · status `open`*

**Where:** `src/Arvan/RealProvider.php:220-225, docs/API_INTEGRATION.md:59`

**Evidence:** Cloud server: `'password_hint' => (string) ($server['password'] ?? '')` wrapped in `array_filter(...)` — if the create response carries no password (the request sends `'ssh_key' => false`, RealProvider.php:183, so Arvan mails the credential to the account owner, i.e. the reseller), the key is dropped entirely and the customer receives only IP and `user: root`. Object storage: 'Keys can't be minted by the plugin ... the customer is told keys come from the reseller (panel-issued)' (API_INTEGRATION.md:59).

**Impact:** README.md:13-15 sells the removal of exactly this — 'copy the IP and password, and email it back'. In practice the reseller still logs into the panel per cloud-server sale to retrieve or reset the root password, and per object-storage sale to mint and hand over S3 keys. The 'no human in the loop' claim holds fully only for CDN. The object-storage caveat is disclosed in README's limitations; the cloud-server password gap is not.

**Fix:** Disclose the root-password path in README's known-limitations list alongside the S3 one, and give the admin a per-service 'send credentials' action that records the handoff in the audit log — so the residual manual step is at least tracked and auditable rather than invisible.

### EX-092 — Global .arvrs-card margin-bottom fights every flex/grid container, so declared gaps are never the real gaps

*🟡 medium · Visual design · effort S · status `open`*

**Where:** `assets/css/front.css:130,133,135; templates/front/dashboard.php:115,162`

**Evidence:** .arvrs-card { margin-bottom: 16px } (:130) is unconditional. Cards are then placed inside .arvrs-stack { display:flex; flex-direction:column; gap:14px } (:133, dashboard.php:115) and .arvrs-grid { display:grid; gap:20px } (:135, dashboard.php:162). In flex/grid, margin adds to gap: the services list spaces at 30px not 14px, the wallet grid row-gaps at 36px not 20px, and the last card in every stack carries a 16px trailing margin the container did not ask for.

**Impact:** Vertical rhythm is nowhere near the values the stylesheet declares, and the two wallet cards in dashboard.php:162-179 end up separated by 36px inside a container whose own wrapper already adds an inline margin-bottom:20px.

**Fix:** Drop margin-bottom from .arvrs-card and let containers own spacing (add .arvrs-stack to the few places cards are emitted bare).

### EX-093 — Three different page gutters at 390px because the shared container primitive is dead code

*🟡 medium · Visual design · effort S · status `open`*

**Where:** `assets/css/front.css:66,76,126,423,446`

**Evidence:** .arvrs-shell { max-width:1160px; margin-inline:auto; padding: 0 24px } (:66) is defined and used by zero templates (verified by diffing every class attribute in templates/ against both stylesheets). Instead .arvrs-header-inner (:76), .arvrs-main (:126) and .arvrs-footer (:423) each redeclare max-width:1160px with their own padding, and the <=640px block (:446) reduces padding to 16px on .arvrs-main only.

**Impact:** At 390px the header brand mark sits 24px from the edge, page content 16px, and the footer 24px - the vertical edge line visibly steps twice down a single mobile screen. Because the container was copied three times instead of shared, the fix has to be made in three places.

**Fix:** Apply .arvrs-shell to the header inner, main and footer and delete the duplicated max-width/padding declarations, so the mobile gutter change happens once.

### EX-094 — No mobile navigation, and the fixed-height header overflows in the 640-760px band

*🟡 medium · Visual design · effort M · status `open`*

**Where:** `assets/css/front.css:76,87,445; assets/js/front.js (no menu code); templates/front/partials/shell-top.php:27-31`

**Evidence:** .arvrs-nav { display: none } at <=640px (:445) with no replacement - front.js contains no drawer/hamburger handler (its only sections are auth tabs, order form, gateway, top-up, mark-read). Above that breakpoint, .arvrs-header-inner has a fixed height:68px (:76) while .arvrs-nav sets flex-wrap: wrap (:87) and .arvrs-brand-name (:86) has no max-width or text-overflow, and the brand name is reseller-supplied (shell-top.php:25).

**Impact:** On a 390px phone the three product links (Cloud Server / CDN / Object Storage) disappear entirely from the shell - the storefront's primary navigation is reachable only by tapping the logo. Between roughly 640 and 760px, a long Persian brand name pushes the nav onto a second wrapped row inside a 68px-tall bar, spilling over the content below.

**Fix:** Add a <details>-based disclosure menu (no JS needed) for <=640px, and give .arvrs-brand-name a max-width with text-overflow:ellipsis plus min-width:0 on the flex parent so the header degrades instead of wrapping.

### EX-095 — Decorative overlay paints on top of hero and auth content because of stacking order

*🟡 medium · Visual design · effort S · status `open`*

**Where:** `assets/css/front.css:149-154,378-382`

**Evidence:** .arvrs-hero-body is position:relative with no z-index (:154). .arvrs-hero::after is position:absolute; inset:0 with a coral radial-gradient and no z-index (:149-153). Both are positioned with z-index:auto, so painting follows tree order and the generated ::after - always the last child - renders above the body. The author added pointer-events:none (:153) rather than a z-index, which is the classic symptom-patch for exactly this. .arvrs-auth-aside::after (:378-381) has the identical structure over .arvrs-auth-aside-body (:382) and does not even carry that guard.

**Impact:** The 28%-alpha coral wash tints the hero headline, badge and the white 'view plans' CTA rather than sitting behind them, and the same happens over the auth panel's welcome copy. pointer-events:none masks the interaction bug but leaves the visual one.

**Fix:** Add z-index:1 to .arvrs-hero-body and .arvrs-auth-aside-body (or z-index:0 to the two ::after overlays) and drop the pointer-events workaround.

### EX-096 — Semantic color misused for a positive claim, and .arvrs-alert-body markup differs across four call sites

*🟡 medium · Visual design · effort S · status `open`*

**Where:** `templates/front/product.php:20,26-28; templates/front/payment.php:24,56; templates/front/dashboard.php:56; templates/front/auth.php:25; assets/css/front.css:286-297`

**Evidence:** product.php:20 renders the positive claim 'تحویل آنی' (instant delivery) as <span class="arvrs-tag arvrs-tag-danger"> - the red/danger semantic token. Separately, .arvrs-alert-body is applied to a <strong> (payment.php:24, dashboard.php:56), a <span> (auth.php:25) and a <div> (product.php:26, payment.php:56). In the <div> form the nested <p class="arvrs-muted"> (product.php:28) applies var(--arvrs-muted) #5c7876 - a grey-teal - on top of the warning alert's #fffaeb background, ignoring the alert's own .arvrs-alert-warning color #7c2d12 (:291).

**Impact:** The storefront's headline selling point is badged in the same red the system uses for 'suspended' and 'provisioning failed', training the user's eye wrongly. And the plan-unavailable alert renders its explanatory line in an off-scheme teal-grey against yellow instead of the alert's dark amber ink.

**Fix:** Use arvrs-tag-success for the instant-delivery badge; fix .arvrs-alert-body to one element contract (a <div> with a <strong> title and a <p> that inherits the alert color) and scope .arvrs-muted out of alerts with .arvrs-alert p { color: inherit; opacity: .85 }.

### EX-097 — Declared weight hierarchy is fictional, and 49 inline styles carry four off-palette hexes past existing tokens

*🟡 medium · Visual design · effort M · status `open`*

**Where:** `assets/css/front.css:49-51; templates/front/dashboard.php:233,235; templates/admin/dashboard.php:40; templates/admin/customers.php:30; templates/admin/customer-detail.php:116`

**Evidence:** Three static faces are bundled (Vazirmatn-Regular/Medium/Bold.woff2, ~51KB each, not a variable font) but :51 declares 'font-weight: 700 900' on the single Bold file, so the stylesheet's 12 uses of 700, 21 of 800 and 17 of 900 all render as one weight. Separately, templates carry 49 inline style attributes (26 front, 23 admin); four contain hexes outside the palette: #41605d (dashboard.php:233, vs --arvrs-muted #5c7876), #dc2626 (admin/dashboard.php:40 and customers.php:30, vs --danger #e3305a), and #16a34a/#dc2626 (customer-detail.php:116, vs --success/--danger which are declared four lines up in admin.css:19-21 and duplicated by the existing .arvrs-amount-credit/.arvrs-amount-debit classes at front.css:313-314).

**Impact:** Card titles, page titles, stat values and body bold are all specified as distinct weights (800 vs 900 vs 700) but render identically, so the intended typographic hierarchy is flat. And the admin shows two different reds and two different greens for the same credit/debit concept on adjacent pages.

**Fix:** Ship the Vazirmatn variable font (one file, real 700-900 range) or collapse the stylesheet to the three weights that exist; replace the four inline hexes with var(--danger)/var(--success) and reuse the amount-credit/debit classes.

### EX-098 — Index set does not match the query set: `audit_log.level` unindexed, four declared indexes unused

*🟡 medium · Data & analytics · effort S · status `open`*

**Where:** `src/Install/Schema.php:69,102,121,167-168; src/Audit/Audit.php:64-68`

**Evidence:** `Audit::recent()` filters `WHERE level = %s ORDER BY id DESC` — `level` has no index, while the two indexes that do exist on that table (`KEY created_at`, `KEY action`) are used by no query in `src/`. Same mismatch elsewhere: `orders KEY created_at` (no query filters or orders by it), `services KEY remote_id` (UsageSync.php:45 maps remote_id in a PHP array, never in SQL), `ledger KEY created_at` (the only created_at query, Ledger.php:146, also filters `customer_id` and `direction` so a standalone created_at index is not usable).

**Impact:** `audit_log` is the fastest-growing table and its one filtered read (the admin security-report page and the health page's `Audit::recent(10,'error')`) is a full table scan that degrades linearly forever. Meanwhile four index trees are maintained on every INSERT for no reader. DATA_MODEL.md:43 asserts the audit keys are `created_at`, `action` — presented as if they serve the queries, which they do not.

**Fix:** Replace `KEY action` with `KEY level_id (level, id)` to serve the actual filter-plus-order, and drop `orders.created_at`/`services.remote_id`/`ledger.created_at` unless the missing time-range reports (above) are added — in which case `orders.created_at` becomes justified.

### EX-099 — `usage_records` carries neither `is_demo` nor a cost/price split — the recurring stream has no reportable margin

*🟡 medium · Data & analytics · effort M · status `fixed`*

**Closed (v1.1.0):** `usage_records` gained `price`, `currency`, `source` and `is_demo` columns (v4→v5 migration backfills `price = cost` on pre-split rows); `reconciliation_by_credential()` filters `is_demo` and joins on it.
**Where:** `src/Install/Schema.php:124-138; src/Wallet/Ledger.php:172-185`

**Evidence:** `usage_records` has a single `cost bigint` column — unlike `orders`, which stores `amount`/`base_cost`/`margin` as a triple — and no `is_demo` or `currency` column, though `orders`, `services` and `ledger` all have `is_demo`. `reconciliation_by_credential()` runs `SUM(u.cost) ... GROUP BY s.credential_id` with no demo predicate at all.

**Impact:** Usage cost is debited to the customer 1:1 with no markup and no record of what the reseller paid upstream, so gross margin is reportable only on one-time orders and is structurally unknowable for consumption revenue. The per-credential reconciliation report — the one place a reseller checks whether their Arvan invoice matches what they billed — silently includes demo usage, and demo services (provisioned with `credential_id` NULL) are coerced to `0` by the `(int) $credential_id` cast at Services.php:29, so they surface as a phantom credential row.

**Fix:** Add `is_demo`, `base_cost` and `currency` to `usage_records`, mirror the demo filter into `reconciliation_by_credential()`, and preserve NULL for demo credential ids. Note this is currently latent in production because RealProvider::usage() returns `[]` (RealProvider.php:317-320) — but that makes the usage-based reports demo-only, which should be stated where they are rendered.

### EX-100 — Four unbounded tables with no retention or pruning path

*🟡 medium · Data & analytics · effort M · status `open`*

**Where:** `repo-wide (src/Install/Schema.php; uninstall.php)`

**Evidence:** Grep for `DELETE FROM|prune|TRUNCATE` across `src/` returns only `uninstall.php` (all-or-nothing drop) and the `data_retention_on_uninstall` setting. `ledger`, `audit_log`, `usage_records` and `jobs` (done/dead rows) have no archival, rollup, or partitioning. `usage_records.raw longtext` stores the full provider payload per hourly period.

**Impact:** Hourly usage across N services writes 2 rows/service/hour (usage + ledger debit) plus a `longtext` blob — ~17.5k rows and potentially tens of MB per service per year, permanently, on shared WordPress hosting. The only retention control offered is a binary "delete everything on uninstall" toggle. The ledger *should* be append-only forever; `usage_records.raw`, completed `jobs` rows and `level='info'` audit rows should not.

**Fix:** Add a monthly rollup for closed usage periods (keep the aggregate, drop `raw`), a cron that deletes `jobs` in status `done` older than N days, and an audit-log retention window for non-`audit` levels. Document that the ledger itself is intentionally never pruned.

### EX-101 — Dashboard order counts skip the demo filter applied two lines above them

*🟡 medium · Data & analytics · effort S · status `open`*

**Where:** `src/Admin/Menu.php:102-103`

**Evidence:** `'total' => SELECT COUNT(*) FROM $orders_table` and `'active' => ... WHERE status = 'active'` — neither appends `$demo_filter`, while lines 86-89 (revenue, cost, margin, failed) all do. The customer count at :99 uses `count_users()` and likewise cannot exclude demo-created accounts.

**Impact:** In live operation the "سفارش‌ها" (orders) KPI card on the dashboard (templates/admin/dashboard.php:24) counts demo orders alongside real ones, sitting directly beside a revenue card that excludes them — two headline numbers on the same row computed on different populations, which is the specific failure mode that erodes trust in a reporting screen.

**Fix:** Append `$demo_filter` to both counts (it is already built and in scope on line 85).

### EX-102 — Object Storage region choice is advertised by RealProvider but silently discarded before it reaches the API

*🟡 medium · Integration honesty · effort S · status `open`*

**Where:** `src/Arvan/RealProvider.php:128-132, 261 vs src/Orders/OrderService.php:46-52`

**Evidence:** `RealProvider::options('object_storage')` returns two regions (`ir-central1`, `ir-northwest1`) and `create_bucket` reads `$region = in_array($config['region'] ?? '', [...]) ? $config['region'] : 'ir-central1'`. But `sanitize_config` for `object_storage` accepts only `bucket` — there is no `region` key in `$out`, so `$config['region']` is always unset by the time the provisioner runs. `DemoProvider::options('object_storage')` (DemoProvider.php:69-71) doesn't return regions at all, so the DTO shapes also disagree between the two providers.

**Impact:** Every real bucket is created in `ir-central1` regardless of what the catalog advertises, and the customer's connection info always shows the `s3.ir-thr-at1` endpoint. It is a silent wrong-answer path, not an error, and the demo/real `options()` shape drift means UI built or reviewed against DemoProvider never renders the region field at all.

**Fix:** Add `region` to the `object_storage` branch of `sanitize_config` with an allowlist, and make DemoProvider return the same `regions` array so the two providers are shape-identical as `ProviderInterface` claims.

### EX-103 — Zero test coverage of the real integration path — every test runs through DemoProvider

*🟡 medium · Integration honesty · effort M · status `open`*

**Where:** `tests/ (repo-wide)`

**Evidence:** `rg 'ArvanClient|RealProvider|wp_remote' tests/` returns nothing; the only provider reference is `use ArvanReseller\Arvan\DemoProvider;` in tests/unit/UsageAndRedactionTest.php:2. tests/integration/e2e.php imports Orders, Payments, Pricing, Usage, Wallet — never the Arvan namespace. There are no recorded HTTP fixtures anywhere in the repo.

**Impact:** The retry loop, the 401 auth-prefix flip, the 429 `Retry-After` branch, status→kind normalization, and every upstream response parser in RealProvider are entirely unexercised. The layer with the most external uncertainty is the only one with no test, and the passing test suite therefore says nothing about real mode.

**Fix:** Add unit tests that stub `wp_remote_request` (the codebase already runs unit tests without a WP install) and assert: 5xx → bounded retries, timeout → kind `timeout`, 401 → single prefix flip then `auth`, and that a truncated/unexpected `data` shape yields a ProviderError rather than a PHP warning.

### EX-104 — docs/API_INTEGRATION.md claims 402 error semantics are handled; ArvanClient has no 402 branch and classifies it as retryable

*🟡 medium · Integration honesty · effort S · status `fixed`*

**Closed (v1.1.0):** `ArvanClient::handle()` has an explicit `402` branch → `ProviderError('billing', …)`, which `retryable()` excludes — not retried, and surfaced to the customer/admin with an actionable message.
**Where:** `docs/API_INTEGRATION.md:28 vs src/Arvan/ArvanClient.php:123-132`

**Evidence:** The doc states: "Documented error semantics handled: `402` insufficient upstream wallet → surfaced to the admin as a provider error". `handle()` branches on 401/403, 404, 422/400, and everything else falls to `throw new ProviderError('unknown', ...)`. `Provisioner.php:68` then puts `'unknown'` in the retryable list `['timeout','unavailable','rate_limit','unknown']`.

**Impact:** A 402 (reseller's own Arvan wallet empty — a permanent condition until topped up) is retried with backoff until the job dies, and the admin notification says "job dead" rather than "your upstream balance is empty". The doc describes behavior the code does not implement, which is the kind of claim a judge would spot-check.

**Fix:** Add an explicit `402` branch mapping to a non-retryable kind (e.g. `insufficient_upstream_funds`) with an admin-targeted message, or delete the claim from the doc.

### EX-105 — "No endpoint in this plugin is invented" is asserted absolutely but is unverifiable from the artifact, and at least one endpoint/field shape looks unusual

*🟡 medium · Integration honesty · effort M · status `fixed`*

**Closed (v1.1.0, doc + partial code):** the security-group mapping bug is fixed (`RealProvider::create_server()` now prefers the object's `name`, falls back to `id` only when absent). The doc's absolute "no endpoint invented" claim is reworded to say plainly that the repo vendors no spec/fixture and the citations are pointers to verify independently, not self-proving — no fixtures were added, so treat that half of the original fix suggestion as still open.
**Where:** `docs/API_INTEGRATION.md:3, 35 vs src/Arvan/RealProvider.php:171-176, 246`

**Evidence:** The doc opens with "**No endpoint in this plugin is invented.**" and cites specs by URL, but the repo contains no vendored spec, no captured response fixture, and no test asserting any shape — nothing in the artifact can corroborate `GET /domains/{domain}/ns-keys/check` or `https://storage.arvanapis.ir/v1/buckets`. Separately, the security-group body maps an ID into a name field: `$security_groups = [['name' => (string) $sg['id']]]` from `GET /securities`, and the selection loop `if (!empty($sg['id']) && (!empty($sg['default']) || empty($security_groups)))` overwrites rather than accumulates.

**Impact:** The strongest honesty claim in the docs rests entirely on the author's word. If any path or field is off by a segment, real mode fails on the first create while every demo and test still passes — and a reviewer has no way to tell which. The `name`-holds-an-ID mapping is the sort of detail that is either exactly right or silently rejected upstream with a 422.

**Fix:** Commit the relevant slices of the OpenAPI/Redoc specs (or a `docs/fixtures/` folder of recorded responses) and add shape assertions against them, so the "nothing invented" claim is checkable inside the repo rather than asserted.

### EX-106 — spec.md — the self-declared source of truth — has drifted from the code in five places

*🟡 medium · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** all five spec.md drift points fixed — the `PricingProvider` reference removed, `service_charge` added to §7, `/me/notifications` and `/me/services/{id}` added to §9, the two provider class names corrected, and a new §5.6a added for the renewal-billing lifecycle the spec was missing entirely.
**Where:** `spec.md:97,102-103,131,147-148`

**Evidence:** (1) §6:97 cites a "`PricingProvider` abstraction" — `grep -r PricingProvider` matches only spec.md and docs/DATA_MODEL.md:52; no such class or interface exists in src/. (2) §6:97 says base costs are "Documented in ADR-0007", but ADR-0007 is "Append-only ledger; balances derived" — it says nothing about pricing. (3) §7:103 lists ledger types omitting `service_charge`, which is in `Ledger::DEBIT_TYPES` (src/Wallet/Ledger.php:17) and in DATA_MODEL.md:34. (4) §9:131 omits the `/me/notifications` list route that src/Rest/Routes.php:79 registers in the loop. (5) §11:147-148 names `DemoArvanProvider` and `SandboxPaymentProvider`; the classes are `DemoProvider` (src/Arvan/DemoProvider.php) and `SandboxProvider` (src/Payments/SandboxProvider.php).

**Impact:** CONTRIBUTING.md:5 makes spec.md binding ("Behavior changes update the spec in the same PR"). A new engineer following it will grep for a `PricingProvider` that was never built, read the wrong ADR for pricing rationale, and get a ledger type list and REST table that are each one entry short of reality.

**Fix:** Delete the `PricingProvider` reference (or say plainly: base costs are a table, swap point is `BaseCosts`), retarget the ADR citation, add `service_charge` to §7 and `/me/notifications` to §9, and rename the two provider classes to their real names.

### EX-107 — README's stack table and project tree name classes and modules that do not match src/

*🟡 medium · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** README stack table now says `DemoProvider`/`RealProvider`; the project-structure tree gained `Customers/`, `Services/`, `Billing/`, `Reports/`.
**Where:** `README.md:139, README.md:186-196`

**Evidence:** Line 139: "`DemoArvanProvider` ↔ `RealArvanProvider` swap without touching business logic" — the classes are `DemoProvider` and `RealProvider` (src/Arvan/). ARCHITECTURE.md:67 gets it right (`RealProvider::usage()`). The project-structure block lists `Admin/ Front/ Rest/ Onboarding/ Identity/ Audit/ Support/` but omits `src/Customers/` and `src/Services/`, both of which exist and both of which ARCHITECTURE.md's ownership table (lines 44-45) documents as owning real responsibilities (`Customers\Rules`, `Services::get_owned`).

**Impact:** README is the first file a new engineer greps. Two of its class names return zero hits, and its directory map is missing the module that implements the isolation primitive (`Services::get_owned`) the same README cites as the core security control on line 148.

**Fix:** Correct the two class names and add the `Customers/` and `Services/` lines to the tree.

### EX-108 — No troubleshooting/runbook, and the E2E script's fresh-database requirement has no documented reset step

*🟡 medium · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** `docs/RUNBOOK.md` added (stuck order, stranded jobs, credential rotation, ledger repair, failing renewals, DB reset for a repeat E2E run); `DEVELOPMENT.md` gained the two-line reset command inline.
**Where:** `DEVELOPMENT.md:72-79, tests/integration/e2e.php:13`

**Evidence:** e2e.php's header states "Requires a FRESH install (re-runs need a reset DB)" and DEVELOPMENT.md:79 repeats "Fresh install required", but neither file gives the reset command (no `wp db reset`, no drop-and-reinstall snippet) anywhere in the repo. Separately, no document covers recovery procedures for the failure modes the docs themselves name: SECURITY.md:93 says "Salt rotation invalidates encrypted credentials by design" with no recovery steps, SCALABILITY.md:25 names `arvrs_jobs.status='dead'` as an alerting signal with no runbook for draining it, and ADR-0004:20 mentions a dead-letter path with no operator procedure.

**Impact:** The second run of the flagship E2E evidence fails with duplicate registrations and no documented way out — the exact wall a new engineer hits on day one after their first successful run. The named-but-unroutined failure modes (dead jobs, salt rotation, stuck paid orders) leave operators with a diagnosis and no procedure.

**Fix:** Add the two-line reset to DEVELOPMENT.md (`php wp-cli.phar db reset --yes --path=wp && core install … && plugin activate …`), and add a short docs/RUNBOOK.md covering: dead job triage, salt-rotation credential re-entry, retry/refund of a stuck paid order, and demo-state reset.

### EX-109 — Customer-facing ArvanCloud error messages bypass `__()` entirely

*🟡 medium · Accessibility & i18n · effort S · status `open`*

**Where:** `src/Arvan/DTO.php:88-96`

**Evidence:** `customer_message()` returns a bare PHP array of six untranslated Persian literals: `'auth' => 'پیکربندی سرویس‌دهنده ابری نیاز به بررسی دارد…'`, `'rate_limit' => …`, `'timeout' => …`, `'invalid' => …`, `'unavailable' => …`, plus the fallback `'خطای غیرمنتظره‌ای رخ داد. سفارش شما محفوظ است و پیگیری می‌شود.'`. The docblock above it even says "Actionable Persian message safe for customers (SEC-12)" — and spec.md:121 claims "Errors shown to customers are translated Persian messages". Every sibling error path does use `__()` (src/Rest/Routes.php:130,136,139; src/Orders/OrderService.php:92).

**Impact:** The six most likely checkout failure messages — the ones a user sees when provisioning times out or the upstream API rate-limits — are frozen in Persian and invisible to `wp i18n make-pot`. Together with the three hardcoded Persian strings in `assets/js/front.js`, an English or Arabic translation of the plugin would still show Persian at exactly the moments the user most needs to understand what happened.

**Fix:** Wrap all six in `__(…, 'arvan-reseller')`. Do the same for `assets/js/front.js:78` (`|| 'ادامه و پرداخت'`), `:122` (`'✓ کال‌بک تکراری شناسایی شد — …'`) and `:152` (`'پرداخت و شارژ'`) by adding those keys to the `i18n` array already localized at src/Front/Assets.php:43-46.

### EX-110 — `Domain Path: /languages` points at a directory that does not exist; no .pot ships

*🟡 medium · Accessibility & i18n · effort S · status `open`*

**Where:** `arvan-reseller.php:12,51`

**Evidence:** The header declares `Domain Path: /languages` and `plugins_loaded` calls `load_plugin_textdomain('arvan-reseller', false, dirname(plugin_basename(__FILE__)) . '/languages')` (line 51). `ls` of the repo root shows no `languages/` directory, and `find . -name "*.pot" -o -name "*.po" -o -name "*.mo"` returns nothing.

**Impact:** 619 correctly-wrapped `__()` calls produce zero translatable output in practice — there is no catalog for a translator to open and no build step that generates one, so the loader silently no-ops for every locale. "i18n-ready" (spec.md:156) is true of the call sites but not of the deliverable.

**Fix:** Generate and commit `languages/arvan-reseller.pot` (`wp i18n make-pot . languages/arvan-reseller.pot`), plus an `fa_IR` .po/.mo so the shipped Persian is itself a translation rather than the untranslatable source strings.

### EX-111 — White-on-teal gradients that ignore the brand variable fail AA — hero and stat text at ~2:1

*🟡 medium · Accessibility & i18n · effort M · status `open`*

**Where:** `assets/css/front.css (.arvrs-hero, .arvrs-auth-aside, .arvrs-stat.is-brand), admin.css:72-77`

**Evidence:** `.arvrs-hero` and `.arvrs-auth-aside` use literal `linear-gradient(130deg/150deg, #0a4a47 0%, #0c8a80 55%, #14bfb4 100%)` — not `var(--arvrs-brand)` — and place `.arvrs-hero-sub { color: rgba(255,255,255,.85) }` (16px) and `.arvrs-auth-aside p { rgba(255,255,255,.82) }` (15px) over it. Against the #14bfb4 terminus (relative luminance 0.407) those composite to 2.01:1 and 1.96:1; the angles put the light stop at the bottom-right while the RTL text block sits inline-start (right). `.arvrs-stat.is-brand .arvrs-stat-label { rgba(255,255,255,.75) }` at 13px over `#0c8a80` = 3.07:1. In admin, `.button-primary { background: linear-gradient(135deg, #2ad3c6, #0a9e95); color: #fff }` overrides WordPress core's accessible `#2271b1` and yields 1.87:1 at the light stop, 3.31:1 at the dark one.

**Impact:** The hero subtitle, the login-screen value proposition, the wallet-balance stat labels, and — most seriously — every "Save settings" / "Test connection" primary button in wp-admin carry text below the 4.5:1 AA threshold, several below even the 3:1 large-text floor. The admin button is a regression against a WordPress default that already passed.

**Fix:** Cap the gradient light stop (e.g. end at `#0c8a80`, luminance 0.198, which gives white 4.44:1 and clears 3:1 comfortably for the large hero h1), raise the three `rgba(255,255,255,.75-.85)` values to solid `#fff`, and drop the `.arvrs-admin .button-primary` gradient override or restrict it to `#0a7a72`-and-darker.

### EX-112 — Payment success destroys keyboard focus and relies on reveal-to-announce live regions

*🟡 medium · Accessibility & i18n · effort M · status `open`*

**Where:** `assets/js/front.js:95-116, templates/front/payment.php:47-66`

**Evidence:** On success the handler runs `payOk.hidden = true; if (fail) fail.hidden = true; $('#arvrs-pay-done').hidden = false;` (front.js:103-105). `payOk` is the element the user just activated and still holds focus, so hiding it drops focus to `<body>`. Separately, `#arvrs-pay-progress` is `hidden` in markup with its `role="status"` text already baked in (`payment.php:50`) — the script only unhides the container, never mutates the region's text — and `#arvrs-order-error` sets `err.textContent` *while still hidden*, then unhides (front.js:82-83, and the same order at :146-148).

**Impact:** A screen-reader or keyboard user who confirms payment is silently returned to the top of the document with no announcement that the transaction settled or that a service was provisioned — the exact moment the flow most needs confirmation. Live regions whose content changes while unrendered, or that are merely un-hidden, are announced inconsistently across NVDA/JAWS/VoiceOver, so the "در حال تأیید پرداخت…" and error messages may never be spoken. The failure branch uses a raw `alert()` (front.js:109), a blocking modal with no styling or context.

**Fix:** Move focus to `#arvrs-pay-done` (give it `tabindex="-1"`) after revealing it. Keep the `role="status"` elements permanently rendered and set `textContent` after they are visible rather than before; unhide first, then write. Replace the `alert()` with the existing `.arvrs-alert-danger` + `role="alert"` pattern already used on the product page.

### EX-113 — Brand color is user-settable to any hex with no contrast guard, and drives all CTA/focus colors

*🟡 medium · Accessibility & i18n · effort M · status `open`*

**Where:** `templates/admin/branding.php:30, src/Admin/Actions.php:74, src/Front/Assets.php:47-49`

**Evidence:** `<input type="color" name="brand_color" />` is saved through `sanitize_hex_color(wp_unslash($_POST['brand_color'] ?? '')) ?: '#0c6960'` (Actions.php:74) — format validation only — then injected as `wp_add_inline_style('arvrs-front', ':root{--arvrs-brand:' . $color . ';}')`. `--arvrs-brand` feeds white-on-brand `.arvrs-product-cta`, the `--arvrs-grad` primary button, `.arvrs-chip`, `.arvrs-nav a.is-active`, and every `:focus-visible` outline. The shipped default `#0c6960` gives white 6.55:1 (fine), but the CSS `:root` fallback `#14bfb4` gives 2.30:1.

**Impact:** A reseller picking any light or mid teal/yellow/lime — which a bare color picker invites — silently drops the primary CTA, the wallet chip, the active nav link and every focus ring below AA, with no warning in the UI. The derived ramp (`brand-2`, `brand-3`, `brand-ink` via `color-mix`) is well-designed but only safe for dark inputs; the failure is ungated rather than inherent.

**Fix:** Compute the relative luminance of the submitted hex in `Actions.php` and either reject/warn below the threshold that keeps white text at 4.5:1, or auto-derive the on-brand text color (white vs `--arvrs-ink`) per contrast instead of hardcoding `color: #fff !important` on `.arvrs-btn-primary`.

### EX-114 — Public registration silently overrides the site's membership setting and leaks account existence

*⚪ low · Security · effort S · status `open`*

**Where:** `src/Identity/Customers.php:61-85 + src/Front/FormActions.php:47-59`

**Evidence:** `Customers::register` calls `wp_insert_user([...'role' => self::ROLE])` directly, with no reference to `get_option('users_can_register')`. It returns distinguishable errors: `email_exists($email)` → "با این ایمیل قبلاً ثبت‌نام شده است" versus a successful signup, and `FormActions::register` reflects `$result->get_error_message()` straight back to the caller. Password floor is `strlen($password) < 8` with no other strength requirement. Rate limit is `register:'.client_ip()` at 5/600s.

**Impact:** Activating the plugin turns on open user registration on any WordPress site regardless of the admin's Membership setting — a surprising security-posture change for a site that deliberately disabled it. The differential error also gives account enumeration (slowly: 5 probes per 10 minutes per IP, trivially parallelised across IPs), and 8 characters with no complexity or breach check is a weak floor for accounts that hold spendable wallet balances.

**Fix:** Return an identical neutral message for both the taken-email and success cases ("if this address is new, you are now signed in; otherwise sign in"), or gate registration behind an explicit plugin setting that the wizard sets, and note in the admin UI that enabling the storefront enables customer signup. Consider raising the floor via `wp_check_password_strength`-equivalent server-side length/entropy checks.

### EX-115 — arvrs_notice / arvrs_error are attacker-controlled reflected text in both admin and front notices

*⚪ low · Security · effort S · status `open`*

**Where:** `templates/admin/partials/notices.php:4-5 + src/Front/Shortcodes.php:156-157`

**Evidence:** `$arvrs_notice = isset($_GET['arvrs_notice']) ? sanitize_text_field(wp_unslash($_GET['arvrs_notice'])) : '';` then `<div class="notice notice-success is-dismissible"><p><?php echo esc_html($arvrs_notice); ?></p></div>` — the value comes straight from the query string with no nonce or integrity binding (the `phpcs:disable WordPress.Security.NonceVerification` on line 3 acknowledges this as "display-only flash messages"). The same pattern appears on the front-end auth page (`templates/front/auth.php:25`) and the wizard (`src/Onboarding/Wizard.php:76-77`).

**Impact:** Not XSS — `esc_html` is correctly applied at every sink and I verified all of them. But an attacker can hand an admin or a customer a link like `…/wp-admin/admin.php?page=arvan-reseller-credentials&arvrs_error=<attacker text>` that renders arbitrary text inside a first-party WordPress error banner. That is a credible phishing/social-engineering primitive on a page that also hosts the API-token entry form ("Your ArvanCloud token expired — re-enter it below").

**Fix:** Replace query-string flash messages with keyed codes: pass `arvrs_notice=branding_saved` and look the human string up from a server-side map, rendering nothing for an unknown key. That removes the injection primitive without changing the UX.

### EX-116 — Test harness ships inside the plugin with no ABSPATH guard

*⚪ low · Security · effort S · status `open`*

**Where:** `tests/integration/e2e.php:1-40, tests/bootstrap.php:1-20`

**Evidence:** Every file in `src/` and `templates/` opens with `defined('ABSPATH') || exit;`. `tests/integration/e2e.php` does not — it goes straight from the docblock to `use` statements, `$GLOBALS['fails'] = 0;`, a global `function check()` and `getenv('ARVRS_DEMO_TOKEN')`. `tests/bootstrap.php` likewise defines `ABSPATH`, `ARVRS_DIR` and a set of global WordPress shims (`get_option`, …) with no guard. `.gitattributes` contains only line-ending and binary rules — no `export-ignore` for `tests/`.

**Impact:** Both files are directly requestable at `/wp-content/plugins/arvan-reseller/tests/…` on a default install. Execution fails fast (undefined classes) rather than doing damage, but with `display_errors` on it discloses the absolute filesystem path and PHP version in the fatal, and `bootstrap.php` will happily define global function shims in a web request context. It is also the one place in the codebase that breaks the otherwise uniform direct-access guard.

**Fix:** Add `defined('ABSPATH') || exit;` to both files (WP-CLI's `eval-file` and PHPUnit's bootstrap both satisfy or predate it — for `bootstrap.php` use a `php_sapi_name() === 'cli' || exit;` guard instead), and add `tests/ export-ignore` plus a `.distignore` so the shipped artifact excludes them.

### EX-117 — Rate limiter is a non-atomic read-modify-write, so burst limits are bypassable

*⚪ low · Security · effort S · status `open`*

**Where:** `src/Support/Helpers.php:82-91`

**Evidence:** `$hits = (int) get_transient($key); if ($hits >= $max) { return false; } set_transient($key, $hits + 1, $window_seconds); return true;` — a plain GET-then-SET with no locking or atomic increment. Concurrent requests all read the same `$hits` and all write `$hits + 1`. SECURITY.md:94 acknowledges only the multi-server limitation ("per-server, not global"), not the intra-server race.

**Impact:** N parallel requests arriving inside one read-write window all pass, and the counter advances by 1 instead of N. The login limiter (10/600s per IP, FormActions.php:34) and the payment-callback limiter (30/300s per IP, Routes.php:156) can therefore be exceeded by a straightforward concurrent burst rather than serial requests. These are throttles rather than authorization boundaries, so impact is bounded — but the claimed protection is weaker than stated.

**Fix:** Use `wp_cache_add($key, 0, '', $window)` followed by `wp_cache_incr($key)` when a persistent object cache is present (atomic there), falling back to the transient path otherwise; or accept it and add the race to the documented limitations alongside the multi-server note.

### EX-118 — The documented module dependency table is stale in at least four places

*⚪ low · Architecture · effort S · status `fixed`*

**Closed (v1.1.0, doc):** `ARCHITECTURE.md`'s module ownership table regenerated from the actual `use` statements and fully-qualified in-body calls, including the `Plugin` edge and the other previously-undeclared lateral calls (Orders→Wallet, Payments→Usage, Usage→Customers/Jobs/Pricing). No CI-less check was added (the fix's second half) — the table is honest now but still unenforced.
**Where:** `ARCHITECTURE.md:32-47`

**Evidence:** Declared vs. actual imports: `Wallet` "may depend on Support" but imports `Plugin` (Ledger.php:47). `Orders` may depend on "Pricing, Arvan, Customers, Support" but calls `Wallet\Ledger::balance` (OrderService.php:90) and `Plugin` (line 110). `Payments` may depend on "Orders, Wallet, Provisioning, Jobs, Notifications" but calls `Usage\UsageSync::apply_policy` (PaymentService.php:144). `Usage` adds an undeclared `Customers\Rules` dependency (UsageSync.php:133).

**Impact:** The table is the artifact a reviewer or new engineer trusts to reason about coupling; being wrong on four edges means it cannot be used to predict blast radius, and nothing enforces it.

**Fix:** Regenerate the table from actual `use` statements and add a cheap CI-less check (a unit test that greps imports per module against an allowlist) so it cannot drift again.

### EX-119 — Only one extension point exists in the entire plugin; no lifecycle events at all

*⚪ low · Architecture · effort S · status `open`*

**Where:** `src/Plugin.php:77 (repo-wide)`

**Evidence:** `apply_filters` appears exactly once (`arvrs_payment_provider`); `do_action` appears zero times across all of `src/`. ARCHITECTURE.md:63-68 lists four "extension points", but three of them are edit-these-files instructions ("add to `Catalog::PRODUCTS`, provider branches, `sanitize_config` whitelist, storefront template branch"). The Arvan provider — the other declared seam — has no filter, so a third provider requires editing `Plugin::arvan()`.

**Impact:** For a WordPress plugin handling orders and money, the absence of events like `arvrs_order_paid` / `arvrs_service_provisioned` means no CRM, accounting or analytics integration is possible without forking. The one real seam (payments) is also the only one whose abstraction has a second implementation.

**Fix:** Fire `do_action('arvrs_order_paid', $order)` / `'arvrs_service_provisioned'` / `'arvrs_policy_stage_changed'` at the three points that already exist in `PaymentService`, `Provisioner` and `UsageSync::apply_policy`, and mirror the payment filter for the Arvan provider.

### EX-120 — The claim that WordPress-freeness is "enforced by the unit suite" is not mechanically true

*⚪ low · Architecture · effort S · status `fixed`*

**Closed (v1.1.0, doc):** `ARCHITECTURE.md` no longer claims WP-freedom is "enforced by the unit suite" — a new "A claim we no longer make" section explains the bootstrap shims would let a regression pass silently, since no test scans for WordPress tokens in the pure classes.
**Where:** `ARCHITECTURE.md:28, tests/bootstrap.php:8-103`

**Evidence:** ARCHITECTURE.md:28: "Pure classes ... import nothing from WordPress — enforced by the unit suite running without WordPress loaded." The bootstrap defines `ABSPATH`, four time/size constants, an in-memory option store, `wp_salt`, `__`, `esc_html`, `esc_attr`, `wp_json_encode`, four sanitizers, `current_time`, and a fake `$wpdb`. A class that started calling `get_option` or `$wpdb->get_var` would still pass.

**Impact:** The purity property is real today (I verified the three engines call nothing), but it is preserved by discipline, not by the mechanism the doc credits — so it will silently erode.

**Fix:** Add a one-assert test that scans the four pure files for WP function/`$wpdb` tokens, or move them to a namespace the bootstrap loads before defining any shim.

### EX-121 — Documentation cites code paths and counts that do not match the repo

*⚪ low · Product completeness · effort S · status `fixed`*

**Closed (v1.1.0):** README's `get_owned` citation is now accurate (production callers exist, see EX-053); E2E count reconciled via TESTING.md; spec.md's ADR-0007 citation reworded to not claim it covers pricing.
**Where:** `README.md:148`

**Evidence:** README's security section claims customer isolation via "`Services::get_owned`", but that method (Services.php:63) is called only from `tests/integration/e2e.php:129-130` — no production handler uses it (isolation is real, but it comes from the `WHERE customer_id = %d` list queries instead). `HACKATHON_READINESS.md:9` claims a "54-check E2E" while the file contains 53 `check()` calls, and line 53 of the same document says "46U+42E". `spec.md:97` attributes base costs to ADR-0007, which is `docs/adr/0007-wallet-ledger-model.md`.

**Impact:** A judge who spot-checks a cited symbol or count finds it does not exist, which cheapens an otherwise unusually honest documentation set (the API_INTEGRATION "NOT available" table is genuinely accurate).

**Fix:** Point the README bullet at the actual owner-scoping mechanism, reconcile the E2E check count in both files, and fix the ADR cross-reference.

### EX-122 — Declared-but-unused surfaces: `usage_sync` job type, `orders.credential_id`, `usage_records.raw`, reservation ledger types

*⚪ low · Product completeness · effort S · status `open`*

**Where:** `src/Jobs/JobRunner.php:118`

**Evidence:** `JobRunner::execute` has a `usage_sync` case, but the only `enqueue()` calls in the codebase are `provision_order` (PaymentService.php:91, Admin/Actions.php:269) — usage runs only via the `arvrs_usage_sync` cron hook. `orders.credential_id` (Schema.php:61) and `usage_records.raw` (Schema.php:133) are never written. `Ledger::derive()` computes `reserved` from `reservation`/`release` entries (Ledger.php:98-107) that nothing ever appends, so the reconciliation figure is structurally always 0.

**Impact:** Minor dead weight rather than user-visible breakage, but README's "derived balances, reservations, reconciliation views" implies a reservation feature that has no writer (ROADMAP does disclose reservation-based checkout as future work).

**Fix:** Drop the unused job case and columns, or wire the usage cron through the durable job table so a missed run is retried like every other job.

### EX-123 — Fixed 48-hour usage window with no per-service watermark; suspended services stop syncing while still running

*⚪ low · Reliability · effort M · status `open`*

**Where:** `src/Usage/UsageSync.php:51, src/Services/Services.php:84-95`

**Evidence:** `Plugin::arvan($product)->usage($product, array_keys($map), gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS))` — the lower bound is always now-2d, never a stored high-water mark, and there is no per-service `last_synced_at` column in the schema. `active_for_sync()` selects `WHERE status IN ('active','at_risk')`, excluding `suspended`, while apply_policy's own comment (UsageSync.php:179-182) confirms suspension is local only: "the plugin never deletes or powers off a remote resource".

**Impact:** WP-Cron is traffic-triggered, so a low-traffic site or a >48h outage loses that usage permanently — there is no catch-up path and the back-fill in `ingest()` only helps for periods still inside the window. A suspended customer's resources keep running and keep costing the reseller upstream while the plugin stops recording any of it, and re-activation does not recover the gap.

**Fix:** Store `last_usage_sync_at` per service and use `max(last_synced, now-30d)` as the `since` bound; keep suspended services in the sync set (billing the debt is the point of suspension) or explicitly record the un-metered interval so the operator can see it.

### EX-124 — Top-up intents are written to wp_options and never deleted or expired

*⚪ low · Reliability · effort S · status `open`*

**Where:** `src/Payments/PaymentService.php:107-115, 118-150`

**Evidence:** `add_option('arvrs_topup_' . $ref, ['customer_id' => ..., 'amount' => ..., 'at' => time()], '', false)` on every top-up start; `handle_topup_callback` reads it with `get_option` and never calls `delete_option`, and the stored `at` timestamp is never compared against anything.

**Impact:** The options table accumulates one permanent row per top-up attempt including all abandoned ones, and an intent stays redeemable forever — a callback presenting a months-old ref still credits the wallet. Replay of a *settled* ref is blocked by the ledger unique key, so this is bounded growth plus an unbounded intent lifetime rather than a double-credit.

**Fix:** Reject intents older than a few hours (`$intent['at']`), and delete the option once the top-up is ledgered; a scheduled sweep of `arvrs_topup_%` older than 24h covers abandoned ones.

### EX-125 — Untranslated internals leak into the Persian admin, and detail pages have no back-link

*⚪ low · UX & usability · effort S · status `open`*

**Where:** `templates/admin/orders.php:17-19, templates/admin/wizard.php:113-118, templates/admin/customer-detail.php:18-22, templates/admin/order-detail.php:9-18`

**Evidence:** The order filter chips print `esc_html($state)` — 'pending_payment', 'provision_failed' — directly beside the same statuses rendered in Persian by Helpers::status_tag in the table below (orders.php:39). The wizard's pages step prints `esc_html($key)` ('storefront', 'object_storage') although PageFactory::definitions() already carries Persian titles like 'فضای ابری (Object Storage)'. Ledger rows print raw `$entry['type']` and status events print raw from/to values (customer-detail.php:115, order-detail.php:74-75). Neither customer-detail nor order-detail renders any link back to its list page.

**Impact:** The reseller admin reads as half-localised in exactly the places an operator scans fastest, and after opening a customer file or order the only way back to the list is the browser Back button or the sidebar.

**Fix:** Route the filter chips and ledger/event types through Helpers::status_tag (or a small type-label map), use $def['title'] in the wizard pages list, and add a '← بازگشت به فهرست' link at the top of both detail templates.

### EX-126 — No automated coverage of the browser layer; the .playwright-cli directory holds manual session dumps, not tests

*⚪ low · Testing & QA · effort L · status `open`*

**Where:** `repo-wide (assets/js/front.js, templates/, .playwright-cli/)`

**Evidence:** assets/js/front.js is 166 lines and there are 20+ PHP templates under templates/front and templates/admin, with no .spec.js/.test.js anywhere and no Playwright/Cypress config (`find` for those patterns returns only the `.playwright-cli` directory, which contains console-*.log and page-*.yml session artifacts). TESTING.md:45-46 substitutes screenshots and a manual click-through checklist. `composer.json` also defines `"lint": "@php -l arvan-reseller.php"` — a single bootstrap file, not src/.

**Impact:** Every client-side behaviour is regression-tested by a human: form validation, the checkout flow's client state, RTL rendering, the wizard, and any XSS-relevant escaping in templates. The REST-level dispatch checks in the E2E do not touch a rendered page, so an unescaped variable in a template or a broken submit handler is invisible to the suite. Honest disclosure at TESTING.md:55 mitigates the credibility cost but not the risk.

**Fix:** Even three Playwright checks — register→login, checkout→pay→service visible, admin wizard completes — would cover the highest-traffic paths; and widen the composer lint script to `find src -name '*.php' -print0 | xargs -0 -n1 php -l`.

### EX-127 — Three concrete comment defects: a docblock attached to the wrong function, a stale @return, and a duplicated const comment

*⚪ low · Code quality · effort S · status `open`*

**Where:** `src/Payments/PaymentService.php:22`

**Evidence:** Lines 22-26 hold `/** Order payment callback. @param array $payload … @return array{ok:bool,replay:bool,message:string,order:?array} */` immediately followed by a second docblock (27-32) and then `public static function sandbox_blocked(): bool` — the orphaned block documents `handle_order_callback()` (line 38), which now has none. Separately, src/Admin/Actions.php:42 reads `/** Shared guard: capability + nonce; returns sanitized redirect target. */` on `private static function guard(string $action): void`, which returns nothing. And src/Notifications/Notifier.php:16-19 carries two different comments for the same constant.

**Impact:** IDE hovers and generated API docs attach a callback signature to a boolean predicate; a reader of `guard()` looks for a return value that does not exist. These are cheap credibility losses in a codebase whose comments are otherwise its strongest asset.

**Fix:** Move the 22-26 block down to `handle_order_callback()`, change the `guard()` line to note that it `wp_die()`s on failure, and keep one of the two Notifier comments.

### EX-128 — UsageSync::apply_policy is an 80-line multi-responsibility function with a duplicated `global $wpdb`

*⚪ low · Code quality · effort M · status `open`*

**Where:** `src/Usage/UsageSync.php:130`

**Evidence:** One function computes the grace override, calls PolicyEngine, persists user meta, ranks stage severity, dispatches four notification variants from an inline message table (:155-160), and issues three raw `UPDATE wp_arvrs_services SET status = …` statements — with `global $wpdb;` declared twice, at :171 inside the `mark_at_risk` block and again at :177 in the enclosing scope.

**Impact:** The interaction between `mark_at_risk` (active→at_risk at :170) and the `else` reactivation branch (suspended→active at :193, at_risk→active at :199) is order-dependent and takes real effort to reason about; the bulk UPDATEs also bypass `Services::set_status()` and its status whitelist, so the two ways to change service status can drift. Highest-risk function in the module and, per the finding above, entirely untested.

**Fix:** Split into `stage_for($customer_id)`, `notify_for_stage($customer_id, $stage, $worsened)` and `apply_service_holds($customer_id, $actions, $stage)`; hoist the single `global $wpdb;` and move the message table to a private const.

### EX-129 — Admin order action relies on a helper's hidden exit() for control flow, with no return statements

*⚪ low · Code quality · effort S · status `open`*

**Where:** `src/Admin/Actions.php:243`

**Evidence:** `order_action()` runs three sequential `if ($do === '…') { … self::back(…); }` blocks followed by an unconditional `self::back('', __('عملیات ناشناخته.'))`. Nothing returns; correctness depends entirely on `self::back()` calling `exit` (Actions.php:60). Additionally the `retry_provision` branch enqueues a job *and* calls `Provisioner::provision()` inline (:247-249) with no comment — unlike the identical pattern at PaymentService.php:106, which explains itself.

**Impact:** Adding logging, a shutdown hook, or any early-return refactor to `back()` turns every handler into fall-through: a 'cancel' action would also emit the 'unknown operation' redirect. A reader must jump to `back()` to learn the function ever terminates.

**Fix:** Make `back()`'s exit explicit at each call site (`return self::back(…);` plus a `never`-style docblock) or convert the chain to a `switch` with `break`s and a single tail redirect; and copy PaymentService's two-line comment explaining the queue-then-inline pattern.

### EX-130 — Redaction is key-name based only; upstream error text and job errors are stored verbatim

*⚪ low · Operational readiness · effort S · status `open`*

**Where:** `src/Audit/Audit.php:15,45-59; src/Arvan/ArvanClient.php:121; src/Jobs/JobRunner.php:95`

**Evidence:** `redact()` matches only against `REDACT_KEYS = ['token','api_token','password','secret','authorization','pat']` on array KEYS; values are never scanned. `ArvanClient::handle()` stores `['message' => substr($message, 0, 300)]` — an upstream body echoed back — under the non-matching key `message`, and `JobRunner` stores `substr($e->getMessage(), 0, 500)` into `jobs.last_error`, both rendered in admin UI.

**Impact:** The health page's own heading claims the error list is محرمانه‌زدایی‌شده (redacted). That guarantee holds for structured detail keys but not for free-text upstream messages, which are exactly where a provider is most likely to echo a request header or signed URL back at you.

**Fix:** Add a value-side pass to `redact()`: regex-replace long high-entropy substrings and `Bearer|Apikey \S+` patterns in string values before storage. Cheap, and it makes the on-screen claim true.

### EX-131 — Scheduled usage syncs record a timestamp but no result counts, so a silently empty run looks healthy

*⚪ low · Operational readiness · effort S · status `open`*

**Where:** `src/Usage/UsageSync.php:87; src/Admin/Actions.php:321-326`

**Evidence:** `sync_all()` returns `['services','ingested','debited','errors']` and writes only `update_option('arvrs_last_usage_sync', gmdate(...))`. The stats are audited exclusively on the manual path (`Audit::log(0, 'usage.sync_now', 'system', '', $stats)` in `Actions::sync_now()`); the cron path (`add_action('arvrs_usage_sync', [self::class,'sync_all'])`, UsageSync.php:32) discards them. The health page shows only `$last_usage_sync`.

**Impact:** An hourly sync that touched 40 services and ingested 0 records — the signature of a broken usage endpoint or a mis-mapped `remote_id` (silently `continue`d at UsageSync.php:60-62) — is indistinguishable on the health page from a correct run. Revenue leaks quietly.

**Fix:** Move the `Audit::log(..., $stats)` call to the end of `sync_all()` itself so both paths record it, and show the last run's counts next to the timestamp on the health page.

### EX-132 — Composite indexes do not match the actual sort orders; OFFSET pagination degrades on deep pages

*⚪ low · Scalability · effort S · status `open`*

**Where:** `src/Install/Schema.php:137, :120-121, src/Usage/UsageSync.php:220-225`

**Evidence:** `usage_records` has only `KEY customer_id`, but `customer_usage()` runs `WHERE u.customer_id = %d ORDER BY u.id DESC LIMIT %d`; `ledger` has `KEY customer_id` and `KEY created_at` separately, while `entries()` sorts `WHERE customer_id = %d ORDER BY id DESC`. `is_demo` is filtered by `total_credit()`/`reconciliation()` but is not indexed. All list queries use `LIMIT %d OFFSET %d`.

**Impact:** Each customer-dashboard render sorts that customer's whole usage history (~17.5k rows/year) to return 20 — a filesort that grows linearly and is invisible until it isn't. OFFSET pagination re-scans skipped rows, so deep admin pages degrade, and `templates/admin/orders.php:48` infers "has next page" from `count($orders) === 20` rather than a count, so there is no total to bound it with.

**Fix:** Add `KEY customer_recent (customer_id, id)` to usage_records and ledger; index `is_demo` if the demo split stays on aggregates. Keyset pagination (`WHERE id < :last_seen`) is the follow-up if deep paging ever matters.

### EX-133 — Declared model surface that no code path writes: `orders.credential_id`, and 4 of 10 ledger types

*⚪ low · Data & analytics · effort S · status `open`*

**Where:** `src/Install/Schema.php:61; src/Wallet/Ledger.php:16-17`

**Evidence:** `orders.credential_id` is declared but appears in no INSERT/UPDATE — `OrderService::create` (OrderService.php:98-113) omits it and nothing else writes the orders table's credential column; only `services.credential_id` is populated (Services.php:29). Of the declared types, `reservation`, `release`, `promo_credit` and `service_charge` are appended by zero call sites (the only `Ledger::append` callers use topup/payment/purchase/refund/usage_debit/adjustment).

**Impact:** `derive()` computes `'reserved' => max(0, $reserved - $released)` (Ledger.php:117) which is structurally always 0 and is surfaced as a real wallet field. DATA_MODEL.md:34 lists all ten types as if they were live, so the documented model overstates what the ledger actually records — a reviewer reconciling docs to code finds 40% of the type vocabulary is aspirational.

**Fix:** Drop `orders.credential_id` (routing is a service-level fact) and either implement the reservation/release pair or remove those types and the `reserved` field until they are needed.

### EX-134 — No FKs and no application-level referential cleanup — deleting a credential orphans its services

*⚪ low · Data & analytics · effort S · status `open`*

**Where:** `src/Arvan/Credentials.php:68-73`

**Evidence:** `delete()` is a bare `$wpdb->delete(self::table(), ['id' => $id])` with no check for dependent rows, and no table in Schema.php declares a FOREIGN KEY. `services.credential_id` continues to point at the removed id.

**Impact:** The orphaned services still exist upstream in the deleted Arvan account, but the plugin can no longer tell the reseller which account holds them — and `reconciliation_by_credential()` groups them under an id that resolves to nothing, so the per-credential reconciliation report becomes silently wrong. Absent FKs, this is exactly the class of breakage FKs prevent, and there is no compensating guard.

**Fix:** Refuse deletion when `SELECT 1 FROM services WHERE credential_id = %d AND status IN ('active','at_risk') LIMIT 1` returns a row (offer disable instead — `enabled` already exists), and NULL the column on delete otherwise.

### EX-135 — Documented client behavior overstates two details: a 5 s connect timeout that WP ignores, and "key-redacted request/response logging" that does not exist

*⚪ low · Integration honesty · effort S · status `fixed`*

**Closed (v1.1.0):** the connect timeout is now actually bound via the `http_api_curl` hook (the WP HTTP API drops a bare `connect_timeout` arg); `API_INTEGRATION.md`'s logging line reworded to state precisely what is redacted by key vs. scrubbed by value, and that production logs carry no request/response bodies at all (only WP_DEBUG does, redacted).
**Where:** `src/Arvan/ArvanClient.php:50-51, 106, 121 vs docs/API_INTEGRATION.md:50`

**Evidence:** The doc says "Connect timeout 5 s, total 20 s ... request/response logging is key-redacted (`Audit::redact`)". The client passes `'connect_timeout' => self::CONNECT_TIMEOUT` to `wp_remote_request`, which is not a WP HTTP API argument — `WP_Http::request` forwards only `timeout`, `useragent`, `blocking` and `hooks` into the Requests options, so the connect phase inherits the transport default, not 5 s. And the client logs no request or response bodies at all; it logs `['path','code','cid','message']`, where `Audit::redact` (Audit.php:45-58) matches on *key names* (`token`, `password`, `authorization`, …) and so would not redact anything inside the `message` value.

**Impact:** Two small, checkable claims in the integration doc that the code does not deliver. Individually cosmetic; together they weaken the credibility of the rest of the doc's assertions, which is precisely the currency this file trades in.

**Fix:** Set the connect budget via a `http_api_curl` hook (or drop the claim), and reword the logging line to what is true: "no request/response bodies are logged; audit detail arrays are key-redacted".

### EX-136 — The admin UI offers "sync usage now" in real mode, where it can only ever produce zero rows

*⚪ low · Integration honesty · effort S · status `open`*

**Where:** `templates/admin/health.php:20, 44 vs src/Arvan/RealProvider.php:317-320`

**Evidence:** `RealProvider::usage()` returns `[]` unconditionally. The health page still renders a primary-styled «همگام‌سازی مصرف — همین حالا» button and a "last usage sync" timestamp, with no real-mode notice; the limitation appears only in README.md:211 and docs/API_INTEGRATION.md:56.

**Impact:** An operator running real mode clicks sync, sees the timestamp update, and reasonably concludes usage accounting is running. The limitation is honestly documented but not surfaced where the wrong belief is formed.

**Fix:** When `!Plugin::demo_mode()`, replace the button with an inline notice stating that ArvanCloud publishes no usage API and that billing is package-based.

### EX-137 — Internal AI-agent tooling artifact leaked into a shipped engineering document

*⚪ low · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** `docs/DATA_MODEL.md`'s top-up storage description rewritten in plain prose with no `ponytail` marker; the section was also updated to reflect that top-up intents now live in the `topups` table, not `wp_options` (a larger change than the wording fix alone — the underlying storage moved in this same round).
**Where:** `docs/DATA_MODEL.md:56`

**Evidence:** "top-up intents (`arvrs_topup_{ref}`, autoload off — ponytail note: move to a table if top-up volume ever matters)". "ponytail" is an authoring-assistant convention (it also appears as a source comment at src/Payments/PaymentService.php:110, where it is appropriate), not a term defined anywhere in this repo's documentation.

**Impact:** A reader of the data model doc hits an undefined internal term and learns the docs were partly machine-authored without an editorial pass — cheap damage to an otherwise polished handbook.

**Fix:** Rewrite as plain prose: "top-up intents live in `wp_options` with autoload off; move to a table if top-up volume grows." Keep the source-code `ponytail:` marker if the debt-harvest tooling depends on it, but strip the convention from prose docs.

### EX-138 — "i18n-ready" is claimed but no translation catalog or translator guidance ships

*⚪ low · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** `languages/arvan-reseller.pot` + `arvan-reseller-fa_IR.po`/`.mo` ship, with `bin/make-pot.php`/`bin/make-mo.php` to regenerate; CONTRIBUTING.md gained a Translations section naming both commands and the locale-file naming convention.
**Where:** `spec.md:156, arvan-reseller.php:11-12,51`

**Evidence:** The plugin header declares `Domain Path: /languages` (line 12) and Plugin bootstrap calls `load_plugin_textdomain('arvan-reseller', false, … . '/languages')` (line 51), but there is no `languages/` directory in the repo and no `.pot` template. spec.md:156 asserts "i18n-ready (`arvan-reseller` text domain); shipped strings Persian", and no document explains how to generate a catalog or add a locale.

**Impact:** The declared Domain Path points at a directory that does not exist, and a contributor wanting to add English or Arabic has no documented starting point — despite the product being explicitly white-label and pitched at resellers.

**Fix:** Ship `languages/arvan-reseller.pot` (generatable via `wp i18n make-pot .`) and add a five-line "Translations" section to CONTRIBUTING.md naming the command and the file layout.

### EX-139 — The JS bundle size cited in the README badge and the stack decision matrix is overstated by ~45%

*⚪ low · Documentation · effort S · status `fixed`*

**Closed (v1.1.0):** README badge and STACK_EVALUATION.md both now say "one 16 KB JS file" (measured: `assets/js/front.js` is 16,017 bytes as of this round) instead of the stale ~9 KB figure.
**Where:** `README.md:136, docs/STACK_EVALUATION.md:38`

**Evidence:** Both say "~9 KB JS" (STACK_EVALUATION.md:38 uses it to score "Performance (7): 9" in the weighted matrix that produces the 860-vs-750 frontend decision). `assets/js/` contains exactly one file: `front.js` at 6,220 bytes; there is no admin JS file.

**Impact:** A quantitative input to a published decision matrix is wrong. It happens to understate the project's own advantage, so no conclusion changes — but it is the kind of unchecked number that invites a reviewer to spot-check the other figures in that table.

**Fix:** State the measured figure ("6.2 KB, one file") in both places, or drop the byte count and say "one 6 KB script, no build step".

### EX-140 — `role="tablist"` has no arrow-key navigation, and admin focus ring is downgraded to a failing 1px

*⚪ low · Accessibility & i18n · effort S · status `open`*

**Where:** `assets/js/front.js:25-35, assets/css/admin.css:87`

**Evidence:** The auth tabs bind only `click`: `tabLogin.addEventListener('click', …)` / `tabRegister.addEventListener('click', …)` (front.js:34-35). There is no `keydown` handler, no roving `tabindex`, and neither button carries `tabindex="-1"` — so Left/Right/Home/End do nothing inside the tablist, contrary to the ARIA APG tabs pattern the markup opts into. Separately, `admin.css:87` reads `.arvrs-admin input:focus, select:focus, textarea:focus { border-color: var(--brand); box-shadow: 0 0 0 1px var(--brand); outline: none }` where `--brand: #14bfb4` — a 1px indicator at 2.30:1 against white, replacing WordPress core's 2px `#2271b1`.

**Impact:** Tab semantics promise arrow-key behavior that isn't there; the fallback (both buttons in tab order) works but is inconsistent with what AT announces. The admin focus indicator fails SC 1.4.11 non-text contrast (3:1 required) and is thinner than the core ring it overrides, making keyboard traversal of the credentials and policy forms harder than in unstyled wp-admin.

**Fix:** Add a `keydown` handler for ArrowLeft/ArrowRight (direction-swapped in RTL), Home and End with roving `tabindex`, or drop `role="tablist"` and let them be plain buttons. Change `admin.css:87` to `box-shadow: 0 0 0 2px var(--brand-ink)` (`#0a7a72`, ≈5.9:1) or simply stop overriding core's focus style.

### EX-141 — Shortcode output nests `<main>` inside the theme's `<main>` and adds a second `<h1>`

*⚪ low · Accessibility & i18n · effort S · status `open`*

**Where:** `templates/front/partials/shell-top.php:47, src/Install/PageFactory.php:41, src/Front/Shortcodes.php:27-33`

**Evidence:** `PageFactory` creates ordinary pages whose `post_content` is just the shortcode (`'post_content' => $def['shortcode']`, line 41), so `Shortcodes::storefront()` output is injected into the theme's post-content area. That output opens `<header class="arvrs-header">`, closes with `<main class="arvrs-main">` (shell-top.php:47) and `<footer class="arvrs-footer">` (shell-bottom.php), and each template emits its own `<h1>` — `storefront.php:28`, `product.php:19`, `dashboard.php:31`, `checkout.php:10`, `require-login.php:9`.

**Impact:** Nearly every WordPress theme already wraps `the_content()` in `<main>` and renders the page title as `<h1>`. The result is two `main` landmarks (invalid HTML, ambiguous "skip to main" target) and two `<h1>`s per page, so heading-based navigation lands on the theme's page title rather than the plugin's real page heading. There is also no skip link and no `sr-only` utility anywhere in the CSS, and the visible `!` / `i` / `✓` glyphs in `.arvrs-alert-mark` are not `aria-hidden`, so each alert is prefixed by a spoken punctuation character.

**Fix:** Change `<main class="arvrs-main">` to `<div class="arvrs-main">` (the styling is identical) and demote the template `<h1>`s to `<h2>` — or register a page template that suppresses the theme title. Add `aria-hidden="true"` to `.arvrs-alert-mark` and `.arvrs-feature-mark`.

