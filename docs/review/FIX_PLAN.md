# Remediation contract — round 2

Binding contract for the agents remediating the 141 findings in
`docs/review/ISSUE_BACKLOG.md`. **Every signature below is final.** Write
callers against these signatures even if the file that defines them has not
been edited yet — the owning agent is producing exactly this.

Runtime target stays **PHP 7.4** (no arrow-function-only syntax, no union
types, no `match`, no constructor promotion, no named args, no nullsafe `?->`,
no `str_contains`). WordPress 6.0+. Zero runtime Composer dependencies.

Every string shown to a human goes through `__()`/`esc_html__()` with text
domain `arvan-reseller`. Every `$wpdb` call is `prepare()`d. Every
state-changing admin path keeps its capability + nonce check.

---

## File ownership (do not edit files you do not own)

| Agent | Owns |
|---|---|
| **MONEY** | `src/Wallet/`, `src/Install/Schema.php`, `src/Policies/`, `src/Pricing/`, `src/Reports/` (new) |
| **BILLING** | `src/Billing/` (new), `src/Usage/`, `src/Services/`, `src/Notifications/` |
| **INTEGRATION** | `src/Arvan/` (all) |
| **PROVISIONING** | `src/Jobs/`, `src/Provisioning/`, `src/Plugin.php`, `arvan-reseller.php`, `src/Install/Activator.php` |
| **ORDERS** | `src/Orders/`, `src/Payments/`, `src/Rest/`, `src/Support/`, `src/Audit/`, `src/Customers/`, `src/Licensing/` |
| **ADMIN** | `src/Admin/`, `src/Onboarding/`, `templates/admin/` |
| **FRONT** | `src/Front/`, `src/Identity/`, `src/Install/PageFactory.php`, `templates/front/`, `assets/js/` |
| **DESIGN** | `assets/css/` |
| **I18N** | `languages/` (new), `bin/` (new) |
| **TESTS** | `tests/` |
| **DOCS** | `*.md`, `docs/`, `readme.txt` |

---

## C1 — `ArvanReseller\Wallet\Ledger` (MONEY)

```php
// SQL aggregate, ONE row out. $include_demo null => Plugin::demo_mode().
// Object-cached in group 'arvrs_wallet', invalidated by append().
public static function balance(int $customer_id, ?bool $include_demo = null): array;
// => ['available'=>int,'reserved'=>int,'consumed'=>int,'topup_total'=>int]

// Batch form: ONE GROUP BY query for many customers. Missing ids => zero row.
// @return array<int,array{available:int,reserved:int,consumed:int,topup_total:int}>
public static function balances(array $customer_ids, ?bool $include_demo = null): array;

// True start of the current non-positive period, in whole days. Walks the
// ledger backwards in pages of 200 (bounded: stop after 20 pages and return
// the days since the oldest row examined). null when available > 0.
public static function negative_since_days(int $customer_id): ?int;

// Idempotent repair of the two order entries after a swallowed write.
// @return int number of entries newly written (0 = already whole)
public static function repair_order_entries(int $customer_id, string $payment_ref, int $amount, int $order_id): int;

public static function flush_cache(int $customer_id = 0): void;
```

`derive()`, `append()`, `entries()`, `reconciliation*()`, `total_credit()`
keep their signatures. `append()` calls `flush_cache($customer_id)` on a real
insert. `entries()` gains `?bool $include_demo = null` with the same default
rule. `reconciliation()` must not be the only demo-aware path any more.

Rationale: SC-critical (`balance()` fetched every row on every render),
DA-high (`is_demo` ignored by the wallet), DA/SC-high (admin N+1),
DA-high (`negative_since_days` measured the last debit so `RESTRICTED` was
unreachable), REL-high (swallowed ledger write had no repair).

## C2 — `ArvanReseller\Install\Schema` (MONEY)

`ARVRS_SCHEMA_VERSION` → **5** (constant lives in `arvan-reseller.php`, owned
by PROVISIONING — MONEY assumes 5 and DOCS records it).

