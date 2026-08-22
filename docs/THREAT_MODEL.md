# Threat Model

Attacker-centric analysis. Assets, adversaries, attack scenarios, and the specific control that stops each. Verified against the code — every mitigation names its implementation site.

## Assets

1. **A1 — Reseller's ArvanCloud API tokens** (full account control upstream)
2. **A2 — Customer money** (wallet balances, order amounts)
3. **A3 — Customer resources & data** (servers, buckets, domains, connection info)
4. **A4 — Reseller margin data** (base costs, markup rules — commercially sensitive)
5. **A5 — Plugin activation** (selling capability)
6. **A6 — Site integrity** (WordPress itself)

## Adversaries

- **M1 — Malicious customer**: authenticated, low privilege, motivated (free services, other customers' data).
- **M2 — Anonymous web attacker**: unauthenticated requests, CSRF, replay.
- **M3 — Compromised/curious admin on shared hosting**: reads DB dumps, log files.
- **M4 — Network attacker**: intercepts/replays gateway callbacks.

## Scenarios

### S1 (M1→A2): Customer changes the price at checkout
**Path:** POST `/checkout` with tampered price field.
**Control:** No price field exists in the request; `OrderService::create` quotes via `Pricing::quote` server-side and persists the snapshot. *Residual: none.*

### S2 (M1/M4→A2): Replayed or forged payment callback
**Path:** Re-POST a captured callback; forge proof for a different amount/ref.
**Controls:** (1) HMAC proof bound to `(ref|amount|type)`, `hash_equals` compare — forgeries fail closed (`SandboxProvider::verify`, tested in `PaymentVerificationTest`). (2) Atomic claim `UPDATE … WHERE status IN payable AND amount = %d` — replays hit 0 rows and get the idempotent answer (`OrderService::claim_paid`). (3) Ledger `UNIQUE(ref_type, ref_id, type)` + `INSERT IGNORE` — even a raced insert cannot double-credit (`Ledger::append`). (4) Per-IP rate limit. *Residual: none for double-spend; noisy callbacks are throttled.*

### S3 (M1→A3): Customer reads another customer's service/order/ledger
**Path:** `/me/services?…id=OTHER`, guessing IDs.
**Control:** IDs from the request are never used for row selection in `/me/*`; queries filter by `customer_id = get_current_user_id()` (`Rest\Routes::me_list`, `Ledger::entries`, `Services::list`). `Services::get_owned` for single-row paths. *Residual: none.*

### S4 (M1→A3): Duplicate provisioning via refresh/retry storm
**Path:** Refresh payment page, replay callback, concurrent job runners.
**Control:** Three-layer idempotency (service-row lookup, atomic state claim, `UNIQUE(order_id)`) — `Provisioning\Provisioner`; demo-provider determinism makes this demonstrable live. *Residual: none.*

### S5 (M1/M3→A1): Steal Arvan API tokens
**Paths:** REST responses, admin UI, logs, DB dump.
**Controls:** Tokens sodium-encrypted at rest keyed from WP salts; UI masks to last-4; no REST route returns `token_enc`; `Audit::redact` strips secret-shaped keys from every log row.

**Real precondition, stated precisely:** whether "DB dump alone is useless" depends entirely on where the encryption key material lives, and the plugin does not control or verify that. `Crypto::key()` derives from `wp_salt('auth')`/`wp_salt('secure_auth')`. If — and only if — `wp-config.php` defines real, non-placeholder `AUTH_KEY`/`AUTH_SALT`/`SECURE_AUTH_KEY`/`SECURE_AUTH_SALT` constants, `wp_salt()` reads those file-defined constants and a DB dump alone (M3 without file access) is genuinely insufficient. If those constants are absent or left at the placeholder value — which happens on some managed/auto-provisioned hosts and on hand-rolled installs that skip that step — WordPress core generates the salts itself and persists them in `wp_options`, in the **same database** as the encrypted `token_enc` column. In that case a DB dump alone decrypts every stored token; there is no second factor. The plugin does not currently detect which case an install is in (see `SECURITY.md` § Known security limitations). *Residual, when salts are file-defined: an attacker needs BOTH the DB and `wp-config.php` — equivalent to full site compromise, out of scope. Residual, when they are not: a DB dump alone is sufficient — treat this as the real M3 exposure until the plugin adds a detection/warning for it.*

### S6 (M2→A6): CSRF against admin actions
**Path:** Lure admin to a page auto-posting to `admin-post.php` (credential delete, pricing change, refund).
**Control:** `check_admin_referer` with per-action nonces on every handler (`Admin\Actions::guard`); capability check precedes nonce check. *Residual: none beyond WP's nonce lifetime model.*

### S7 (M2→A5): Brute-force the Plugin Access Token
**Path:** Scripted wizard submissions.
**Controls:** 128-bit random tokens (2^128 space); bcrypt cost-12 verification (~250 ms); rate limit 10/10min per admin session; failures audited. *Residual: negligible.*

### S8 (M1→A2): Abuse wallet top-up flow
**Path:** Tamper amount between start and callback; replay top-up callback.
**Controls:** Expected amount persisted server-side at start (`arvrs_topup_{ref}` option); proof bound to that amount; ledger unique key makes the credit once-only (`PaymentService::handle_topup_callback` returns replay=true on duplicate). *Residual: none.*

### S9 (M1→A4): Learn reseller base costs / margins
**Path:** Catalog REST, order payloads.
**Control:** `priced_plan()` strips `base_cost`/meta before responding; `/me/orders` strips the `pricing` snapshot. Margins visible only under `manage_options`. *Residual: none.*

### S10 (M1→A6): Stored XSS via customer-controlled fields
**Path:** display name, server name, domain, bucket, notes rendered in admin.
**Controls:** Input narrowed at write (regex whitelists for domain/bucket/flavor; `sanitize_text_field` elsewhere) and escaped at render in every template. *Residual: none identified; templates reviewed file-by-file.*

### S11 (M1→A3/A6): SSRF via CDN domain field
**Path:** Enter `169.254.169.254` or internal host as "domain".
**Control:** The domain is data in an Arvan API body — the plugin never fetches it. Outbound HTTP goes only to fixed Arvan hosts. *Residual: none.*

### S12 (M2→A2): Enumerate payment refs
**Path:** Guess `payment_ref` to view/pay foreign orders.
**Controls:** 48-bit random refs; the payment page additionally requires the session owner to match (`Shortcodes::payment`); callback with a guessed ref still needs a valid HMAC proof. *Residual: none.*

### S13 (M3→A2): Admin mistakenly destroys financial history
**Path:** Uninstall plugin.
**Control:** `uninstall.php` honours the retention opt-in (default ON) — tables survive unless the admin explicitly disabled retention (HC-11). Ledger is append-only; no UI deletes ledger rows. *Residual: a DB-level admin can always drop tables — out of scope.*

## Non-goals / accepted risks

- DRM-grade license enforcement (see ADR-0009).
- Upstream ArvanCloud account compromise (mitigate by scoping the machine-user key upstream).
- Multi-server global rate limiting without a shared object cache.
- WordPress core/plugin ecosystem vulnerabilities outside this plugin.
