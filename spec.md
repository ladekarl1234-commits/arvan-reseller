# Specification — Arvan Reseller Platform (WordPress plugin)

> Engineering source of truth. Product docs live in `README.md`; decisions in `docs/adr/`.
> Status key: **MUST** (hard constraint), **SHOULD** (required unless justified), **MAY** (optional).

## 1. Mission

Turn any standard WordPress site into a white-label storefront that sells ArvanCloud
**Cloud Server**, **CDN** and **Object Storage** with automatic provisioning: the reseller
configures their ArvanCloud API credential once; customers register, pay on the reseller's
site and receive their cloud resource without anyone touching the ArvanCloud panel manually.

## 2. Personas

| Persona | Description | Interface |
|---|---|---|
| **Reseller admin** | Owns the WordPress site and one or more ArvanCloud accounts. Sets pricing, brand, policies. | wp-admin plugin pages |
| **Customer** | Buys cloud services on the reseller's site. Not a cloud expert. Persian speaker. | Front-end storefront + customer dashboard (never wp-admin) |
| **Hackathon judge** | Evaluates the product in Demo Mode without real credentials. | Both, via demo seed |

## 3. Hard constraints

- **HC-1** Standalone WordPress plugin. No WooCommerce/Elementor/theme dependency. Works on a default WP install with any theme. No Node.js at runtime.
- **HC-2** Products: Cloud Server, CDN, Object Storage.
- **HC-3** No invented ArvanCloud endpoints. Anything not officially documented is isolated behind a provider abstraction and listed in `docs/API_INTEGRATION.md` § Limitations.
- **HC-4** Plugin Access Token (licensing) and ArvanCloud API token are separate concepts; never mixed.
- **HC-5** Customer isolation: object-level authorization on every order/service/ledger read and mutation.
- **HC-6** Prices are computed server-side only; client-submitted prices are never trusted.
- **HC-7** Payment callbacks are verified, idempotent and replay-safe. A duplicate callback never double-credits or double-provisions.
- **HC-8** Secrets (Arvan tokens) encrypted at rest, masked in UI, never logged, never returned by REST.
- **HC-9** Demo Mode simulates only the external boundary (Arvan API, payment gateway); all internal flows are real.
- **HC-10** Persian-first, RTL, responsive (≥390 px mobile and laptop widths).
- **HC-11** Uninstall never destroys financial/customer data without an explicit admin opt-in.

## 4. Terminology

| Term | Meaning |
|---|---|
| **Plugin Access Token (PAT)** | Plaintext token the reseller receives out-of-band; plugin ships only bcrypt hashes of valid tokens. Activates selling capability. |
| **Credential** | One ArvanCloud API token entry (encrypted) with name, enabled flag, allowed products, priority. |
| **Product** | `cloud_server`, `cdn`, `object_storage`. |
| **Plan** | A purchasable configuration of a product (e.g. ECC flavor g1-2-2, CDN tier, storage package). Catalog comes from the active Arvan provider; cached. |
| **Order** | A customer's intent to buy one plan, with an immutable pricing snapshot. |
| **Service** | A provisioned resource: local record mapped to `(customer, order, credential, product, remote_id)`. |
| **Ledger** | Append-only financial journal; balances are derived, never stored as a mutable number. |
| **Job** | Durable row in the jobs table processed by the WP-Cron runner with retry/backoff. |

## 5. Lifecycles

### 5.1 Plugin lifecycle
`installed → activated (schema migrated) → licensed (PAT verified) → onboarded (wizard finished) → operating`.
Re-activation and repeated wizard runs are idempotent (page creation checks stored IDs; migrations are versioned).

### 5.2 Order state machine (authoritative)

```
draft → pending_payment → payment_processing → paid → provisioning → active
pending_payment|payment_processing → cancelled
provisioning → provision_failed → provisioning (retry) | refunded
paid → provision_failed (no capacity etc.)
active → cancelled (service termination)
paid|active → refunded (admin action)
```

> `payment_processing` is a reserved state for future asynchronous PSP adapters
> (bank-redirect gateways that report an interim state). The shipped sandbox
> settles synchronously, so 1.0 orders move `pending_payment → paid` directly;
> the claim logic already treats both as payable.

Transitions outside this table are rejected and logged. Every transition writes an
`order_events` row `(order_id, from, to, actor, note, created_at)`.

