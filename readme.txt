=== Arvan Reseller ===
Contributors: successtrade
Tags: arvancloud, cloud hosting, reseller, ecommerce, wallet
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn any WordPress site into a white-label ArvanCloud reseller storefront with automatic provisioning and a real wallet ledger.

== Description ==

Arvan Reseller sells ArvanCloud **Cloud Server**, **CDN** and **Object Storage** on your own WordPress site. A customer registers, configures a plan, pays, and the plugin provisions the resource on your ArvanCloud account automatically — no panel round-trips, no manual credential hand-off.

= What it does =

* Persian-first, RTL storefront for the three products, built from server-rendered PHP and one small vanilla-JS file — no build step, no Node.js at runtime.
* Pricing engine: global, per-product and per-customer markup/discount, with an immutable price snapshot on every order.
* A sandbox payment gateway ships in the box so you can exercise the full purchase → provision flow before wiring a real payment service provider. There is no production PSP adapter yet; wiring one is a documented extension point (`docs/extending-payment-provider.md` in the repository), not a built-in.
* Instant post-payment provisioning against ArvanCloud's documented Cloud Server (ECC), CDN 4.0 and Object Storage APIs, with layered idempotency so a retried callback or a crashed worker cannot create two remote resources for one order.
* Recurring billing: each service carries its own renewal clock (`renews_at` / `term_days` / `renewal_price`) and a daily job charges it. ArvanCloud publishes no metering API, so this — not upstream usage — is where recurring revenue comes from. Metered usage sync exists and debits the wallet the moment ArvanCloud publishes a usage endpoint; today it runs against a deterministic demo provider only.
* Append-only wallet ledger (balances are derived, never stored as a mutable number) with a configurable credit-policy ladder (warning → critical → grace → restricted).
* Multi-credential support: several encrypted ArvanCloud API tokens with per-product routing, priority and health tracking.
* Admin dashboard with period revenue/cost/margin, MRR and churn, a job queue view, and a System Health page.

= Demo Mode, honestly =

Demo Mode is the default until you enter a working ArvanCloud API token. In Demo Mode the *only* things that are simulated are the two external boundaries — the ArvanCloud API and the payment gateway. Every internal flow (pricing, order state machine, ledger, provisioning idempotency, usage accounting, credit policy) runs on the same code path it would in real mode, against an in-memory provider that returns realistic-shaped fake resources. Demo-mode ledger and usage rows are stamped `is_demo=1` and are excluded from every real-money report the moment you leave Demo Mode. Nothing in Demo Mode talks to the real ArvanCloud API or moves real money.

= What you need for real mode =

To sell against real ArvanCloud resources you need an ArvanCloud **machine-user API key** (Panel → Settings → Workspace → Machine User — ArvanCloud does not expose an API to create this key, so it must be generated in the panel by hand). Enter it as a credential in the plugin's onboarding wizard or Credentials screen; the plugin runs a live connection test against it before leaving Demo Mode. Real-mode metered usage billing is not possible today because ArvanCloud does not publish a usage/consumption API — the plugin bills fixed-term charges instead (see above), and the single integration point for a future usage API is documented in `docs/API_INTEGRATION.md`.

= Requirements =

* WordPress 6.2 or newer.
* PHP 7.4 or newer, with the `sodium` extension (bundled in PHP ≥ 7.2, standard on virtually every host) — credentials are encrypted at rest with it.
* MySQL 5.7+ / MariaDB 10.3+.
* No Composer or Node.js needed at runtime; nothing this plugin ships depends on either at install time.

== Installation ==

