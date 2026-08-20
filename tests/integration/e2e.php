<?php
/**
 * End-to-end integration scenario, run inside a REAL WordPress install:
 *
 *   wp eval-file tests/integration/e2e.php
 *
 * Covers spec.md §13 acceptance criteria: licensing, idempotent pages,
 * registration, server-side pricing, payment verify/claim, duplicate-callback
 * replay safety, wallet top-up idempotency, usage-sync dedup, policy staging,
 * notification cooldown, customer isolation (direct + REST), and the
 * provision-failure → retry → success path.
 *
 * Requires a FRESH install (re-runs need a reset DB) and the demo activation
 * token from DEVELOPMENT.md. Exits non-zero if any check fails.
 */

use ArvanReseller\Install\PageFactory;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Licensing\License;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Payments\SandboxProvider;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

$GLOBALS['fails'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $GLOBALS['fails']++;
    }
}

// The token is deliberately NOT embedded in source (spec: no plaintext
// activation tokens in code). The demo token lives in DEVELOPMENT.md.
$demo_token = (string) getenv('ARVRS_DEMO_TOKEN');
if ($demo_token === '') {
    echo "SET ARVRS_DEMO_TOKEN first, e.g.:\n  ARVRS_DEMO_TOKEN=<demo token from DEVELOPMENT.md> wp eval-file tests/integration/e2e.php\n";
    exit(2);
}

// ---------- 1. Licensing ----------
check('invalid PAT rejected', !License::activate('WRONG-TOKEN'));
check('valid PAT activates', License::activate($demo_token));
check('license persisted without plaintext', License::is_active() && strpos(json_encode(get_option('arvrs_license')), 'ARVRS-') === false);

// ---------- 2. Onboarding equivalents ----------
Options::set_many(['brand_name' => 'ابر آزما', 'demo_mode' => true, 'global_markup' => 20.0, 'onboarded' => true]);
BaseCosts::seed_defaults();
$pages = PageFactory::ensure_pages();
check('pages created', count($pages) === 8);
$pages2 = PageFactory::ensure_pages();
check('page creation idempotent', $pages === $pages2);

// ---------- 3. Customer registration ----------
$alice = Customers::register('alice@example.com', 'password123', 'آلیس');
$bob   = Customers::register('bob@example.com', 'password123', 'باب');
check('customers registered', !is_wp_error($alice) && !is_wp_error($bob));
check('duplicate email rejected', is_wp_error(Customers::register('alice@example.com', 'password123', 'x')));

// ---------- 4. Checkout: server-side pricing ----------
wp_set_current_user($alice);
$order = OrderService::create($alice, 'cloud_server', 'g1-2-2-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-vm']);
check('order created', is_array($order), is_wp_error($order) ? $order->get_error_message() : '');
check('price = base × 1.2', (int) $order['amount'] === (int) round(BaseCosts::get('cloud_server', 'g1-2-2-25') * 1.2), 'amount=' . $order['amount']);
check('pricing snapshot persisted', strpos((string) $order['pricing'], '"markup_source":"global"') !== false);
check('invalid config rejected', is_wp_error(OrderService::create($alice, 'cdn', 'cdn-growth', ['domain' => 'not a domain !!'])));

// ---------- 5. Payment: verify → claim → ledger → provision ----------
$ref   = (string) $order['payment_ref'];
$proof = SandboxProvider::proof($ref, (int) $order['amount'], 'order');
check('tampered amount fails verify', !PaymentService::handle_order_callback($ref, ['sandbox_proof' => SandboxProvider::proof($ref, 999, 'order'), 'type' => 'order'])['ok']);
$result = PaymentService::handle_order_callback($ref, ['sandbox_proof' => $proof, 'type' => 'order']);
check('payment accepted', $result['ok'] && !$result['replay']);
check('order active after inline provisioning', $result['order']['status'] === 'active', 'status=' . $result['order']['status']);

$service = Services::by_order((int) $order['id']);
check('service created with remote id', $service && $service['remote_id'] !== '');
check('service mapped to customer', (int) $service['customer_id'] === $alice);

// ---------- 6. Duplicate callback (HC-7) ----------
$replay = PaymentService::handle_order_callback($ref, ['sandbox_proof' => $proof, 'type' => 'order']);
check('duplicate callback detected as replay', $replay['ok'] && $replay['replay']);
global $wpdb;
$payments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_ledger WHERE ref_id = %s AND type = 'payment'", $ref));
$services_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_services WHERE order_id = %d", (int) $order['id']));
check('exactly one payment ledger row', $payments === 1, "rows=$payments");
check('exactly one service', $services_count === 1);

