# Runbook

Incident playbooks for an operator, not a developer. Each entry: symptom → how to confirm → the exact screen or command → how to verify it worked. All admin URLs are relative to `wp-admin/`; the plugin's menu slug is `arvan-reseller`.

Where a fix needs a database read with no admin screen for it, the command is `wp eval-file` against a one-line PHP snippet — this plugin does not register its own WP-CLI commands (`wp arvrs …` does not exist).

## Order stuck in `provisioning`

**Symptom:** A customer paid and the order never reaches `active`; the customer dashboard shows the order pending with no service.

**Confirm:** Admin → Arvan Reseller → Orders (`admin.php?page=arvan-reseller-orders`), filter/find the order, open it (`admin.php?page=arvan-reseller-orders&order={id}`). Status reads `provisioning`. This happens when a PHP fatal, an OOM, or `max_execution_time` kills the process mid-provision — the row is claimed (`status='provisioning'`) but nothing ever moves it further, and by design nothing retries a row already in that state (retrying it live would be how one order provisions two remote resources).

**Fix:** On the order-detail page, click **بازیابی سفارش گیرافتاده** (the "reclaim" button — `admin-post.php` action `arvrs_order_action`, `do=reclaim`). This runs `Provisioning\Provisioner::reclaim_stale()` scoped to that one order: if the remote resource actually exists, the order completes to `active`; if it does not, the order moves to `provision_failed` so the ordinary retry/refund actions become available. It is a manual trigger of the same reaper the `arvrs_minutely` cron job runs automatically — use the button when you don't want to wait for the next tick.

**Verify:** Order status is `active` (service visible under Services) or `provision_failed` (retry/refund buttons now present). Check `گزارش امنیتی` (audit log) for an `order.reclaimed` row with `moved: true`.

## Jobs stranded in `running`

**Symptom:** System Health shows a non-zero "stale running jobs" count; provisioning/usage-sync/renewal jobs are not completing.

**Confirm:** Admin → Arvan Reseller → System Health (`admin.php?page=arvan-reseller-health`). The stale-jobs count comes from `Jobs\JobRunner::stats()['stale_running']` — jobs claimed (`status='running'`) whose claim is older than the reaper's timeout with no update since. This is the failure mode of a worker process that died mid-job (PHP-FPM restart, OOM kill, host reboot) — it never gets to mark the row `done` or `dead` itself.

**Fix:** Click **آزادسازی وظایف رهاشده** on the Health page (`arvrs_job_action`, `do=reap`) to run `JobRunner::reap_stale()` immediately. It requeues rows still under their retry limit (`status='pending'`, ready to run on the next tick) and marks the rest `dead`. This is the same sweep `arvrs_minutely` runs automatically every minute — use the button to force it now rather than wait.

**For one specific job:** open its detail page (`admin.php?page=arvan-reseller-health&job={id}`, reached from the failed-jobs list on the Health page) for **بازتلاش** (retry — re-queues it) or **توقف** (kill — marks it `dead` and stops retries) on that single row.

**Verify:** Health page's stale-running count drops to 0; the job's row (visible on its detail page) shows `pending` (will run on the next `arvrs_minutely` tick, within a minute) or `dead` if it exhausted its attempts. A `dead` job needs a human decision — re-enqueue manually (below) or accept the loss.

**Re-enqueue a dead job manually** (no UI for this — same payload, fresh row):
```
wp eval-file - <<'PHP'
<?php
$job = ArvanReseller\Jobs\JobRunner::detail(JOB_ID_HERE);
ArvanReseller\Jobs\JobRunner::enqueue($job['type'], json_decode($job['payload'], true));
PHP
```

## Credential revoked upstream

**Symptom:** Provisioning or usage-sync starts failing with an auth error; System Health's credential row still says "متصل" (connected) if nobody has run a manual test recently.

**Confirm:** Admin → Arvan Reseller → System Health, or → اتصال ArvanCloud (Credentials). The daily `credential_health` job (`Jobs\Handlers::credential_health`, runs off `arvrs_daily`) tests every enabled credential and records the result — a revoked machine-user key surfaces there within 24 h even with no human action, and raises a cooldown-limited `credential_failed` admin notification the moment it's detected.

**Fix:** Don't wait for the daily job — Admin → Arvan Reseller → اتصال ArvanCloud, click **آزمایش اتصال** on the affected credential to test it immediately (same code path as the daily job, `Actions::test_credential` → `Handlers::credential_health` logic). If it fails, generate a fresh machine-user key in the ArvanCloud panel (Settings → Workspace → Machine User — there is no API to do this, it is a manual panel step) and re-enter it: **ویرایش** the credential row and paste the new token. Re-test.

**If the credential is the only one enabled**, the plugin falls back into Demo Mode automatically the moment its verified-credential check fails (`Credentials::has_verified_credential()` goes false) — real checkout and top-up are blocked with an honest gateway-status message rather than silently failing, until a working credential is saved.

**Verify:** Credentials screen shows "متصل" with a recent `last_ok_at` timestamp. A previously stuck provisioning job for this credential can now be retried from its job-detail page.

## Ledger discrepancy after a swallowed write

**Symptom:** An order or top-up shows `paid`/`settled` but `Ledger::entries()` for that customer is missing one or both of the expected rows (payment credit, purchase debit) — visible as the customer's wallet balance not reflecting a payment they made.

**Confirm:** `Ledger::append()` throws `RuntimeException` on a genuinely dropped write (a DB error, or MySQL silently downgrading a data error to a warning under `INSERT IGNORE`) rather than mis-reporting it as a harmless replay — so this shows up as a caught exception in the error log / `گزارش امنیتی` (`wallet.adjust_failed` or an unhandled throw around the claim path), or as a customer support report that a payment "didn't show up." Cross-check: `SELECT COUNT(*) FROM wp_arvrs_ledger WHERE ref_type='order' AND ref_id='{payment_ref}'` should be 2 (payment + purchase) for a normal settled order.