### 5.3 Payment lifecycle
1. Checkout creates order `pending_payment` with pricing snapshot + unique `payment_ref`.
2. Customer is sent to the payment provider (Sandbox provider renders an on-site gateway simulation page with success/fail buttons).
3. Callback hits `POST /arvan-reseller/v1/payment/callback` with `payment_ref` + provider proof.
4. Handler verifies proof with the provider (server-side `verify()`), matches expected amount, then **atomically claims** the order via `UPDATE … SET status='paid' WHERE status IN ('pending_payment','payment_processing')`. Zero affected rows ⇒ replay ⇒ respond idempotently with current state, no side effects.
5. On claim: ledger `purchase` debit + `payment` credit written in one DB transaction keyed by `payment_ref` (unique index ⇒ second insert impossible), then provisioning job enqueued (or run inline; § 5.4).

### 5.4 Provisioning lifecycle
- Job `provision_order` claims the order (`status='paid' → 'provisioning'` atomic claim).
- Provider `create(product, plan, config, idempotency_key)` — the idempotency key is `order:{id}`; before calling out, the service checks for an existing service row for the order (unique index on `order_id`) — refresh/retry can never create a second remote resource.
- Success: service row saved with remote id + connection info; order → `active`; customer notified.
- Failure: order → `provision_failed`; job retried with backoff (max 5); admin notified; money stays visible in ledger with the failed state — never silently consumed. Admin may retry or refund.

### 5.5 Customer lifecycle
WP user with role `arvrs_customer` (no wp-admin access). States (policy engine): `healthy → warning → critical → grace → restricted → suspended` derived from balance vs configurable thresholds. Actions per state are configurable (notify / block purchases / mark at risk / suspend where API supports). Resources are never auto-destroyed by a local balance calculation.

### 5.6 Usage accounting
Cron (hourly) + admin **Sync now**: for each active service, the provider's usage source returns period records `(service, period_start, period_end, quantity, unit, cost)`. A unique index on `(service_id, period_start, period_end)` makes ingestion idempotent — a period is debited exactly once. Each ingested record writes a ledger `usage_debit` referencing the usage row.

## 6. Pricing

- `PricingEngine::quote(product, plan, customer)` returns a **PriceQuote**: base cost, applied rule, margin, customer price, currency (IRT), version, timestamp.
- Rule resolution order (first match wins): customer override → product rule → global rule. **Customer-group pricing is an optional tier not implemented in 1.0** (the resolution chain is designed to accept it; see ROADMAP) — the shipped engine covers global, per-product and per-customer, which subsumes the group case for the hackathon scope.
- Rule shape: `markup_percent` (float ≥ −100), `fixed_adjustment` (signed IRT), optional `discount_percent`.
- Base costs: no official pricing API exists ⇒ admin-maintained base-cost table seeded from the public pricing page, with source + last-updated stamps (`PricingProvider` abstraction keeps a future API swap cheap). Documented in ADR-0007.
- Orders persist the full quote as an immutable JSON snapshot.

## 7. Wallet semantics

Append-only ledger; every row: `customer_id, type, direction (credit|debit), amount, currency, ref_type, ref_id, description, actor, created_at`, unique `(ref_type, ref_id, type)` where applicable.
Types: `topup, purchase, payment, usage_debit, adjustment, refund, promo_credit, reservation, release`.
Derived figures: `available = credits − debits − open reservations`; also exposed: reserved, consumed, total top-up. Admin reconciliation view aggregates per customer and per credential.

## 8. Security requirements

| ID | Requirement |
|---|---|
| SEC-1 | All admin REST/forms: `current_user_can('manage_options')` + nonce. |
| SEC-2 | All customer REST: authenticated + row ownership check (`customer_id = get_current_user_id()`), IDs from server-side lookup only. |
| SEC-3 | Output escaping at sink (`esc_html/esc_attr/esc_url/wp_kses_post`); input sanitized per-field with whitelists — no blind `$_POST` saves. |
| SEC-4 | All SQL through `$wpdb->prepare()` or strictly whitelisted identifiers. |
| SEC-5 | Arvan tokens encrypted (libsodium secretbox, key = HKDF of WP auth salts), masked (`…last4`), never in REST responses or logs. |
| SEC-6 | PAT verified with `password_verify` against bcrypt hashes; plaintext never stored. |
| SEC-7 | Payment callback: provider-side verify + amount match + atomic claim (HC-7). |
| SEC-8 | Uploads via WP media API, MIME/size restricted (logo: png/jpg/svg†/webp ≤ 1 MB; svg sanitized or rejected — decision: **rejected**, no svg). |
| SEC-9 | No customer-controlled URLs are fetched server-side (SSRF: outbound HTTP goes only to the fixed Arvan base URL). |
| SEC-10 | Security-sensitive admin actions (credential CRUD, pricing change, refund, policy change, license reset) write audit rows. |
| SEC-11 | Rate limit: login-adjacent and callback endpoints throttled per-IP via transients. |
| SEC-12 | Errors shown to customers are translated Persian messages; raw exceptions only in admin log viewer (redacted). |