1. Upload the plugin ZIP through wp-admin → Plugins → Add New → Upload Plugin, or copy the plugin directory into `wp-content/plugins/arvan-reseller`.
2. Activate **Arvan Reseller** in wp-admin → Plugins. The onboarding wizard launches automatically on first activation.
3. Enter your Plugin Access Token when prompted (this licenses the plugin's selling capability — it is a separate concept from your ArvanCloud API token).
4. Follow the wizard: brand → ArvanCloud API token (skip this step to stay in Demo Mode) → pricing → automatic storefront page creation → done.
5. If you skipped the ArvanCloud token, the storefront runs fully in Demo Mode. Add a real machine-user token later from wp-admin → Arvan Reseller → Credentials whenever you are ready to sell for real.

No WooCommerce, no page builder and no specific theme is required. The plugin renders its own pages and works with any active theme.

== Frequently Asked Questions ==

= Does this need WooCommerce? =

No. The plugin is fully standalone: its own storefront pages, its own checkout, its own customer dashboard. It does not read from or write to WooCommerce.

= Can I try it without an ArvanCloud account? =

Yes — that is exactly what Demo Mode is for. Every internal flow (pricing, checkout, provisioning, wallet, usage, credit policy) runs for real against a simulated ArvanCloud/payment boundary, so you can walk the entire purchase flow before entering a real API token.

= How do resellers actually take payment? =

The plugin ships a `PaymentProviderInterface` and a working sandbox implementation for testing the flow end to end. Connecting a real Iranian PSP (Zarinpal, IDPay, etc.) requires implementing that interface for your provider — see `docs/extending-payment-provider.md` in the plugin's repository. No production PSP adapter ships in this release.

= What happens if ArvanCloud rejects a purchase because my account is out of balance? =

The plugin recognizes ArvanCloud's `402` response as a permanent-until-topped-up condition, tells the admin plainly instead of retrying it into the ground, and never charges the customer twice for the same attempt — every non-idempotent create is looked up by a deterministic name before ever being retried, so a timed-out create is reconciled, not duplicated.

= Is the plugin translatable? =

Yes. All UI strings go through the `arvan-reseller` text domain. The plugin ships a generated `.pot` template and a Persian `fa_IR` translation under `languages/`. See the Changelog/Description for how to regenerate the catalog or add a locale.

= Where is my ArvanCloud API token stored? =

Encrypted at rest (libsodium secretbox) in your WordPress database, keyed from your site's own WordPress salts. It is never shown in full in the UI (only a last-4 masked form), never returned by any REST endpoint, and never written to a log in the clear. If your `wp-config.php` does not define real, unique `AUTH_KEY`/`AUTH_SALT`/`SECURE_AUTH_KEY`/`SECURE_AUTH_SALT` constants, WordPress falls back to storing those salts in the same database as the encrypted token — see `docs/THREAT_MODEL.md` in the repository for what that means for your threat model, and define those constants in `wp-config.php` for the strongest protection.

== Changelog ==

= 1.1.0 =
* Added a term-based recurring-billing engine (`Billing\Renewals`) — this is where recurring revenue now comes from; ArvanCloud still publishes no metering API.
* Added period revenue/cost/margin, MRR and churn reporting.
* Replaced the hardcoded job-type switch with a filterable job-handler registry.
* `ArvanClient` retry policy is now verb-aware: POST/PATCH are never blindly retried; a request that times out is reconciled by deterministic remote name instead of repeated. Added `Idempotency-Key` and explicit `402` (insufficient upstream balance) handling.
* `RealProvider` now names every remote resource deterministically from the order, so a create can be reconciled after an indeterminate outcome instead of guessed at.
* Removed the blocking `sleep()` from the payment-callback request path; connection detail polling now happens in a background job.
* Wallet ledger balance is now a single indexed SQL aggregate (was an unbounded per-row PHP sum); admin customer lists batch-fetch balances instead of querying per row.
* `negative_since_days` now finds the true start of a negative balance period instead of the age of the most recent debit.
* Added a durable-job reaper and an admin action to reclaim an order stuck in `provisioning`.
* Shipped a real translation catalog (`languages/arvan-reseller.pot`, `arvan-reseller-fa_IR.po`/`.mo`) and the scripts to regenerate them.
* Added a PHP 7.4 compatibility gate (`bin/php74-check.php`, its own CI job) that parses every shipped file against the real 7.4 grammar.
* Schema bumped to version 5 (renewal clock columns on `services`, cost/price split on `usage_records`, top-up intents moved from `wp_options` into their own table).

= 1.0.0 =
* First release: licensing, onboarding wizard, storefront, pricing engine, sandbox payments, provisioning, wallet ledger, usage accounting, credit policy, admin dashboard.

== Upgrade Notice ==

= 1.1.0 =
Schema migrates automatically on activation (version 4 → 5): existing services are backfilled with a renewal clock, existing usage rows are backfilled with a price, and any legacy top-up intent still pending in `wp_options` is moved into its own table. Back up your database before upgrading, as with any release that migrates schema.