Add:

* `services`: `renews_at datetime NULL`, `term_days int(11) NOT NULL DEFAULT 30`,
  `renewal_price bigint(20) NOT NULL DEFAULT 0`, `renewal_count int(11) NOT NULL DEFAULT 0`,
  `last_synced_at datetime NULL`, `cancelled_at datetime NULL`,
  `KEY renew_due (status,renews_at)`.
* `usage_records`: `price bigint(20) NOT NULL DEFAULT 0` (customer-billed),
  `is_demo tinyint(1) NOT NULL DEFAULT 0`, `source varchar(24) NOT NULL DEFAULT 'provider'`,
  `KEY customer_period (customer_id,period_start)`.
* `ledger`: `KEY customer_id_id (customer_id,id)`.
* `audit_log`: `KEY level_created (level,created_at)`, `KEY object (object_type,object_id)`, `KEY user_id (user_id)`.
* `jobs`: `KEY claimed (status,claimed_at)`.
* `orders`: `KEY customer_status (customer_id,status,id)`.
* New table `{p}topups`: `id`, `ref varchar(64) NOT NULL`, `customer_id bigint unsigned NOT NULL`,
  `amount bigint(20) NOT NULL`, `status varchar(16) NOT NULL DEFAULT 'pending'`,
  `created_at datetime NOT NULL`, `expires_at datetime NOT NULL`,
  `PRIMARY KEY (id)`, `UNIQUE KEY ref (ref)`, `KEY expires (status,expires_at)`.

Migration `< 5`:
* `UPDATE services SET renews_at = DATE_ADD(created_at, INTERVAL 30 DAY), term_days = 30 WHERE renews_at IS NULL` (SQLite-safe: compute in PHP with a bounded loop if `DATE_ADD` is unavailable — use `$wpdb->query` with a PHP-computed value per row, batched 500 at a time).
* `UPDATE services s SET renewal_price = (SELECT amount FROM orders o WHERE o.id = s.order_id)` — do it in PHP per batch for portability.
* `UPDATE usage_records SET price = cost WHERE price = 0`.
* Migrate `wp_options` rows named `arvrs_topup_%` into `{p}topups`, then delete them.

Also add:

```php
/** @return array{ok:bool,missing:string[],tables:string[]} */
public static function verify(): array;   // asserts every table exists and every
                                          // UNIQUE KEY this design depends on is
                                          // present; surfaced on the health page
```

The unique keys that MUST be verified: `orders.payment_ref`,
`services.order_id`, `ledger.uniq_ref`, `usage_records.uniq_period`,
`base_costs.product_plan`, `topups.ref`. Use `SHOW INDEX FROM` and degrade
gracefully (`ok=true, missing=[]`, note "introspection unavailable") when the
DB layer does not support it.

The v3→v4 back-stamp stays, but must be bounded and logged
(`Audit::log(0,'schema.backfill', …)` with the affected row count).

Rationale: REL-medium (unique indexes never verified), DA-medium (index set
does not match query set), SEC-medium (`topup` options row per call),
REL-low (top-up intents never expire), plus the storage the renewal engine
needs.

## C3 — `ArvanReseller\Billing\Renewals` (BILLING, new file)

This is the revenue engine the panel found missing (EX-004 critical) and the
mechanism that makes the wallet/policy subsystem live in real mode (EX-005
critical). It replaces "upstream metered usage" — which has no public API —
with **term-based recurring charges**, which the plugin can actually source.