**Fix:** `PaymentService` already enqueues a `repair_ledger` job (`Jobs\Handlers::repair_ledger` → `Ledger::repair_order_entries()`) the moment a swallowed write is detected on the claim path, so most cases self-heal within a minute via `arvrs_minutely`. To force it immediately, or to repair a case that didn't auto-enqueue:
```
wp eval-file - <<'PHP'
<?php
ArvanReseller\Jobs\JobRunner::enqueue('repair_ledger', [
    'customer_id' => CUSTOMER_ID,
    'payment_ref' => 'PAYMENT_REF',
    'amount'      => AMOUNT_IRT,
    'order_id'    => ORDER_ID,
]);
PHP
```
Then run pending jobs from System Health (**اجرای وظایف در صف**) or wait for the next tick. `repair_order_entries()` is idempotent — it only writes whichever of the two rows is actually missing, keyed by the same `UNIQUE(ref_type, ref_id, type)` as every other ledger write, so running it twice is safe.

**Verify:** The `SELECT COUNT(*)` query above returns 2; the customer's wallet balance page reflects the payment; `گزارش امنیتی` shows a `ledger.repaired` row with the entry count written.

## Renewals failing

**Symptom:** System Health / Reports show services past their `renews_at` date still active with no renewal ledger charge; customers are not being billed for a new term.

**Confirm:** `Billing\Renewals::due()` lists services due now. A renewal fails per-service for one of: `no_price` (service has `renewal_price = 0` — usually a service that predates term pricing and was never backfilled), insufficient wallet balance (the charge itself is a ledger debit; a customer with no funds simply can't be charged — this is expected, not a bug, and is what feeds the credit-policy ladder), or the service was already cancelled (`Renewals::cancel()`). Check the daily `renew_services` job's result in the audit log for the specific `kind` returned per service.

**Fix — insufficient balance:** working as designed; the credit-policy ladder (warning → critical → grace → restricted) should already be notifying the customer. Confirm Policies settings have sane thresholds (Admin → Arvan Reseller → سیاست اعتبار).

**Fix — `no_price` on a legacy service:** this should not happen after the v4→v5 migration (every service gets a renewal clock backfilled at migration time — see System Health's schema-version check below), but if a service was created through an unusual path, set its renewal price manually:
```
wp eval-file - <<'PHP'
<?php
global $wpdb;
$wpdb->update($wpdb->prefix . 'arvrs_services',
    ['renewal_price' => AMOUNT_IRT, 'term_days' => 30],
    ['id' => SERVICE_ID]);
PHP
```

**Fix — force one renewal charge now** (bypasses waiting for the daily job):
```
wp eval-file - <<'PHP'
<?php
$r = ArvanReseller\Billing\Renewals::charge(SERVICE_ID);
echo json_encode($r), "\n";
PHP
```
`kind` in the result is one of `charged`, `replay` (already charged this term — safe to re-run), `not_due`, `cancelled`, `no_price`, `error`.

**Verify:** `Reports\Reports::period()` for the relevant window (Admin → Arvan Reseller → گزارش مالی) shows the recurring-revenue line move; the service's `renews_at` advanced by one term; exactly one new `renewal` ledger entry exists for that service/term (`UNIQUE(ref_type, ref_id, type)` on `('renewal', "{service_id}:{period_start}")` makes a second run of the same term a no-op, not a double charge).

## Database reset for a repeat E2E run

**Symptom:** `wp eval-file tests/integration/e2e.php` fails on a second run with duplicate-registration errors (`alice@example.com` / `bob@example.com` already exist) or unique-key violations. The script requires a fresh install — it registers fixed demo accounts and is not written to be re-run against its own prior output.

**Confirm:** The failure surfaces immediately, at the `Customers::register` calls near the top of the script.

**Fix — full reset (recommended, matches how the shipped evidence was produced):**
```bash
php wp-cli.phar db reset --yes --path=wp
php wp-cli.phar core install --path=wp --url=http://localhost:8899 \
  --title="Demo Reseller" --admin_user=admin --admin_password=admin123 \
  --admin_email=admin@example.com --skip-email
php wp-cli.phar plugin activate arvan-reseller --path=wp
```
This drops and recreates every WordPress table (including the plugin's, since `db reset` operates at the database level, not per-plugin) and re-runs plugin activation, which re-creates the schema at the current `ARVRS_SCHEMA_VERSION` — so this also happens to be the fastest way to test a fresh-install migration path.

**Fix — plugin-only reset**, when you want to keep the WordPress install (other plugins, other content) and only clear this plugin's data:
```bash
php wp-cli.phar plugin deactivate arvan-reseller --path=wp
php wp-cli.phar eval 'global $wpdb; foreach (["credentials","orders","order_events","services","ledger","usage_records","topups","jobs","audit_log","notifications","customer_rules","base_costs"] as $t) { $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}arvrs_{$t}"); } delete_option("arvrs_schema_version");' --path=wp
php wp-cli.phar user delete $(php wp-cli.phar user list --field=ID --path=wp | tail -n +1) --yes --path=wp --network 2>/dev/null || true
php wp-cli.phar plugin activate arvan-reseller --path=wp
```
The user-delete step is only needed if a prior run's `alice`/`bob` accounts still exist — check with `wp user list` first; deleting all non-admin users is a blunt instrument, prefer `wp user delete alice bob --path=wp` when only those two are stale.

**Verify:** Admin → Arvan Reseller → System Health shows schema version = target version with a green schema-verification row; re-running the E2E script reports `ALL E2E CHECKS PASSED` with no duplicate-key errors.
