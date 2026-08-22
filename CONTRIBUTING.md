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
Register a handler via `JobRunner::handle('your_type', $callable)` — either add it in `Jobs\Handlers::register()` if it's a core type, or hook the `arvrs_job_handlers` filter from outside the module (this is the extraction seam: `JobRunner` itself imports nothing from Provisioning/Usage/Billing). The handler throws to mean "retry", returns to mean "done" — decide retryable vs terminal explicitly by branching on a typed result (`ProviderError::kind`, not message text: see `Handlers::provision_order` for the pattern). Add an enqueue site and an E2E check for the new type.

### Touch the ledger
Read [ADR-0007](docs/adr/0007-wallet-ledger-model.md) first. New entry types need: direction mapping, a unique ref scheme, derivation handling in `Ledger::derive`, unit tests, and an ADR update. Ledger rows are never updated or deleted — if you need that, you're modeling the problem wrong.

### Record a decision
Irreversible or expensive-to-change choices get a numbered ADR (`docs/adr/`) using the existing section template.

## Translations

Every UI string goes through the `arvan-reseller` text domain. The template lives at `languages/arvan-reseller.pot`; a Persian translation ships (`languages/arvan-reseller-fa_IR.po`/`.mo`).

- **Regenerate the template** after adding/changing strings: `php bin/make-pot.php`.
- **Compile a `.po` to `.mo`** after editing a translation: `php bin/make-mo.php languages/arvan-reseller-<locale>.po`.
- **Add a new locale**: copy `languages/arvan-reseller.pot` to `languages/arvan-reseller-<locale>.po` (WordPress locale codes, e.g. `ar` or `en_US`), translate the msgstr entries, then compile it with `bin/make-mo.php`. WordPress loads the matching `.mo` automatically once the site locale matches (`{text-domain}-{locale}.mo` in `languages/` is the WordPress convention this follows).

## Security issues

Never as public issues — see [`SECURITY.md`](SECURITY.md#reporting-a-vulnerability).

## PR expectations

Green CI (lint 7.4/8.2/8.3 + units + secret scan), template checklist completed, screenshots for UI changes (RTL, 390 px + laptop), docs updated with the code they describe.