```php
final class Renewals
{
    /** Services due for renewal (status active|at_risk, renews_at <= now). */
    public static function due(int $limit = 50): array;

    /**
     * Charge ONE service's next term. Idempotent on
     * (ref_type='renewal', ref_id="{service_id}:{period_start}") in the
     * ledger AND on usage_records' UNIQUE(service,period_start,period_end).
     * @return array{ok:bool,kind:string,charged:int,stage:string}
     *   kind: 'charged'|'replay'|'not_due'|'cancelled'|'no_price'|'error'
     */
    public static function charge(int $service_id): array;

    /** Run the whole due batch. @return array{due:int,charged:int,replayed:int,errors:int} */
    public static function run_due(int $limit = 50): array;

    /** Notify customers whose renewal lands within `renewal_reminder_days`. */
    public static function remind(): array;

    /** Sum of active services' renewal_price normalised to 30 days. */
    public static function mrr(bool $include_demo = false): int;

    /** Stop future charges without touching the remote resource. */
    public static function cancel(int $service_id, string $actor = 'admin'): bool;
}
```

`charge()` must, in this order:

1. Load the service; bail `not_due` when `renews_at > now` or status is
   `cancelled`/`suspended`… — **suspended still renews** (the hold is a
   collection action, not a termination); `cancelled` does not.
2. Compute `price` = `renewal_price` (>0) else the order's `amount`; bail
   `no_price` when still 0 and alert the admin once.
3. Compute `cost` = `BaseCosts::get($product, $plan_id)` — the upstream figure,
   so margin is recorded, not assumed.
4. `INSERT IGNORE` a `usage_records` row: `source='renewal'`, `period_start` =
   old `renews_at`, `period_end` = `renews_at + term_days`, `quantity` = 1,
   `unit` = `'term'`, `cost` = upstream cost, `price` = customer price,
   `is_demo` from `Plugin::demo_mode()`.
5. `Ledger::append($customer_id, 'service_charge', $price, 'renewal', "{$service_id}:{$period_start}", …)`.
   A `0` return is a replay → report `kind='replay'`, still advance the clock
   if `renews_at` has not already moved.
6. Advance `renews_at`, `renewal_count++` **atomically**:
   `UPDATE services SET renews_at = %s, renewal_count = renewal_count + 1 WHERE id = %d AND renews_at = %s`
   — the `WHERE renews_at = old` guard is what makes two concurrent runners safe.
7. `UsageSync::apply_policy($customer_id)` and notify the customer.

Registered job types: `renew_services` (daily), `renewal_reminders` (daily).

## C4 — `ArvanReseller\Usage\UsageSync` (BILLING)

* `ingest()` gains the `price`/`is_demo`/`source` columns and applies the
  **usage markup**: customer price = `PricingEngine::apply_markup($cost, $percent)`
  where `$percent` = `Options::get('usage_markup_percent', global_markup)`.
  The ledger debit uses the **price**, the usage row records both. Fixes
  EX-031 (zero-margin metered path).
* `sync_all()` becomes chunked: `Services::active_for_sync(int $limit, int $after_id)`
  cursor-paged at 200; a run processes at most `sync_batch` (default 500)
  services and enqueues a follow-up job when more remain. Fixes SC-high.
* `apply_policy()` split into `stage_for(int $customer_id): string` (pure-ish
  computation) and `apply_actions(int $customer_id, string $stage)` so it is
  testable and no longer an 80-line multi-responsibility function; keep
  `apply_policy()` as the thin composition of the two.
* Per-service watermark: use `services.last_synced_at` instead of a fixed 48h
  window, defaulting to 48h back on first sync. Suspended services keep
  syncing (they are still running upstream). Fixes REL-low.
* Record result counts: `update_option('arvrs_last_usage_sync', ['at'=>…,'stats'=>…])`
  so an empty run is visible. Fixes OPS-low.

## C5 — `ArvanReseller\Services\Services` (BILLING)

* `create_for_order()` returns `0` on genuine failure and the caller must
  react — never silently succeed.
