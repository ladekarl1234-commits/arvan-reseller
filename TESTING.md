# Testing

Three layers, each with an honest statement of what it does and does not prove. Everything documented here **was actually executed** — the evidence lines quote real output.

## 1. Unit tests (PHPUnit, no WordPress)

```bash
composer test
```

Evidence (this repo, PHP 8.5): `OK (46 tests, 158 assertions)` in ~1.5 s.

| Suite | Covers |
|---|---|
| `PricingEngineTest` | global/product/customer markup precedence, discount stacking + cap, fixed adjustments, floors, snapshot fields, hackathon example (10M × 1.2 = 12M) |
| `StateMachineTest` | happy path, failure/retry path, illegal jumps, terminal states, unknown-state rejection |
| `LedgerDerivationTest` | derived balances, reservations/releases, negative balances, exhaustive direction mapping, unknown-type rejection |
| `PolicyEngineTest` | threshold staging incl. boundaries, grace→restricted timing, admin-configured action filtering, destructive-action gating |
| `LicenseTest` | rejection paths, storage shape, no-plaintext-in-repo guarantee, bcrypt round-trip |
| `CryptoTest` | encrypt/decrypt round-trip, per-encryption nonces, tamper → null, invalid input, masking |
| `PaymentVerificationTest` | proof verifies; tampered amount / wrong ref / wrong type / missing proof all fail |
| `UsageAndRedactionTest` | demo-usage determinism (dedup foundation), closed-periods-only, idempotent create, unknown-resource skip, recursive log redaction, Persian error mapping |

The bootstrap (`tests/bootstrap.php`) shims ~20 WP functions; anything needing `$wpdb` for real belongs in layer 2.

## 2. Integration / E2E (real WordPress)

```bash
wp eval-file tests/integration/e2e.php     # fresh install; see DEVELOPMENT.md
```

Evidence (WordPress 6.x + SQLite integration, this machine): `ALL E2E CHECKS PASSED` — **46 checks**, covering exactly the required scenarios:

- **Successful customer purchase**: register → buy → pay → provision → service visible (checks 6–16, 35–36)
- **Duplicate payment callback**: same callback twice → one ledger payment, one service (checks 17–19)
- **Provisioning failure**: pay → transient failure → `provision_failed` → retry → `active`, money never silently consumed (checks 37–42)
- **Low balance**: usage debits cross threshold → warning stage + single notification (cooldown verified) (checks 27–30)
- **Customer isolation**: B cannot read A's rows directly nor via REST; private fields stripped (checks 31–36)
- Plus: license activation, idempotent page creation, server-side pricing, tampered-amount rejection, top-up replay safety, usage-sync dedup across two full runs, per-customer spending/credit limits, and the suspend-service policy action.

This layer found a real bug during development (`insert_id` vs `rows_affected` duplicate detection) — recorded in commit `7f9d2bf`. A three-lens adversarial review panel then found and drove fixes for a batch of real defects (sandbox-as-live-gateway, unenforced customer limits, a logout hook-order bug, demo/real reconciliation mixing, and more) — recorded in commit `9ec42ae`.

## 3. Manual / visual

- Screenshots under `docs/screenshots/` were captured from the live sandbox (storefront, product, auth, customer dashboard ×3, admin dashboard, wizard, health, mobile 390 px ×2).
- The mobile + laptop click-through checklist is [`docs/demo-checklist.md`](docs/demo-checklist.md).

## CI

`.github/workflows/ci.yml`: syntax lint on PHP 7.4/8.2/8.3 (7.4 = minimum runtime), unit suite on 8.2/8.3, and a secret-scan step that fails the build if a plaintext access token or API key literal is committed.

## What is NOT covered (honest)

- Layer-2 E2E runs against the SQLite integration in this environment; a MySQL wp-env run is documented and expected to pass (same SQL dialect subset, and `rows_affected` semantics were chosen for both) but was not executed on this machine (no Docker).
- No browser-driven Playwright test suite (manual visual pass + REST-level dispatch checks instead) — first item in ROADMAP's quality section.
- Load/performance is modeled (CAPACITY_MODEL), not benchmarked.
