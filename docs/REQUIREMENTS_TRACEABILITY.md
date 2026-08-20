# Requirements Traceability

Every significant requirement → spec section → implementation → test evidence → demo step → status. Test refs: **U** = `tests/unit/*` (46 passing), **E** = `tests/integration/e2e.php` (42 checks passing on real WP). Demo steps refer to `demo-script-fa.md` timestamps.

| Requirement | Spec | Implementation | Tests | Demo | Status |
|---|---|---|---|---|---|
| Standalone plugin, no WooCommerce/theme deps | HC-1 | shortcodes + own templates/assets; zero runtime deps | E (runs on bare WP) | 0:30 | ✅ |
| Plugin Access Token gating, hashes only | HC-4, §SEC-6 | `Licensing\License`, `data/license-hashes.php` | U:LicenseTest, E:1–3 | 0:30 | ✅ |
| Onboarding wizard (8 stages, validated, Back/Continue) | §5.1 | `Onboarding\Wizard` + `templates/admin/wizard.php` | E:4–5 (pages, idempotency); manual checklist | 0:30–2:30 | ✅ |
| Reseller branding + secure logo upload | §SEC-8 | `Admin\Actions::save_branding` (MIME/size whitelist) | code-review + manual | 1:30 | ✅ |
| Automatic idempotent page creation | §5.1 | `Install\PageFactory` | E:4–5 | 2:15 | ✅ |
| Customer auth on WP users, no wp-admin | §5.5 | `Identity\Customers` | E:6–7, manual | 3:15 | ✅ |
| Cloud Server / CDN / Object Storage storefronts | HC-2 | `Front\Shortcodes::product`, catalog, templates | E:8–11 (order per product config) | 2:30 | ✅ |
| Live metadata + caching | §12 | `Arvan\Catalog` (6 h transients, refresh) | code + manual | 2:30 | ✅ |
| Pricing engine (global/product/customer, snapshot) | §6, HC-6 | `Pricing\PricingEngine` + `Pricing` + `BaseCosts` | U:PricingEngineTest, E:9–10 | 2:00 | ✅ |
| Customer-specific commercial rules (markup/discount/limits) | §6, §customer rules | `Customers\Rules`; spending_limit & credit_limit enforced in `OrderService::create` | E:43–44 (limits block purchase) | 6:00 | ✅ |
| PaymentProvider abstraction + working sandbox | ADR-0006 | `Payments\*` | U:PaymentVerificationTest, E:12–14 | 3:15 | ✅ |
| Callback verified, idempotent, replay-safe | HC-7 | `claim_paid` + ledger unique keys | E:17–19, 24–25 | 4:00 | ✅ |
| Order state machine + event history | §5.2 | `Orders\StateMachine`, `order_events` | U:StateMachineTest, E events visible | 5:00 admin | ✅ |
| Instant provisioning, layered idempotency | §5.4 | `Provisioning\Provisioner` | E:14–16, 19 | 3:45 | ✅ |
| Provisioning failure → retry → success | §13.5 | jobs + `demo-fail` trigger | E:37–42 | 5:00 | ✅ |
| Real Arvan API integration (documented endpoints only) | HC-3 | `Arvan\RealProvider`, `ArvanClient` | endpoint audit in API_INTEGRATION; connection test | 6:00 (credentials) | ✅ code-complete; live call needs a real token |
| Multiple encrypted credentials, routing, health | §multi-credential | `Arvan\Credentials` | U:CryptoTest; manual test-connection | 6:00 | ✅ |
| Secrets encrypted/masked/never logged | HC-8 | `Crypto`, `Audit::redact` | U:CryptoTest, U:redaction | 6:00 | ✅ |
| Append-only wallet ledger, derived balances | §7 | `Wallet\Ledger` | U:LedgerDerivationTest, E:20–26 | 4:15 | ✅ |
| Usage engine, idempotent per-period ingestion | §5.6 | `Usage\UsageSync` | U:determinism, E:24–26 | 5:30 | ✅ (real-mode fetch blocked upstream — documented) |
| Credit policy engine, configurable, never destroys | §5.5 | `Policies\PolicyEngine` + apply_policy; suspend_service = reversible local hold, per-customer grace_days | U:PolicyEngineTest, E:27–30, 45–46 | 5:30 | ✅ |
| Notifications with cooldown | §notifications | `Notifications\Notifier` | E:29–30 | 5:30 | ✅ |
| Admin dashboard (revenue/margin/health/…) | §admin | `Admin\Menu` + templates | screenshots, manual | 6:00 | ✅ |
| Customer dashboard (services/wallet/usage/inbox) | §customer dashboard | `Front\Shortcodes::dashboard` | E:35–36 + screenshots | 4:15 | ✅ |
| Customer isolation everywhere | HC-5 | session-scoped queries, `get_owned` | E:31–36 | — (implicit) | ✅ |
| Demo mode = boundary-only simulation | HC-9 | `DemoProvider`, `SandboxProvider` | E (entire run), U:determinism | throughout | ✅ |
| Audit log for sensitive actions | §SEC-10 | `Audit\Audit` | E side-effects + screenshot | 6:30 | ✅ |
| System health + Sync now | §observability | health template + actions | manual + screenshot | 5:30 | ✅ |
| Responsive RTL (390 px + laptop) | HC-10 | front.css breakpoints | screenshots mobile-*, checklist | 6:45 | ✅ |
| Uninstall data retention opt-in | HC-11 | `uninstall.php` | code review | — | ✅ |
| Automated tests actually executed | §testing | PHPUnit + E2E | 46 U + 42 E green | — | ✅ |
| CI + secret hygiene | repo quality | `.github/workflows/ci.yml` | runs on push | — | ✅ |
| Docs set (spec, ADRs, threat model, scalability…) | addendum | this repo | — | — | ✅ |

**Legend of the two ⚠-adjacent items:** real-mode provisioning is fully implemented against verified endpoints but was not fired against a live paid Arvan account from this environment (no credential available here); real-mode usage fetching has no upstream API at all — both are documented in `API_INTEGRATION.md` and demoed via the boundary-faithful demo provider.