* Add `active_for_sync(int $limit = 200, int $after_id = 0)`, `set_renewal(int $service_id, string $renews_at, int $term_days, int $price)`,
  `due_for_renewal(int $limit)`, `mark_synced(int $service_id)`,
  `update_connection(int $service_id, array $connection, string $status)`,
  `terminate(int $service_id)` (local state only; the remote delete is the
  admin action's job), `by_remote(string $product, string $remote_id)`.
* `get_owned()` must be the ONLY read path used by customer-facing code
  (SEC-medium: it had no production callers).

## C6 — `ArvanReseller\Arvan\ArvanClient` (INTEGRATION)

```php
/**
 * @param array $opts {
 *   @type string $idempotency_key  sent as `Idempotency-Key` when non-empty
 *   @type bool   $retry_unsafe     allow retrying POST/PATCH (default false)
 * }
 * @throws ProviderError
 */
public function request(string $method, string $path, ?array $body = null, array $opts = []): array;
```

**Retry policy (the critical fix, found by two independent reviewers).**

* `GET`/`HEAD`/`PUT`/`DELETE` — idempotent verbs: retry on timeout/5xx/429 as today.
* `POST`/`PATCH` — **never blind-retry**. On a timeout or 5xx the outcome is
  unknown: throw `ProviderError('timeout_indeterminate', …)`. Only when the
  caller passes `retry_unsafe = true` (meaning: the caller can reconcile by
  lookup) may it retry, and then only with an `Idempotency-Key` header set.
* 402 → `ProviderError('billing', …)`, **not** retryable (docs claimed this
  was handled; it was not).
* 409 → `ProviderError('conflict', …)` — the caller treats it as
  "already exists" and reconciles.
* Real connect timeout via a one-shot `http_api_curl` filter setting
  `CURLOPT_CONNECTTIMEOUT` (the `connect_timeout` arg WordPress ignores must
  go, or actually work — make it work).
* Redacted request/response logging when `WP_DEBUG`: method, path, status,
  correlation id, duration, and a body with every key matching
  `/(token|key|secret|password|authorization)/i` replaced by `[redacted]`.
  Log **successful** calls too (`Audit::log(0,'arvan.request', …)` at debug
  level) so a correlation id can be traced end to end.

Add `ProviderError::kind` values `timeout_indeterminate`, `billing`,
`conflict` and give each a Persian customer message in `DTO.php`, all via
`__()` (ACC-medium: those strings bypassed i18n).

## C7 — `ArvanReseller\Arvan\RealProvider` (INTEGRATION)

* **No `sleep()` anywhere.** `create_server()` returns immediately with
  `status = 'creating'` and whatever address is already present. Completing
  the picture is the new `poll_service` job's job (PROVISIONING owns it; it
  calls `status()` and `Services::update_connection()`).
* **Deterministic remote naming makes create reconcilable.** Derive the server
  name from the idempotency key: `arvrs-` + `preg_replace('/[^a-z0-9]+/','-', strtolower($idempotency_key))`,
  capped at 32 chars — `order:41` → `arvrs-order-41`. Before creating, list
  servers in the region and return the existing one when the name matches.
  After a `timeout_indeterminate` or `conflict`, re-list and return the match;
  only if there is no match rethrow as retryable. Same idea for CDN (the
  domain is the natural key — a `conflict` means "already created", so fetch
  and return it) and Object Storage (the bucket name is the natural key).
  This is the actual remedy for the duplicate-paid-resource critical.
* `create()` passes `$idempotency_key` through to every call it makes.
* **Validate customer input against the live catalog** before it reaches
  Arvan: `region` must be in `options()['regions']`, `image` in
  `options()['images']`, `flavor_id` in `plans()`. Reject with
  `ProviderError('invalid', …)` otherwise. Region-scoped flavor ids mean an
  unchecked pair is a guaranteed 422 after the customer has paid.
* `plans('cloud_server')` returns every flavor even when `BaseCosts::get()` is
  0, with `'unpriced' => true` in the plan meta so the admin can see and price
  them. Add:
  ```php
  /** Import every upstream plan id into base_costs at cost 0 for pricing. @return int rows */
  public function importable_plans(string $product): array; // [['plan_id'=>…,'name'=>…,'meta'=>…], …]
  ```
  ADMIN wires a "درون‌ریزی پلن‌های آروان" button to it. Fixes the
  "real-mode cloud servers are unsellable" high (found twice).