## 9. REST API (namespace `arvan-reseller/v1`)

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/catalog/(product)` | GET | public | cached plan/region/image metadata |
| `/checkout` | POST | customer + nonce | create order from plan + config (server-priced) |
| `/payment/callback` | POST/GET | signed by provider | § 5.3 |
| `/me/summary` | GET | customer | balance, services, orders overview |
| `/me/orders`, `/me/services`, `/me/ledger`, `/me/usage` | GET | customer | paginated, owner-scoped |
| `/me/topup` | POST | customer + nonce | start wallet top-up (payment provider) |
| `/me/notifications/{id}/read` | POST | customer | owner-scoped mark-read |

All routes: `permission_callback` + `args` schema validation.
Admin operations intentionally use `admin-post.php` handlers (capability + per-action nonce) instead of REST — equivalent protection, no-JS-required forms (ADR-0002/0005).

## 10. Data model (custom tables, prefix `{$wpdb->prefix}arvrs_`)

`credentials, orders, order_events, services, ledger, usage_records, jobs, audit_log, notifications, customer_rules, base_costs`
(columns and indexes: `docs/DATA_MODEL.md`; migration versioning via `arvrs_schema_version` option; all migrations idempotent).
Brand/settings/wizard state: `wp_options` (low volume). Identity: WP users + minimal usermeta.

## 11. Demo mode

- Toggle in settings + forced when no real credential passes a connection test.
- `DemoArvanProvider`: in-memory/option-backed catalog identical in shape to real API DTOs; `create` returns realistic fake resources after a short simulated delay; usage source generates plausible per-service consumption so policies can be demonstrated.
- `SandboxPaymentProvider`: on-site payment page with **pay / fail / duplicate-callback** buttons (the duplicate button proves HC-7 live).
- Admin bar + dashboard show a persistent «حالت دمو» badge. Demo services are flagged `is_demo=1` and never mixed into real reconciliation.

## 12. Non-functional

- Assets enqueued only on plugin pages (admin) / plugin-generated pages (front).
- Catalog cached (transient, 6 h; manual refresh). No external HTTP during normal page render.
- Tables paginated server-side; indexes on every FK + status + created_at.
- i18n-ready (`arvan-reseller` text domain); shipped strings Persian.
- Accessibility: semantic HTML, labels, focus states, contrast per Sorkhab tokens.

## 13. Acceptance criteria (Definition of Done extract)

1. Fresh WP + plugin ZIP → activation wizard runs; invalid PAT blocks selling; valid PAT proceeds.
2. Wizard creates the 3 storefront pages + dashboard/auth/checkout pages idempotently.
3. Demo end-to-end: register → configure cloud server → checkout → sandbox pay → auto-provision → service visible in customer dashboard, ledger shows purchase, admin sees order/service/margin.
4. Duplicate callback (button) → still exactly one ledger payment and one service.
5. Kill-switch demo: provisioning failure → `provision_failed` → admin retry → `active`.
6. Usage sync (Sync now) debits ledger once per period; re-sync adds nothing.
7. Low balance crosses threshold → warning notification once (cooldown respected).
8. Customer B cannot read A's order/service/ledger via REST (test evidence).
9. Real provider: documented endpoints wired for ECC/CDN; undocumented gaps listed.
10. PHPUnit suite green (pricing, ledger, state machine, license, crypto, policy, dedup); CI configured.
11. No secrets in repo; docs complete per `docs/REQUIREMENTS_TRACEABILITY.md`.

## 14. Out of scope

Reseller↔Arvan financial settlement; real gateway adapters (interface only); multi-currency; sub-user Arvan accounts; automatic upstream suspension where no documented endpoint exists; DNS management UI beyond CDN basics.

## 15. Known unknowns

Recorded with evidence in `docs/API_INTEGRATION.md` after API research: object-storage provisioning API availability, per-resource usage/billing endpoints, rate limits. Where absent → Demo provider parity + documented manual fallback.
