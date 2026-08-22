# Changelog

All notable changes to this project are documented here. Format: [Keep a Changelog](https://keepachangelog.com/), versioning: SemVer.

## [1.1.0] — 2026-08-22

Remediation round following the 15-agent expert panel review (72.5/100, 141 findings — `docs/review/ISSUE_BACKLOG.md`). Closes both critical business-viability findings (no renewal billing; wallet/usage/credit-policy dead in real mode) and the most serious reliability finding (non-idempotent creates retried on timeout).

### Added
- **Recurring/renewal billing** (`Billing\Renewals`): every service carries its own clock (`renews_at`/`term_days`/`renewal_price`); a daily job charges due services. This — not upstream metering, which ArvanCloud still does not publish — is where recurring revenue comes from now. Three-layer idempotent (usage-period key, ledger ref key, conditional clock-advance UPDATE), with renewal reminders and cancellation.
- **Period reporting** (`Reports\Reports`): revenue/cost/margin for a date window, MRR, churn — the admin dashboard used to show lifetime-cumulative sums only, which can't show a revenue cliff or a margin squeeze.
- **Filterable job-handler registry** (`Jobs\Handlers`, `arvrs_job_handlers` filter) replacing a hardcoded `switch` in the job runner — the queue is now a real extraction seam for a companion plugin.
- **PHP 7.4 compatibility gate** (`bin/php74-check.php`, its own CI job): parses every shipped file against a real PHP 7.4 grammar and walks the AST for PHP 8-only constructs, so "Requires PHP 7.4" is a checked claim rather than an assertion `php -l` on an 8.x CI runner can't actually defend.
- **Translation catalog**: `languages/arvan-reseller.pot`, an `arvan-reseller-fa_IR.po`/`.mo` translation, and `bin/make-pot.php`/`bin/make-mo.php` to regenerate both.
- **`readme.txt`** (WordPress.org plugin-directory format) and **`docs/RUNBOOK.md`** (operator incident playbooks: stuck orders, stranded jobs, credential rotation, ledger repair, failing renewals, database reset for a repeat E2E run).
- Durable-job reaper (`JobRunner::reap_stale`) and an admin action to reclaim an order stuck in `provisioning` (`Provisioner::reclaim_stale`) — previously a crashed worker or a mid-provision fatal left a row unrecoverable from the UI.
- Admin notifications/flash messages are now actually rendered (`Admin\Flash`) — previously written to the database and shown nowhere.
- Daily automated credential health check (`credential_health` job) — previously a revoked token kept showing "connected" until a human clicked Test.

### Changed
- **`ArvanClient` retry policy is verb-aware**: idempotent verbs (GET/HEAD/PUT/DELETE/OPTIONS) retry on 5xx/timeout as before; POST/PATCH are never blindly retried — a timeout or 5xx on a non-idempotent call now raises `timeout_indeterminate` and the caller reconciles by deterministic remote name instead of repeating a write that may already have billed the customer's ArvanCloud account. Requests now carry an `Idempotency-Key` header; `402` (insufficient upstream balance) is a dedicated non-retryable error kind instead of falling into the generic retryable bucket.
- **`RealProvider`** names every remote resource deterministically from the order, so a create can be looked up and adopted after an indeterminate outcome instead of guessed at; the connect-timeout budget is now actually enforced (via the `http_api_curl` hook — the WordPress HTTP API silently drops a bare `connect_timeout` argument).
- **Removed the blocking `sleep()`** from the payment-callback request path; waiting for a resource's connection details (IP, etc.) now happens in a background job instead of inside the customer's checkout request.
- **`Wallet\Ledger::balance()`/`balances()`** are now a single indexed SQL aggregate (object-cached), replacing an unbounded per-row PHP sum that loaded a customer's entire ledger history on every storefront/dashboard render. The admin customer list batch-fetches all visible customers' balances in one query instead of one query per row.
- **`Ledger::negative_since_days()`** now walks back to the true start of a negative-balance period instead of reporting the age of the most recent debit — the credit-policy `restricted` stage was previously unreachable in production because of this.
- **Job runner** decides retry-vs-terminal by the typed `ProviderError::kind`/handler result, not by substring-matching English error text (which silently broke for the many Persian-language failure paths).
- **`Arvan\Catalog`** cache-miss path now has a single-refresher lock, a short negative cache for upstream failures, and a stale-serve fallback — a cold cache used to let every concurrent visitor make its own blocking upstream call, and an upstream outage was never cached, so it could exhaust PHP-FPM workers.
- Payment result now reports the true post-payment provisioning state instead of unconditionally telling the customer their service is ready.

### Fixed
- Security-group selection on Cloud Server create now uses the upstream object's `name` field when present, falling back to `id` only when it is absent (previously mapped `id` into `name` unconditionally).
- `usage_records` now carries a `cost`/`price` split (previously billed at raw cost, earning zero margin on the metered-usage stream); pre-existing rows were backfilled `price = cost`.

### Migration
- Schema bumped **4 → 5**. On upgrade: existing services are backfilled a renewal clock (30-day term, price from their original order amount); existing zero-price usage rows are backfilled `price = cost`; any top-up intent still pending in a legacy `wp_options` row is moved into the new `topups` table. All migrations are idempotent dbDelta; back up before upgrading as with any schema-migrating release.
- Plugin version bumped to **1.1.0**.

## [1.0.0] — 2026-08-20

First complete release for the ArvanCloud reseller hackathon.

### Added
- Plugin Access Token licensing (bcrypt allowlist, fingerprint-only storage)
- 7-step onboarding wizard with server-side validation and idempotent page creation
- Persian RTL storefront for Cloud Server / CDN / Object Storage with plan configuration
- Pricing engine: global / per-product / per-customer markup, discounts, fixed adjustments, immutable order snapshots; admin-maintained base-cost table
- Sandbox payment gateway with HMAC server-side verification and a live duplicate-callback demonstration; PSP adapter interface
- Order state machine with append-only event history and atomic optimistic transitions
- Instant provisioning with three-layer idempotency and durable retry jobs (backoff + dead-letter)
- Real ArvanCloud provider over verified documented endpoints (ECC, CDN 4.0, Object-Storage management API); demo provider with deterministic catalog/usage
- Multiple sodium-encrypted Arvan credentials with per-product routing and connection health
- Append-only wallet ledger with derived balances, top-ups and admin reconciliation
- Usage engine: idempotent per-period ingestion → ledger debits → configurable credit-policy staging with cooldown-aware notifications
- Customer dashboard (services/orders/wallet/usage/inbox) and 10-section admin experience incl. System Health with Sync-now and an audit log
- 46 unit tests + 53-check end-to-end scenario on a real WordPress (corrected from the originally-published "54" — see `docs/review/ISSUE_BACKLOG.md` EX-045); CI (lint 7.4/8.2/8.3, tests, secret scan)
- Hardened across three adversarial review rounds (panel → verification → convergence), every finding fixed and re-tested
- Engineering handbook: spec, 11 ADRs, threat model, stack evaluation, scalability/capacity models, API integration reference, demo script (fa) and checklist