* `usage()` keeps returning `[]` but its docblock must say plainly that
  recurring revenue comes from `Billing\Renewals`, not from an upstream
  metering API — and `Catalog`/health must stop implying otherwise.
* `Credentials::select_for()` — remove the dead branch: the order is
  (1) explicit product match, (2) unrestricted credential, (3) nothing.
  A product-restricted credential must **never** be handed to another
  product (PC-medium).
* `Catalog`: negative caching (cache the empty result for 60 s) and a
  stampede guard (`wp_cache_add` lock, 30 s) so a cold cache cannot
  fan out into concurrent upstream calls during page render (SC-high).

## C8 — `ArvanReseller\Provisioning\Provisioner` (PROVISIONING)

```php
/** @return array{ok:bool,kind:string,message:string,service_id:int}
 *  kind ∈ provisioned|already|not_claimable|not_found|failed|retryable */
public static function provision(int $order_id): array;

/** Orders stuck in `provisioning` past $minutes go back to provision_failed
 *  so the retry path can claim them. @return int rows moved */
public static function reclaim_stale(int $minutes = 20): int;
```

* JobRunner switches on `kind` — **never** `strpos()` on a human message
  (CQ-high; the message is Persian in half the paths).
* `Services::create_for_order()` returning 0 → do **not** transition to
  ACTIVE; throw retryable and alert. (REL-medium: the result was ignored.)
* On terminal failure notify the **customer** as well as the admin — the
  panel's UX critical is that the customer is told nothing, ever.

## C9 — `ArvanReseller\Jobs\JobRunner` (PROVISIONING)

* `reap_stale(int $minutes = 15): int` — `running` jobs whose `claimed_at` is
  older than `$minutes` go back to `pending` (or `dead` past `max_attempts`),
  with `last_error` explaining the reclaim. Called first inside `run_due()`.
* Dispatch through a registry, not a hardcoded `switch`:
  ```php
  public static function handlers(): array;             // type => callable
  // filterable via apply_filters('arvrs_job_handlers', $map)
  ```
  Built-ins: `provision_order`, `usage_sync`, `renew_services`,
  `renewal_reminders`, `poll_service`, `credential_health`, `prune`,
  `repair_ledger`. This also removes the Infrastructure→Application layer
  inversion (ARCH-medium) and gives the plugin real extension points
  (ARCH-low).
* `failed()` also returns stale `running` jobs; `retry()` accepts `dead` **and**
  stale `running`; add `kill(int $job_id)` and `detail(int $job_id)` (full
  payload + full error) for the admin UI (OPS-medium/high).
* `stats()` adds `stale_running`.

## C10 — `ArvanReseller\Payments\PaymentService` (ORDERS)

```php
/** @return array{ok:bool,replay:bool,message:string,order:?array,
 *                provision:array{state:string,message:string}} */
public static function handle_order_callback(string $payment_ref, array $payload): array;
// provision.state ∈ 'active' | 'pending' | 'failed'
```

* The payment page must be able to tell the truth. `state='active'` only when
  the order is genuinely `active`; `'pending'` while `paid`/`provisioning`;
  `'failed'` on `provision_failed`. (UX critical.)
* Ledger failure on a settled payment: enqueue `repair_ledger` **and** alert
  the admin, then continue. (REL-high.)
* `OrderService::claim_paid()` becomes:
  ```php
  /** @return array{kind:string,order:?array} kind ∈ claimed|replay|amount_mismatch|not_found */
  public static function claim_paid(string $payment_ref, int $verified_amount, string $transaction_id): array;
  ```
  An amount mismatch is a **failure with an admin alert**, never a cheerful
  replay (REL-medium).
* Top-up intents move from `wp_options` to the `topups` table with a 2-hour
  expiry, and `start_topup()` is rate-limited per customer
  (`Helpers::rate_limit('topup:'.$customer_id, 10, 300)`). (SEC-medium.)