// ---------- 7. Wallet & ledger ----------
$balance = Ledger::balance($alice);
check('purchase settled net-zero wallet effect', $balance['available'] === 0, 'available=' . $balance['available']);
$topup_ref = 'TOP-E2ETEST01';
add_option('arvrs_topup_' . $topup_ref, ['customer_id' => $alice, 'amount' => 5000000, 'at' => time()], '', false);
$topup = PaymentService::handle_topup_callback($topup_ref, ['sandbox_proof' => SandboxProvider::proof($topup_ref, 5000000, 'topup'), 'type' => 'topup']);
check('topup credited', $topup['ok'] && !$topup['replay']);
$topup2 = PaymentService::handle_topup_callback($topup_ref, ['sandbox_proof' => SandboxProvider::proof($topup_ref, 5000000, 'topup'), 'type' => 'topup']);
check('topup replay safe', $topup2['ok'] && $topup2['replay']);
check('balance after single topup', Ledger::balance($alice)['available'] === 5000000);

// ---------- 8. Usage sync idempotency ----------
$stats1 = UsageSync::sync_all();
$stats2 = UsageSync::sync_all();
check('first sync ingested usage', $stats1['ingested'] > 0, 'ingested=' . $stats1['ingested']);
check('re-sync ingests nothing (dedup)', $stats2['ingested'] === 0, 'ingested=' . $stats2['ingested']);
$after_usage = Ledger::balance($alice);
$usage_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(cost),0) FROM {$wpdb->prefix}arvrs_usage_records WHERE customer_id = %d", $alice));
check('usage debited once', $after_usage['available'] === 5000000 - $usage_total, 'available=' . $after_usage['available']);

// ---------- 9. Policy engine ----------
UsageSync::apply_policy($alice);
$stage = get_user_meta($alice, 'arvrs_policy_stage', true);
check('policy stage computed', in_array($stage, ['healthy', 'warning', 'critical', 'grace', 'restricted'], true), "stage=$stage");
// Force a low balance with an adjustment debit, then re-stage.
Ledger::append($alice, 'adjustment', 4500000, 'admin', 'e2e-drain', 'drain for policy test');
$stage2 = UsageSync::apply_policy($alice);
check('low balance triggers warning+', in_array($stage2, ['warning', 'critical', 'grace', 'restricted'], true), "stage=$stage2");
$notes = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_notifications WHERE customer_id = %d AND type IN ('low_balance','critical_balance','suspension_warning')", $alice));
check('low-balance notification created once', (int) $notes >= 1, "notes=$notes");
UsageSync::apply_policy($alice); // cooldown must prevent a duplicate
$notes2 = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_notifications WHERE customer_id = %d AND type IN ('low_balance','critical_balance','suspension_warning')", $alice));
check('notification cooldown respected', $notes2 === $notes, "before=$notes after=$notes2");

// ---------- 10. Customer isolation (HC-5) ----------
check('bob cannot read alice service via get_owned', Services::get_owned((int) $service['id'], $bob) === null);
check('alice reads her own service', Services::get_owned((int) $service['id'], $alice) !== null);
check('bob ledger empty', Ledger::balance($bob)['available'] === 0);
check('bob order list empty', OrderService::list($bob) === []);

// ---------- 11. REST isolation smoke ----------
wp_set_current_user($bob);
$response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/arvan-reseller/v1/me/services'));
$data = $response->get_data();
check('REST /me/services scoped to session user', is_array($data) && count($data) === 0, 'count=' . (is_array($data) ? count($data) : -1));
wp_set_current_user($alice);
$response2 = rest_get_server()->dispatch(new WP_REST_Request('GET', '/arvan-reseller/v1/me/services'));
$data2 = $response2->get_data();
check('alice sees exactly her service via REST', is_array($data2) && count($data2) === 1 && !isset($data2[0]['credential_id']));

// ---------- 12. Provisioning failure → retry → success (spec §13.5) ----------
wp_set_current_user($alice);
$fail_order = OrderService::create($alice, 'cloud_server', 'g1-2-2-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-fail']);
$fail_ref   = (string) $fail_order['payment_ref'];
PaymentService::handle_order_callback($fail_ref, ['sandbox_proof' => SandboxProvider::proof($fail_ref, (int) $fail_order['amount'], 'order'), 'type' => 'order']);
$fail_status = OrderService::get((int) $fail_order['id'])['status'];
check('transient failure leaves recoverable state', $fail_status === 'provision_failed', "status=$fail_status");
check('no service created on failure', Services::by_order((int) $fail_order['id']) === null);
check('admin notified of failure', (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_notifications WHERE customer_id = 0 AND type = 'provision_failed'") >= 1);
// Retry (as the admin button / job runner would):
$retry = \ArvanReseller\Provisioning\Provisioner::provision((int) $fail_order['id']);
check('retry succeeds', $retry['ok'], $retry['message']);
check('order active after retry', OrderService::get((int) $fail_order['id'])['status'] === 'active');
check('money never silently consumed', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}arvrs_ledger WHERE ref_id = %s", $fail_ref)) === 2);

echo "\n" . ($GLOBALS['fails'] === 0 ? 'ALL E2E CHECKS PASSED' : $GLOBALS['fails'] . ' E2E CHECKS FAILED') . "\n";
exit($GLOBALS['fails'] === 0 ? 0 : 1);
