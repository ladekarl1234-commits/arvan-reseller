# Security

Security is a primary product feature of this plugin. This document maps every implemented control to its code location so a reviewer can verify claims instead of trusting them. The attacker-centric view lives in [`docs/THREAT_MODEL.md`](docs/THREAT_MODEL.md).

## Reporting a vulnerability

Open a **private** GitHub security advisory, or email the maintainer (see repo profile). Do not open public issues for exploitable bugs. You will get an acknowledgement within 72 hours.

## Control inventory

### Authentication & authorization

| Control | Implementation |
|---|---|
| Admin capability checks | Every admin page (`Admin\Menu`) and every admin action (`Admin\Actions::guard`) requires `manage_options`. |
| Customer role without wp-admin | `Identity\Customers`: role `arvrs_customer` has only `read`; wp-admin access redirected away, admin bar hidden. |
| Object-level authorization | `/me/*` REST handlers derive the customer ID from the session (`get_current_user_id()`), never from the request. `Services::get_owned()` centralizes row-ownership checks. `Notifier::mark_read` scopes the UPDATE by `customer_id` in SQL. |
| No hidden-button security | Rendering and authorization are independent: even a hand-crafted request hits `permission_callback` / `guard()`. |

### CSRF

- Every admin-post action: `check_admin_referer()` with a per-action nonce (`Admin\Actions`, `Onboarding\Wizard`, `Front\FormActions`).
- Every state-changing customer REST route: WordPress cookie auth requires the `X-WP-Nonce: wp_rest` header (sent by `assets/js/front.js`).
- The payment callback is intentionally nonce-free (gateways cannot send nonces); its authenticity control is the provider-side `verify()` (below).

### XSS

- All output escaped at the sink: `esc_html` / `esc_attr` / `esc_url` / `esc_textarea` throughout `templates/`. The only `phpcs:ignore EscapeOutput` sites echo values built exclusively from escaped parts (`Helpers::status_tag`).
- Input sanitized per-field with the narrowest sanitizer (`sanitize_email`, `sanitize_key`, regex whitelists for domains/buckets/flavors).

### SQL injection

- 100% of variable SQL goes through `$wpdb->prepare()`. Identifier interpolation is limited to the plugin's own constant table names.
- Dynamic `IN (...)` lists are built from placeholder templates (`OrderService::claim_paid`).

### Mass assignment

- `Support\Options` writes only whitelisted keys (`DEFAULTS`), so a forged settings POST cannot create arbitrary options.
- `Customers\Rules::save`, `Credentials::save`, `Actions::*` copy named fields only — request bodies are never saved wholesale.

### Secrets

| Rule | Implementation |
|---|---|
| Encrypted at rest | `Support\Crypto`: libsodium `secretbox` (XSalsa20-Poly1305), fresh nonce per encryption. Key = HMAC-SHA256 of WP auth salts with a fixed context string — never stored on disk. |
| Never displayed | UI shows `••••last4` only (`Credentials::all` strips `token_enc`). |
| Never in REST | No REST route selects `token_enc`. |
| Never logged | `Audit::redact` strips keys matching `token/password/secret/authorization/pat` recursively before persistence. |
| PAT storage | `Licensing\License` stores only `active` + a 12-hex SHA-256 fingerprint; verification is `password_verify` against bundled bcrypt hashes. MD5/SHA1 are used nowhere as password storage. |

### Payments (HC-7)

1. Price recomputed server-side at checkout (`Pricing::quote`); client price fields do not exist.
2. Callback verification: `SandboxProvider::verify` recomputes an HMAC over `(ref|amount|type)` keyed with `wp_salt('nonce')` and compares with `hash_equals`. A real PSP adapter replaces this with the PSP's verify API — same interface, same call site.
3. Amount binding: the claim SQL matches `amount = verified_amount` in the same `UPDATE`.
4. Replay safety: the claim flips `status` only from payable states; zero affected rows → idempotent "already processed" answer with **no side effects**. Ledger entries ride `UNIQUE(ref_type, ref_id, type)` + `INSERT IGNORE`, so even a raced duplicate cannot double-credit.
5. Rate limiting on the callback endpoint per IP (`Helpers::rate_limit`).

### Provisioning idempotency

Three independent layers (`Provisioning\Provisioner`):
1. Existing service row for the order → return it, done.
2. Atomic state claim `paid → provisioning` (optimistic `UPDATE … WHERE status = …`).
3. `UNIQUE KEY (order_id)` on the services table + `INSERT IGNORE`.

A browser refresh, duplicated callback, or twice-delivered job cannot create two remote resources.

### REST API

- Every route declares `permission_callback` and an `args` schema with types/enums/ranges.
- High-risk endpoints (callback, checkout, login/register) are rate-limited (fixed-window transients).
- List endpoints paginate server-side.

### Uploads

- Logo only, via `media_handle_upload` with an explicit MIME whitelist (PNG/JPG/WebP), 1 MB cap, `wp_check_filetype_and_ext` verification. SVG is rejected (XSS vector).

### SSRF

- The plugin performs outbound HTTP only to the fixed ArvanCloud hosts (`ArvanClient::BASE`, storage management host). No customer-supplied URL is ever fetched. CDN domains are data in an API body, not fetch targets.

### Auditability

`Audit\Audit` writes append-only rows (actor, action, object, redacted detail, IP) for: license activation/failure, credential save/test/delete, pricing/policy changes, wallet adjustments, refunds, provisioning success/failure, registration. Surfaced in wp-admin → گزارش امنیتی.

### Failure UX

Customers see translated Persian messages (`ProviderError::customer_message`); raw upstream errors and stack traces appear only in the redacted admin error log.

## Known security limitations

- The static PAT allowlist prevents casual misuse, not determined piracy (a code-modified plugin bypasses any offline check). Documented as a hackathon constraint; ADR-0009 defines the remote signed-license path.
- Salt rotation invalidates encrypted credentials by design; the UI detects this and asks for re-entry (fails closed, never plaintext-recovers).
- Fixed-window rate limiting on transients is per-server, not global, under object-cache-less multi-server setups.