* Refund credits only after confirming the original `payment` entry exists for
  that `payment_ref`; otherwise refuse and alert (SEC-medium).

## C11 — `ArvanReseller\Support\Helpers` (ORDERS)

* `rate_limit()` becomes atomic: use `wp_cache_add()` as the lock, or a single
  `add_option`/`update_option` with a compare-and-set retry loop; the current
  read-modify-write lets a burst through (SEC-low).
* Add `Helpers::jdate(string $utc_datetime, string $format = 'j F Y'): string`
  — Gregorian→Jalali conversion, **pure**, no `intl` dependency, plus Persian
  month names and Persian digits. Every customer-facing date goes through it
  (UX-medium). Add `Helpers::fa_digits(string $s): string`.
* Add `Helpers::connection_label(string $key): string` — snake_case →
  Persian label map for service credentials (UX-medium).

## C12 — `ArvanReseller\Rest\Routes` (ORDERS)

* Payment-callback rate limiting keyed on the **payment reference**, not the
  client IP — a real gateway is one IP (REL-medium). Keep a much higher IP cap
  as a crude flood guard.
* Every route gets an `args` schema with `validate_callback`/`sanitize_callback`
  (SECURITY.md claimed this; it was not true).
* New route `GET /orders/(?P<id>\d+)/state` — owner-scoped, returns
  `{status, provision_state, message}` so the payment page can poll instead of
  lying. Rate-limited.

## C13 — Admin (ADMIN)

Blocking items:

* **Render notices.** `templates/admin/dashboard.php` must render `$notices`
  (found by two reviewers, both high). Add a `arvan-reseller-notifications`
  page with unread counts, filters and mark-read, and a menu bubble.
* **Job recovery UI** on the health page: stale `running` jobs listed, with
  Reclaim / Retry / Kill actions and a detail view showing the full payload
  and full error (not 12 truncated words).
* **Schema health**: render `Schema::verify()`.
* **Credential auto-health**: a daily `credential_health` job runs
  `test_connection()` per enabled credential and records the result, so a
  revoked token stops showing "connected" (OPS-high).
* **Audit investigation**: filters (action, object type, object id, user,
  level, date range), pagination, and CSV export via an
  `admin_post` handler with capability + nonce.
* **Order lookup** by id / payment_ref / customer email on the orders screen.
* **Services screen becomes operable**: resync status from the provider,
  suspend / resume, terminate (calls `ProviderInterface::delete()`, requires an
  explicit typed confirmation), cancel renewal. This is what makes
  `delete()`/`status()` stop being dead code (PC-medium).
* **Stuck order recovery**: an order in `provisioning` gets a "بازیابی" action
  wired to `Provisioner::reclaim_stale()` scoped to that order (REL-high).
* **Reports**: period selector (this month / last month / 90 days / custom)
  over revenue, cost, margin, MRR, active services, churn — from
  `ArvanReseller\Reports\Reports` (MONEY owns that class; see C15).
* **Pricing screen**: "import upstream plans" button using
  `RealProvider::importable_plans()`, and a visible warning listing plans with
  `base_cost = 0` that therefore cannot be sold.
* Dashboard: apply the demo filter to the order counts too (DA-medium); batch
  balances via `Ledger::balances()` (no N+1); cache the six aggregates in a
  5-minute transient, busted on order/ledger writes.
* Stop running raw SQL against `arvrs_credentials` from `Admin\*` — go through
  `Credentials::` (ARCH-medium).
* **Every** admin `<label>` gets `for` and its control gets a matching `id`
  (ACC-high). Detail pages get a back-link. No untranslated internals leak
  into the Persian admin (`templates/admin/orders.php`, `wizard.php`,
  `customer-detail.php`, `order-detail.php`).

## C14 — Front (FRONT)

