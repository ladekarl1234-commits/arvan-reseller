# Contributing

## Ground rules

1. **`spec.md` is the source of truth.** Behavior changes update the spec in the same PR.
2. **Module boundaries are law.** Presentation → Application → Infrastructure, never upward (`ARCHITECTURE.md` has the ownership table). No business logic in templates; no SQL in controllers; no Arvan HTTP outside `src/Arvan/`.
3. **Money paths require tests.** Anything touching ledger, claims, pricing or idempotency ships with a unit test (pure logic) and/or an E2E check (`tests/integration/e2e.php`).
4. **Security checklist on every PR** — the template asks; answer honestly.

## Setup

See [`DEVELOPMENT.md`](DEVELOPMENT.md) — clone → `composer install` → `composer test` should be green before you start.

## Branch & commit conventions

- Branches: `feat/<topic>`, `fix/<topic>`, `docs/<topic>`.
- Commits: conventional style seen in history — `feat(wallet): …`, `fix(idempotency): …`, `security(api): …`, `docs(adr): …`. Coherent commits over squash-everything; no drive-by reformatting.

## Code style

- PHP 7.4 compatible (no `match`, enums, promoted constructors, `?->`).
- WordPress security idioms verbatim: `$wpdb->prepare()` always, escape at sink, nonce + capability on every state change, whitelist every input field.
- Persian UI strings through `__('…', 'arvan-reseller')`; engineering docs in English.

## How to…

### Add a payment provider
Full walkthrough: [`docs/extending-payment-provider.md`](docs/extending-payment-provider.md).

### Add a cloud product
1. `Catalog::PRODUCTS` + label; 2. `RealProvider`/`DemoProvider` branches for `plans/options/create/status/delete`; 3. config whitelist in `OrderService::sanitize_config`; 4. product template branch + `PageFactory` page; 5. base-cost seed rows; 6. E2E check for the purchase path; 7. spec §catalog update.

### Add a migration
Bump `ARVRS_SCHEMA_VERSION`, extend `Schema::migrate` (dbDelta-idempotent — new columns/keys only, never destructive), note it in the PR's database-impact checkbox.

### Add a REST endpoint
Register in `Rest\Routes` with `permission_callback` + full `args` schema; owner-scope every query by session user; add a dispatch-level check to the E2E script.

### Add a job type
One `case` in `JobRunner::execute` + an enqueue site; decide retryable vs terminal errors explicitly (throw = retry).

### Touch the ledger
Read [ADR-0007](docs/adr/0007-wallet-ledger-model.md) first. New entry types need: direction mapping, a unique ref scheme, derivation handling in `Ledger::derive`, unit tests, and an ADR update. Ledger rows are never updated or deleted — if you need that, you're modeling the problem wrong.

### Record a decision
Irreversible or expensive-to-change choices get a numbered ADR (`docs/adr/`) using the existing section template.

## Security issues

Never as public issues — see [`SECURITY.md`](SECURITY.md#reporting-a-vulnerability).

## PR expectations

Green CI (lint 7.4/8.2/8.3 + units + secret scan), template checklist completed, screenshots for UI changes (RTL, 390 px + laptop), docs updated with the code they describe.
