# Testing

Three layers, each with an honest statement of what it does and does not prove. Everything documented here **was actually executed** — the evidence lines quote real output.

**This file is the one place the headline test/check counts are stated.** Every other document (README, spec, HACKATHON_READINESS, CAPACITY_MODEL, docs/performance/) points here instead of restating the numbers — an earlier round of this repo published two different, both-wrong counts for the E2E script across nine files (`docs/review/ISSUE_BACKLOG.md` EX-045). If you change the suite, re-run it and update the numbers here only.

## 1. Unit tests (PHPUnit, no WordPress)

```bash
composer test
```

Evidence (this repo, PHP 8.5): `OK (192 tests, 736 assertions)` in ~9 s.

| Suite | Covers |
|---|---|
| `PricingEngineTest` | global/product/customer markup precedence, discount stacking + cap, fixed adjustments, floors, snapshot fields, hackathon example (10M × 1.2 = 12M) |
| `StateMachineTest` | happy path, failure/retry path, illegal jumps, terminal states, unknown-state rejection |
| `LedgerDerivationTest` | derived balances, reservations/releases, negative balances, exhaustive direction mapping, unknown-type rejection (pure `Ledger::derive`, no DB) |
| `LedgerDbTest` | the SQL aggregate (`Ledger::balance`/`balances`) against a real `$wpdb` shim — asserts it agrees with `derive()` |
| `PolicyEngineTest` | threshold staging incl. boundaries, grace→restricted timing, admin-configured action filtering, destructive-action gating |
| `LicenseTest` | rejection paths, storage shape, no-plaintext-in-repo guarantee, bcrypt round-trip |
| `CryptoTest` | encrypt/decrypt round-trip, per-encryption nonces, tamper → null, invalid input, masking |
| `PaymentVerificationTest` | proof verifies; tampered amount / wrong ref / wrong type / missing proof all fail |
| `PaymentServiceTest` | callback claim/replay behavior against `$wpdb` |
| `UsageAndRedactionTest` | demo-usage determinism (dedup foundation), closed-periods-only, idempotent create, unknown-resource skip, recursive log redaction, Persian error mapping |
| `UsagePolicyTest` | usage ingestion → policy staging integration |
| `ProvisionerTest` | idempotent create pipeline, `reclaim_stale` |
| `RenewalsTest` | term-charge idempotency, clock advance, cancellation |
| `JobRunnerTest` | claim/backoff/dead-letter, `reap_stale` |
| `ArvanClientTest` | verb-aware retry policy: idempotent verbs retry, POST/PATCH raise `timeout_indeterminate` instead; 402/429/401-refresh handling |
| `AuthorizationTest` | `Services::get_owned` as the object-level authorization choke point |
| `OrderClaimTest` | atomic `claim_paid` under concurrent-claim simulation |
| `SchemaMigrationTest` | v3→v4→v5 migrations, including the backfills (renewal clock, usage price split, top-up-option migration) |

The bootstrap (`tests/bootstrap.php`) shims WP functions and a fake `$wpdb`; anything that needs real MySQL semantics belongs in layer 2.

## 2. Integration / E2E (real WordPress)

```bash
wp eval-file tests/integration/e2e.php     # fresh install; see DEVELOPMENT.md
```

Evidence (WordPress 6.x + SQLite integration, this machine): `ALL E2E CHECKS PASSED` — **123 checks** (the script counts its own `check()` calls at runtime and prints `{n} checks run`, so this number cannot drift from what the script actually did — see EX-045). Covering:

- **Successful customer purchase**: register → buy → pay → provision → service visible (e.g. `'order created'`, `'service created with remote id'`, `'order active after inline provisioning'`)
- **Duplicate payment callback**: same callback twice → one ledger payment, one service (`'duplicate callback detected as replay'`, `'exactly one payment ledger row'`, `'exactly one service'`)
- **Provisioning failure**: pay → transient failure → `provision_failed` → retry → `active`, money never silently consumed (`'transient failure leaves recoverable state'` … `'money never silently consumed'`), plus crash-recovery: an order stuck in `provisioning` and a job stranded in `running` are both reclaimed by the reaper (`'an abandoned provisioning claim is reclaimed'`, `'reap_stale reclaims the abandoned job'`)
- **Recurring billing**: a due service is charged, its clock advances by one term, a replayed charge is recognised not re-charged, and cancellation stops future charges (`'renewal charged'` … `'a cancelled service never charges again'`)
- **Low balance**: usage debits cross threshold → warning stage + single notification (cooldown verified); `negative_since_days` finds the true crossing point, not the newest debit (`'negative_since_days measures the crossing point, not the newest debit'`)
- **Customer isolation**: B cannot read A's rows directly nor via REST; anonymous and wrong-owner callers are refused, not just empty-listed (`'bob cannot read alice service via get_owned'`, `'wrong-owner service read answers 404'`, `'anonymous REST call to … is refused'`)
- **Schema migration**: the v3→v4→v5 backfills run against pre-migration rows and are individually asserted (`'v3→v4 back-stamped the pre-existing ledger row as demo'`, `'v4→v5 gave a clockless service a renewal date'`, `'v4→v5 moved a legacy top-up option into the topups table'`)
- Plus: license activation, idempotent page creation, server-side pricing, tampered-amount rejection, top-up replay safety, usage-sync dedup across two full runs, per-customer spending/credit limits (and the explicit non-gate on `credit_limit` at checkout), the suspend-service policy action, and the demo-mode-exit boundary (sandbox proofs refused once real mode is live).

This layer found a real bug during development (`insert_id` vs `rows_affected` duplicate detection) — recorded in commit `7f9d2bf`. Then **three rounds of adversarial review** drove the code to convergence: a three-lens panel found a batch of real defects (sandbox-as-live-gateway, unenforced customer limits, a logout hook-order bug, demo/real reconciliation mixing — commit `9ec42ae`); a verification round caught **regressions those fixes introduced** (commit `887ea33`); and a two-reviewer convergence round found the last two majors — a suspension-lift gap at the critical/grace band and two unguarded ledger throws on the refund path (commit `375d629`). Every round's findings were fixed and re-tested, not argued away.

## 3. Manual / visual

- Screenshots under `docs/screenshots/` were captured from the live sandbox (storefront, product, auth, customer dashboard ×3, admin dashboard, wizard, health, mobile 390 px ×2).
- The mobile + laptop click-through checklist is [`docs/demo-checklist.md`](docs/demo-checklist.md).

## CI

`.github/workflows/ci.yml`: syntax lint on PHP 7.4/8.2/8.3 (7.4 = minimum runtime), a dedicated **PHP 7.4 compatibility gate** (`php bin/php74-check.php` — parses every shipped file against a real PHP 7.4 grammar via `nikic/php-parser` and walks the AST for PHP 8-only constructs, because `php -l` only ever validates against the interpreter running it and cannot defend a "Requires PHP 7.4" header from an 8.x CI runner; evidence: `102 file(s) parsed against the PHP 7.4 grammar; 0 violation(s)`), unit suite on 8.2/8.3, and a secret-scan step that fails the build if a plaintext access token or API key literal is committed.

## What is NOT covered (honest)

- Layer-2 E2E runs against the SQLite integration in this environment; a MySQL wp-env run is documented and expected to pass (same SQL dialect subset, and `rows_affected` semantics were chosen for both) but was not executed on this machine (no Docker).
- No browser-driven Playwright test suite (manual visual pass + REST-level dispatch checks instead) — first item in ROADMAP's quality section.
- Load/performance is modeled (CAPACITY_MODEL), not benchmarked.