* **`templates/front/payment.php` must not claim success it cannot see.**
  Render from `provision.state`: `active` → the ready panel; `pending` → an
  honest "در حال راه‌اندازی" panel that polls
  `GET /orders/{id}/state` (bounded: 20 polls, 3 s apart, then a "we will
  notify you" terminal state); `failed` → a real failure panel with the
  reference number and a support route. (UX critical.)
* **`assets/js/front.js`**: any network failure re-enables the buttons and
  surfaces the error — never a permanent spinner (UX-high). `role="tablist"`
  gets arrow-key navigation (ACC-low). Payment success moves focus to the
  result heading instead of destroying it (ACC-medium). Add the mobile nav
  toggle DESIGN's CSS expects.
* Dashboard links to pending orders (UX-high dead end), shows renewal dates
  and next charge, and offers "cancel renewal".
* All customer-facing dates through `Helpers::jdate()`; all connection keys
  through `Helpers::connection_label()`.
* A logged-in non-customer must get an explanatory panel with a link to
  wp-admin, not an auth→dashboard→auth loop (UX-medium).
* Password reset link (`wp_lostpassword_url()`); a failed registration
  re-renders with the submitted values preserved (UX-medium).
* `arvrs_notice` / `arvrs_error` query args become **codes** resolved through a
  fixed map — no attacker-controlled text is ever echoed (SEC-low, and the
  same fix applies to `templates/admin/partials/notices.php`, ADMIN's file).
* `shell-top.php`: emit `lang` from `get_locale()`, `dir` from `is_rtl()`,
  no nested `<main>`, exactly one `<h1>` per page (ACC-high/low).

## C15 — `ArvanReseller\Reports\Reports` (MONEY, new file)

```php
/** @return array{revenue:int,cost:int,margin:int,orders:int,services:int} */
public static function period(string $from_utc, string $to_utc, bool $include_demo = false): array;

/** Monthly buckets for the last N months. @return array<string,array> keyed 'YYYY-MM' */
public static function monthly(int $months = 12, bool $include_demo = false): array;

/** Recurring revenue: active services' renewal_price normalised to 30 days. */
public static function mrr(bool $include_demo = false): int;

/** Services cancelled/terminated in the window ÷ active at window start. */
public static function churn(string $from_utc, string $to_utc): float;
```

All queries indexed, all `prepare()`d, all demo-aware. This closes the
lifetime-sums-only finding (EX-090) and gives the reseller the instrument that
would reveal a month-2 revenue cliff.

## C16 — Design tokens & contrast (DESIGN)

Non-negotiable, all verifiable with a contrast calculator:

* Every status pill (`.arvrs-tag-*` in both stylesheets) reaches **≥ 4.5:1**
  at its real rendered size and weight. Three of five failed; the success pill
  was 2.89:1.
* The brand token actually drives the brand surfaces: header, hero, auth
  aside, primary buttons, focus rings, stat cards, admin accents. No literal
  hex where a token exists. Delete tokens nothing uses, or use them — do not
  ship a decorative scale (VD-high).
* Hero / auth-aside / `.arvrs-stat.is-brand` white-on-teal reaches AA
  (currently ~2:1) — darken the gradient or drop the white (ACC-medium).
* Admin actually loads Vazirmatn (it declares it and never enqueues it).
* One container primitive, one gutter, at every breakpoint (VD-medium).
* Decorative overlays sit **behind** content (`z-index`/stacking) (VD-medium).
* `.arvrs-card` margin-bottom stops fighting flex/grid gaps (VD-medium).
* A real mobile nav below 760px; the fixed-height header must not overflow in
  the 640–760px band (VD-medium ×2).
* Focus ring ≥ 2px at ≥ 3:1 in both stylesheets (ACC-low).
* Radii and weights come from the scale — 32 literal radii and a fictional
  weight hierarchy are both findings.

## C17 — i18n (I18N)

* Create `languages/` with a real `arvan-reseller.pot` generated by
  `bin/make-pot.php` (pure PHP: tokenise every `.php` for `__`, `_e`,
  `esc_html__`, `esc_attr__`, `_n`, `_x` and emit valid gettext with source
  references). Ship `arvan-reseller-fa_IR.po` **and** a compiled `.mo` written
  by `bin/make-mo.php` (the MO binary format is ~60 lines of `pack()`).
* `load_plugin_textdomain()` on `plugins_loaded` (PROVISIONING wires the call;
  I18N provides the catalog and the header check).
* The plugin header's `Domain Path: /languages` must point at a directory that
  exists — that was the finding.

## C18 — Tests (TESTS)

The single highest-value change: **make `$wpdb` paths testable.**

* `tests/support/FakeWpdb.php` — a `$wpdb`-shaped object backed by
  `PDO('sqlite::memory:')`, supporting `prefix`, `prepare` (`%d %s %f %%`),
  `query`, `get_var`, `get_row`, `get_results`, `get_col`, `insert`, `update`,
  `delete`, `replace`, `insert_id`, `rows_affected`, `last_error`,
  `get_charset_collate`, and translating `INSERT IGNORE` → `INSERT OR IGNORE`
  and `SHOW INDEX FROM x` → `PRAGMA index_list`. Provide a `dbDelta()` shim
  that executes the `CREATE TABLE` after rewriting MySQL types to SQLite ones
  and hoisting `UNIQUE KEY` into `CREATE UNIQUE INDEX`.
* With that in place, add integration-grade unit tests for: ledger idempotency
  on the real unique key, `claim_paid` amount mismatch, the sandbox-gateway
  block, the v4→v5 migration, `Renewals::charge()` replay + clock advance,
  `JobRunner::reap_stale()`, `Provisioner` result kinds, `Schema::verify()`,
  balance batching, and `negative_since_days` across a top-up that repairs the
  balance.
* Add a **concurrency** test: two `Renewals::charge()` calls and two
  `claim_paid()` calls interleaved against the same row must produce exactly
  one effect (drive it by calling in sequence against the same connection —
  the unique key and the `WHERE old_value` guards are what is under test).
* Rewrite the tests the panel called decorative: `LedgerDerivationTest`'s
  order-replay check must reach the DB guard; the two `LicenseTest` cases that
  assert nothing about the license code; and the three E2E checks that cannot
  fail.
* Add negative auth/authz tests: unauthenticated REST, wrong-owner REST,
  wrong-owner form POST, missing nonce, missing capability.
* `tests/integration/e2e.php` gets an `ABSPATH` guard and covers the new flows.
  Every test file gets an `ABSPATH`/CLI guard so the harness cannot be reached
  over HTTP inside a plugin directory (SEC-low).

## C19 — Docs (DOCS, last)

Fix every documentation finding against the **final** code, not against this
plan: the E2E check count (state it once, derive it from the code), the three
traceability errors, the five spec drifts, the README stack table and project
tree, the JS bundle size claim, the `DATA_MODEL.md` leaked agent artifact, the
`ARCHITECTURE.md` module table, the two false `SECURITY.md` control claims,
the two false `THREAT_MODEL.md` claims, `API_INTEGRATION.md`'s 402 claim and
its overstated client-behaviour claims, and the "i18n-ready" claim.

Add `docs/RUNBOOK.md`: incident playbooks for stuck orders, stale jobs,
credential revocation, ledger discrepancy, failed renewals, plus the database
reset procedure the E2E script needs.

Add `readme.txt` in WordPress.org format (required for a distributable
plugin): `=== Arvan Reseller ===`, `Requires at least`, `Tested up to`,
`Requires PHP`, `Stable tag`, short description, installation, FAQ,
changelog.

---

## Definition of done, per agent

1. `php -l` clean on every file you touched, on PHP 7.4 syntax rules.
2. No finding you were assigned is left unaddressed **or** unexplained: if you
   decide a finding is wrong or not worth fixing, say so with the file:line
   that disproves it.
3. You did not edit a file you do not own.
4. Report back: the findings you closed, the ones you did not and why, and any
   contract in this document you had to deviate from.
