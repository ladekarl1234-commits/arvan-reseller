# Requirements Traceability

Every significant requirement → spec section → implementation → test evidence → status.

**Test counts live in exactly one place — [`TESTING.md`](../TESTING.md) — and are not repeated as numbers here.** Every other document, including this one, cites test *coverage* by test/check **name** (grep-verifiable, stable under refactors) rather than by count or line range, because a hardcoded number here is precisely the kind of claim that silently drifts once the suite it describes grows. Test refs: **U** = `tests/unit/*`, **E** = `tests/integration/e2e.php` (name is the `check('name', …)` string, or a `*Test` class for unit tests — `grep` either directly).

| Requirement | Spec | Implementation | Tests | Status |
|---|---|---|---|---|
| Standalone plugin, no WooCommerce/theme deps | HC-1 | shortcodes + own templates/assets; zero runtime deps | E: whole run happens on bare WP with no other plugin active | ✅ |
| Plugin Access Token gating, hashes only | HC-4, §SEC-6 | `Licensing\License`, `data/license-hashes.php` | U: `LicenseTest`; E: `'invalid PAT rejected'`, `'valid PAT activates'` | ✅ |
| Onboarding wizard (**7** stages, validated, Back/Continue) | §5.1 | `Onboarding\Wizard::STEPS = ['welcome','license','identity','arvan','pricing','pages','ready']` | E: `'pages created'`, `'page creation idempotent'`; manual checklist | ✅ |
| Reseller branding + secure logo upload | §SEC-8 | `Admin\Actions::save_branding` (MIME/size whitelist) | code-review + manual | ✅ |
| Automatic idempotent page creation | §5.1 | `Install\PageFactory` | E: `'pages created'`, `'page creation idempotent'` | ✅ |
| Customer auth on WP users, no wp-admin | §5.5 | `Identity\Customers` | E: `'customers registered'`, `'duplicate email rejected'`; manual | ✅ |
| Cloud Server / CDN / Object Storage storefronts | HC-2 | `Front\Shortcodes::product`, catalog, templates | E: `'order created'` and the config-validation checks around it | ✅ |
| Live metadata + caching | §12 | `Arvan\Catalog` (6 h transient, negative-cache on failure, stale-while-revalidate, refresh) | code + manual | ✅ |
| Pricing engine (global/product/customer, snapshot) | §6, HC-6 | `Pricing\PricingEngine` + `Pricing` + `BaseCosts` | U: `PricingEngineTest`; E: `'price = base × 1.2'`, `'pricing snapshot persisted'` | ✅ |
| Customer-specific commercial rules (markup/discount/limits) | §6, §customer rules | `Customers\Rules`; **both `spending_limit` and `credit_limit` are checkout gates in `OrderService::create` (see the comment at `sanitize`→`create`, which states "Two distinct per-customer caps, both enforced here"): `spending_limit` caps lifetime spend against the order amount, `credit_limit` refuses a new purchase when the wallet debt already standing exceeds the line. `credit_limit` additionally drives the credit-policy grace→restricted band** (`OrderService.php:126-141`, comment states this explicitly) | E: `'spending_limit blocks over-limit purchase'`, `'credit_limit does NOT block a gateway order (regression fix)'` | ✅ |
| PaymentProvider abstraction + working sandbox | ADR-0006 | `Payments\SandboxProvider` (implements `PaymentProviderInterface`; no production PSP adapter ships) | U: `PaymentVerificationTest`; E: `'payment accepted'`, `'tampered amount fails verify'` | ✅ |
| Callback verified, idempotent, replay-safe | HC-7 | `claim_paid` + ledger unique keys | E: `'duplicate callback detected as replay'`, `'ledger unique key absorbs a replayed payment entry'` | ✅ |
| Order state machine + event history | §5.2 | `Orders\StateMachine`, `order_events` | U: `StateMachineTest`; E events visible in admin order detail | ✅ |
| Instant provisioning, layered idempotency | §5.4 | `Provisioning\Provisioner`; deterministic remote naming makes a create reconcilable after a timeout (`RealProvider::remote_name`) | E: `'service created with remote id'`, `'queued provisioning job after an inline success creates no second service'` | ✅ |
| Provisioning failure → retry → success | §13.5 | jobs + `demo-fail` trigger; job runner branches on `ProviderError::kind`, not message text | E: `'transient failure leaves recoverable state'` … `'order active after retry'`; also `'a fresh provisioning claim is not reclaimed'`, `'an abandoned provisioning claim is reclaimed'` (crash-recovery path) | ✅ |
| Recurring / renewal billing | §5.6, ADR-0007 | `Billing\Renewals` — each service carries its own clock (`renews_at`/`term_days`/`renewal_price`); a daily job charges due services | E: `'a due service is in the renewal batch'`, `'renewal charged'`, `'the billing clock advanced by one term'`, `'a replayed renewal is recognised, not re-charged'`, `'renewal cancellation succeeds'` | ✅ |
| Real Arvan API integration (documented endpoints only) | HC-3 | `Arvan\RealProvider`, `ArvanClient` (verb-aware retry, `Idempotency-Key`, explicit `402` handling) | endpoint audit in `API_INTEGRATION.md`; connection test | ✅ code-complete; live call against a real Arvan account needs a real machine-user token (none available in this environment) |
| Multiple encrypted credentials, routing, health | §multi-credential | `Arvan\Credentials`; daily `credential_health` job | U: `CryptoTest`; manual test-connection; E: credential-scoped service creation | ✅ |
| Secrets encrypted/masked/never logged | HC-8 | `Support\Crypto` (libsodium, key from WP salts), `Audit::redact` | U: `CryptoTest`, redaction unit coverage | ✅ (see `THREAT_MODEL.md` S5 for the salt-source precondition this depends on) |
| Append-only wallet ledger, derived balances | §7 | `Wallet\Ledger` — `balance()`/`balances()` are single indexed SQL aggregates, object-cached | U: ledger derivation unit tests; E: `'purchase settled net-zero wallet effect'`, `'exactly one payment ledger row'` | ✅ |
| Usage engine, idempotent per-period ingestion | §5.6 | `Usage\UsageSync` | U: determinism tests; E: `'first sync ingested usage'`, `'re-sync ingests nothing (dedup)'` | ✅ (real-mode fetch has no upstream API to call — documented in `API_INTEGRATION.md`; recurring revenue in real mode comes from `Billing\Renewals` instead) |
| Period reporting: revenue/cost/margin, MRR, churn | §7 | `Reports\Reports::period/mrr/churn` | manual + admin `گزارش مالی` screen | ✅ |
| Credit policy engine, configurable, never destroys | §5.5 | `Policies\PolicyEngine` + `apply_policy`; `negative_since_days` finds the true crossing point of the negative period | U: `PolicyEngineTest`; E: `'a 400,000 balance stages exactly warning'`, `'negative_since_days measures the crossing point, not the newest debit'`, `'customer reaches restricted stage'` | ✅ |
| Notifications with cooldown | §notifications | `Notifications\Notifier` | E: `'low-balance notification created exactly once'`, `'notification cooldown respected'` | ✅ |
| Admin dashboard (revenue/margin/health/…) | §admin | `Admin\Menu` + templates | screenshots, manual | ✅ |
| Customer dashboard (services/wallet/usage/inbox) | §customer dashboard | `Front\Shortcodes::dashboard` | E: isolation checks below + screenshots | ✅ |
| Customer isolation everywhere | HC-5 | Session-derived IDs for list routes (`Rest\Routes::me_list`); `Services::get_owned()` is the single-row read path — it has production callers now (`Rest\Routes::me_service`, `Front\FormActions::cancel_service`), not just tests | U: `AuthorizationTest`; E: `'bob cannot read alice service via get_owned'`, `'wrong-owner service read answers 404'`, `'anonymous REST call to … is refused'` | ✅ |
| Demo mode = boundary-only simulation | HC-9 | `DemoProvider`, `SandboxProvider`; ledger/usage rows stamped `is_demo` | E: `'demo-mode ledger rows are is_demo stamped'`, `'no unstamped ledger row escaped demo mode'`, `'the store is genuinely out of demo mode'` | ✅ |
| Audit log for sensitive actions | §SEC-10 | `Audit\Audit` | E side-effects + screenshot | ✅ |
| System health + Sync now | §observability | health template + actions | manual + screenshot | ✅ |
| Responsive RTL (390 px + laptop) | HC-10 | front.css breakpoints | screenshots mobile-*, checklist | ✅ |
| Uninstall data retention opt-in | HC-11 | `uninstall.php` | code review | ✅ |
| Automated tests actually executed | §testing | PHPUnit + E2E | counts and evidence: `TESTING.md` | ✅ |
| PHP 7.4 compatibility is a checked claim, not an assertion | HC (runtime) | `bin/php74-check.php` parses every shipped file against a real PHP 7.4 grammar and walks the AST for PHP 8-only syntax | CI job `php74`; evidence in `TESTING.md` | ✅ |
| i18n catalog ships, not just the text-domain plumbing | §12 | `languages/arvan-reseller.pot` + `fa_IR.po`/`.mo`, regenerated by `bin/make-pot.php`/`bin/make-mo.php` | manual (`.mo` loads via `load_plugin_textdomain`) | ✅ |
| CI + secret hygiene | repo quality | `.github/workflows/ci.yml` (lint × 3 PHP versions, PHP 7.4 grammar gate, unit tests × 2 PHP versions, secret scan) | runs on push | ✅ |
| Docs set (spec, ADRs, threat model, scalability…) | addendum | this repo | — | ✅ |

## Known ⚠-adjacent items

Real-mode provisioning is fully implemented against verified endpoints but was not fired against a live paid Arvan account from this environment (no credential available here). Real-mode metered usage fetching has no upstream API at all — recurring revenue in real mode comes from `Billing\Renewals` (term charges), not from usage metering. Both are documented in `API_INTEGRATION.md` and demoed via the boundary-faithful demo provider.
