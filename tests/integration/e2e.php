<?php
/**
 * End-to-end integration scenario, run inside a REAL WordPress install:
 *
 *   wp eval-file tests/integration/e2e.php
 *
 * This is the half of the test suite the unit suite cannot be: real WordPress,
 * real MySQL, real REST dispatch, real roles. Everything MySQL-specific — that
 * `SHOW INDEX` really finds the UNIQUE keys, that dbDelta really created them
 * at 191 characters, that the money aggregates agree with InnoDB — is only
 * provable here (see the header of tests/support/FakeWpdb.php for the exact
 * list of what SQLite cannot prove).
 *
 * Covers: licensing, idempotent pages, registration, server-side pricing,
 * payment verify/claim/ledger/provision, duplicate-callback replay safety
 * driven down to the DB guards, the sandbox-gateway block, wallet top-ups
 * through the topups table, usage-sync dedup, the credit ladder, notification
 * cooldown, customer isolation (direct + REST) including anonymous and
 * wrong-owner callers, provisioning failure → retry → success, stuck-order
 * reclaim, stale-job reaping, term renewals with replay, and the v3→v5
 * migrations.
 *
 * Requires a FRESH install (re-runs need a reset DB — see docs/RUNBOOK.md) and
 * the demo activation token from DEVELOPMENT.md. Exits non-zero if any check
 * fails, and prints the number of checks it ran so no document has to guess.
 */

// Direct-access guard: this file ships inside the plugin directory, so it must
// not be reachable at /wp-content/plugins/arvan-reseller/tests/… (EX-116).
// wp eval-file loads WordPress first, so ABSPATH is always defined for a
// legitimate run.
defined('ABSPATH') || exit;

use ArvanReseller\Billing\Renewals;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Install\Schema;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Licensing\License;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Payments\SandboxProvider;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

$GLOBALS['fails'] = 0;
$GLOBALS['checks'] = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    $GLOBALS['checks']++;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail !== '' ? "  [$detail]" : '') . "\n";
    if (!$ok) {
        $GLOBALS['fails']++;
    }
}

/** Start a top-up through the production path and return its reference. */
function e2e_start_topup(int $customer_id, int $amount): string
{
    $url = PaymentService::start_topup($customer_id, $amount);
    $query = [];
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return isset($query['arvrs_ref']) ? rawurldecode((string) $query['arvrs_ref']) : '';
}

function e2e_topup(int $customer_id, int $amount): array
{
    $ref    = e2e_start_topup($customer_id, $amount);
    $result = PaymentService::handle_topup_callback($ref, [
        'sandbox_proof' => SandboxProvider::proof($ref, $amount, 'topup'),
        'type'          => 'topup',
    ]);
    return [$ref, $result];
}

/** A provider whose create() always fails permanently, for the customer-notification check. */
function e2e_permanently_failing_provider($provider)
{
    return new class($provider) implements \ArvanReseller\Arvan\ProviderInterface {
        private $inner;
        public function __construct($inner)
        {
            $this->inner = $inner;
        }
        public function plans(string $product): array
        {
            return $this->inner->plans($product);
        }
        public function options(string $product): array
        {
            return $this->inner->options($product);
        }
        public function create(string $product, string $plan_id, array $config, string $idempotency_key): \ArvanReseller\Arvan\RemoteResource
        {
            throw new \ArvanReseller\Arvan\ProviderError('invalid', 'e2e: permanent upstream rejection');
        }
        public function status(string $product, string $remote_id): \ArvanReseller\Arvan\RemoteResource
        {
            return $this->inner->status($product, $remote_id);
        }
        public function delete(string $product, string $remote_id): bool
        {
            return $this->inner->delete($product, $remote_id);
        }
        public function usage(string $product, array $remote_ids, string $since): array
        {
            return $this->inner->usage($product, $remote_ids, $since);
        }
        public function test_connection(): array
        {
            return $this->inner->test_connection();
        }
    };
}

// The token is deliberately NOT embedded in source (spec: no plaintext
// activation tokens in code). The demo token lives in DEVELOPMENT.md.
$demo_token = (string) getenv('ARVRS_DEMO_TOKEN');
if ($demo_token === '') {
    echo "SET ARVRS_DEMO_TOKEN first, e.g.:\n  ARVRS_DEMO_TOKEN=<demo token from DEVELOPMENT.md> wp eval-file tests/integration/e2e.php\n";
    exit(2);
}

global $wpdb;
$p = $wpdb->prefix . 'arvrs_';

// ---------- 1. Licensing ----------
check('invalid PAT rejected', !License::activate('WRONG-TOKEN'));
check('valid PAT activates', License::activate($demo_token));
$license_state = json_encode(get_option('arvrs_license'));
check('license persisted as a fingerprint, never the token',
    License::is_active() && strpos($license_state, $demo_token) === false && strpos($license_state, 'ARVRS-') === false,
    'stored=' . $license_state);

// ---------- 2. Schema integrity on the real database ----------
// SQLite cannot prove this: dbDelta silently declines to add a UNIQUE key to a
// table that already holds duplicates, and every idempotency guarantee below
// rests on those keys existing in MySQL.
$verify = Schema::verify();
check('every UNIQUE key the idempotency model needs exists in MySQL', !empty($verify['ok']),
    'missing=' . implode(',', $verify['missing']));
check('schema verification actually introspected the tables', count($verify['tables']) === 6,
    'tables=' . count($verify['tables']));

// ---------- 3. Onboarding equivalents ----------
Options::set_many(['brand_name' => 'ابر آزما', 'demo_mode' => true, 'global_markup' => 20.0, 'onboarded' => true]);
BaseCosts::seed_defaults();
$pages = PageFactory::ensure_pages();
check('pages created', count($pages) === 8);
$pages2 = PageFactory::ensure_pages();
check('page creation idempotent', $pages === $pages2);

// ---------- 4. Customer registration ----------
$alice = Customers::register('alice@example.com', 'password123', 'آلیس');
$bob   = Customers::register('bob@example.com', 'password123', 'باب');
check('customers registered', !is_wp_error($alice) && !is_wp_error($bob));
check('duplicate email rejected', is_wp_error(Customers::register('alice@example.com', 'password123', 'x')));

// ---------- 5. Checkout: server-side pricing ----------
wp_set_current_user($alice);
$order = OrderService::create($alice, 'cloud_server', 'g1-2-2-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-vm']);
check('order created', is_array($order), is_wp_error($order) ? $order->get_error_message() : '');
check('price = base × 1.2', (int) $order['amount'] === (int) round(BaseCosts::get('cloud_server', 'g1-2-2-25') * 1.2), 'amount=' . $order['amount']);
check('pricing snapshot persisted', strpos((string) $order['pricing'], '"markup_source":"global"') !== false);
check('invalid config rejected', is_wp_error(OrderService::create($alice, 'cdn', 'cdn-growth', ['domain' => 'not a domain !!'])));

// ---------- 6. Payment: verify → claim → ledger → provision ----------
$ref   = (string) $order['payment_ref'];
$proof = SandboxProvider::proof($ref, (int) $order['amount'], 'order');
check('tampered amount fails verify', !PaymentService::handle_order_callback($ref, ['sandbox_proof' => SandboxProvider::proof($ref, 999, 'order'), 'type' => 'order'])['ok']);
$result = PaymentService::handle_order_callback($ref, ['sandbox_proof' => $proof, 'type' => 'order']);
check('payment accepted', $result['ok'] && !$result['replay']);
check('order active after inline provisioning', $result['order']['status'] === 'active', 'status=' . $result['order']['status']);
// The payment page renders from provision.state and from nothing else, so it
// must agree with the order's real status (UX critical).
check('payment result reports a truthful provisioning state', $result['provision']['state'] === 'active',
    'state=' . $result['provision']['state']);

$service = Services::by_order((int) $order['id']);
check('service created with remote id', $service && $service['remote_id'] !== '');
check('service mapped to customer', (int) $service['customer_id'] === $alice);
check('service given a renewal clock at creation', !empty($service['renews_at']) && (int) $service['renewal_price'] > 0,
    'renews_at=' . (string) $service['renews_at'] . ' price=' . (int) $service['renewal_price']);

// ---------- 7. Duplicate callback, down to the DB guards (HC-7) ----------
// The callback-level replay only proves the STATE short-circuit: the order is
// already active, so it returns before verify(), before claim_paid() and before
// either Ledger::append(). The three real defences are exercised directly
// underneath it, because those are what protect two SIMULTANEOUS callbacks —
// both of which read a payable status.
$replay = PaymentService::handle_order_callback($ref, ['sandbox_proof' => $proof, 'type' => 'order']);
check('duplicate callback detected as replay', $replay['ok'] && $replay['replay']);

$claim_again = OrderService::claim_paid($ref, (int) $order['amount'], 'tx-second');
check('claim_paid on a settled order reports replay, not a second claim', $claim_again['kind'] === 'replay',
    'kind=' . $claim_again['kind']);
$mismatch = OrderService::claim_paid($ref, 1, 'tx-wrong-amount');
check('claim_paid never re-settles at a different amount', in_array($mismatch['kind'], ['replay', 'amount_mismatch'], true),
    'kind=' . $mismatch['kind']);
check('ledger unique key absorbs a replayed payment entry',
    Ledger::append($alice, 'payment', (int) $order['amount'], 'order', $ref, 'replayed payment') === 0);
check('ledger unique key absorbs a replayed purchase entry',
    Ledger::append($alice, 'purchase', (int) $order['amount'], 'order', $ref, 'replayed purchase') === 0);

$payments = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ledger WHERE ref_id = %s AND type = 'payment'", $ref));
$services_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE order_id = %d", (int) $order['id']));
check('exactly one payment ledger row', $payments === 1, "rows=$payments");
check('exactly one service', $services_count === 1);

// The callback enqueues provision_order AND provisions inline. Running the
// queue afterwards is the scenario that would buy a SECOND cloud server.
JobRunner::run_due();
$services_after_queue = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE order_id = %d", (int) $order['id']));
check('queued provisioning job after an inline success creates no second service',
    $services_after_queue === 1, "services=$services_after_queue");

// ---------- 8. Wallet & ledger (top-ups now live in their own table) ----------
$balance = Ledger::balance($alice);
check('purchase settled net-zero wallet effect', $balance['available'] === 0, 'available=' . $balance['available']);

[$topup_ref, $topup] = e2e_topup($alice, 5000000);
check('top-up intent persisted in the topups table, not wp_options',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}topups WHERE ref = %s", $topup_ref)) === 1
    && get_option('arvrs_topup_' . $topup_ref, null) === null);
check('topup credited', $topup['ok'] && !$topup['replay']);
$topup2 = PaymentService::handle_topup_callback($topup_ref, ['sandbox_proof' => SandboxProvider::proof($topup_ref, 5000000, 'topup'), 'type' => 'topup']);
check('topup replay safe', $topup2['ok'] && $topup2['replay']);
check('balance after single topup', Ledger::balance($alice)['available'] === 5000000);
$settled_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}topups WHERE ref = %s", $topup_ref));
check('settled intent cannot be redeemed again', $settled_status === 'settled' && PaymentService::topup_intent($topup_ref) === null,
    "status=$settled_status");

// An intent is a checkout session, not a coupon.
$stale_ref = e2e_start_topup($alice, 100000);
$wpdb->query($wpdb->prepare("UPDATE {$p}topups SET expires_at = %s WHERE ref = %s", gmdate('Y-m-d H:i:s', time() - 60), $stale_ref));
$stale = PaymentService::handle_topup_callback($stale_ref, ['sandbox_proof' => SandboxProvider::proof($stale_ref, 100000, 'topup'), 'type' => 'topup']);
check('expired top-up intent is refused', !$stale['ok']);
check('expired intent credited nothing', Ledger::balance($alice)['available'] === 5000000);

// ---------- 9. Usage sync idempotency ----------
$stats1 = UsageSync::sync_all();
$stats2 = UsageSync::sync_all();
check('first sync ingested usage', $stats1['ingested'] > 0, 'ingested=' . $stats1['ingested']);
check('re-sync ingests nothing (dedup)', $stats2['ingested'] === 0, 'ingested=' . $stats2['ingested']);
$after_usage = Ledger::balance($alice);
$usage_billed = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(price),0) FROM {$p}usage_records WHERE customer_id = %d", $alice));
$usage_cost   = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(cost),0) FROM {$p}usage_records WHERE customer_id = %d", $alice));
check('usage debited once, at the customer price', $after_usage['available'] === 5000000 - $usage_billed, 'available=' . $after_usage['available']);
check('metered usage carries a margin', $usage_billed > $usage_cost, "price=$usage_billed cost=$usage_cost");

// ---------- 10. Policy engine ----------
UsageSync::apply_policy($alice);
$stage = get_user_meta($alice, 'arvrs_policy_stage', true);
// Alice is ~5,000,000 minus a few hours of usage, well above the 500,000
// warning threshold: the stage is pinned exactly, not merely "one of five".
check('a well-funded customer stages healthy', $stage === 'healthy', "stage=$stage balance=" . $after_usage['available']);
// Force the balance into the warning band precisely: leave 400,000, which is
// below policy_warning (500,000) and above policy_critical (100,000).
$drain = $after_usage['available'] - 400000;
Ledger::append($alice, 'adjustment', $drain, 'admin', 'e2e-drain', 'drain for policy test');
$stage2 = UsageSync::apply_policy($alice);
check('a 400,000 balance stages exactly warning', $stage2 === 'warning', "stage=$stage2");
$notes = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notifications WHERE customer_id = %d AND type IN ('low_balance','critical_balance','suspension_warning')", $alice));
check('low-balance notification created exactly once', $notes === 1, "notes=$notes");
UsageSync::apply_policy($alice); // cooldown must prevent a duplicate
$notes2 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}notifications WHERE customer_id = %d AND type IN ('low_balance','critical_balance','suspension_warning')", $alice));
check('notification cooldown respected', $notes2 === $notes, "before=$notes after=$notes2");

// ---------- 11. Customer isolation (HC-5) ----------
check('bob cannot read alice service via get_owned', Services::get_owned((int) $service['id'], $bob) === null);
check('alice reads her own service', Services::get_owned((int) $service['id'], $alice) !== null);
check('bob ledger empty', Ledger::balance($bob)['available'] === 0);
check('bob order list empty', OrderService::list($bob) === []);

// ---------- 12. REST authorization, positive AND negative ----------
wp_set_current_user($bob);
$response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/arvan-reseller/v1/me/services'));
$data = $response->get_data();
check('REST /me/services scoped to session user', is_array($data) && count($data) === 0, 'count=' . (is_array($data) ? count($data) : -1));
wp_set_current_user($alice);
$response2 = rest_get_server()->dispatch(new WP_REST_Request('GET', '/arvan-reseller/v1/me/services'));
$data2 = $response2->get_data();
check('alice sees exactly her service via REST', is_array($data2) && count($data2) === 1 && !isset($data2[0]['credential_id']));

// Anonymous: the permission_callback must refuse, not return an empty list.
wp_set_current_user(0);
foreach (['/arvan-reseller/v1/me/services', '/arvan-reseller/v1/me/orders', '/arvan-reseller/v1/me/summary'] as $route) {
    $anon = rest_get_server()->dispatch(new WP_REST_Request('GET', $route));
    check('anonymous REST call to ' . $route . ' is refused', in_array($anon->get_status(), [401, 403], true),
        'status=' . $anon->get_status());
}
// The request must carry VALID args, otherwise WordPress rejects it at
// has_valid_params() with a 400 before permission_callback ever runs — and the
// check would pass while proving nothing about authorization.
$anon_post = new WP_REST_Request('POST', '/arvan-reseller/v1/me/topup');
$anon_post->set_param('amount', 500000);
$anon_res = rest_get_server()->dispatch($anon_post);
check('anonymous top-up is refused', in_array($anon_res->get_status(), [401, 403], true), 'status=' . $anon_res->get_status());

// A logged-in WordPress user who is NOT a customer must be refused too.
$outsider = wp_insert_user(['user_login' => 'outsider', 'user_email' => 'outsider@example.com', 'user_pass' => 'password123', 'role' => 'subscriber']);
wp_set_current_user($outsider);
$outsider_response = rest_get_server()->dispatch(new WP_REST_Request('GET', '/arvan-reseller/v1/me/services'));
check('a logged-in non-customer is refused', in_array($outsider_response->get_status(), [401, 403], true),
    'status=' . $outsider_response->get_status());

// Wrong owner: an id that is not yours answers 404, never someone else's row.
wp_set_current_user($bob);
$stolen = new WP_REST_Request('GET', '/arvan-reseller/v1/me/services/' . (int) $service['id']);
$stolen->set_param('id', (int) $service['id']);
$stolen_response = rest_get_server()->dispatch($stolen);
check('wrong-owner service read answers 404', $stolen_response->get_status() === 404, 'status=' . $stolen_response->get_status());

$stolen_state = new WP_REST_Request('GET', '/arvan-reseller/v1/orders/' . (int) $order['id'] . '/state');
$stolen_state->set_param('id', (int) $order['id']);
$stolen_state_response = rest_get_server()->dispatch($stolen_state);
check('wrong-owner order state answers 404', $stolen_state_response->get_status() === 404, 'status=' . $stolen_state_response->get_status());

// …and the owner's own poll works, so the refusals above are not vacuous.
wp_set_current_user($alice);
$state_request = new WP_REST_Request('GET', '/arvan-reseller/v1/orders/' . (int) $order['id'] . '/state');
$state_request->set_param('id', (int) $order['id']);
$state_response = rest_get_server()->dispatch($state_request);
$state_data = $state_response->get_data();
check('owner can poll her own order state', $state_response->get_status() === 200 && $state_data['provision_state'] === 'active',
    'status=' . $state_response->get_status());

// ---------- 13. CSRF: a state-changing form post without a nonce ----------
// check_admin_referer() calls wp_die(), so the guard is observed by trapping it.
$nonce_blocked = false;
add_filter('wp_die_handler', static function () use (&$nonce_blocked) {
    return static function () use (&$nonce_blocked) {
        $nonce_blocked = true;
        throw new \RuntimeException('wp_die');
    };
});
$_POST = ['service_id' => (int) $service['id']]; // no arvrs_nonce
try {
    \ArvanReseller\Front\FormActions::cancel_renewal();
} catch (\Throwable $e) {
    // expected: wp_die or the redirect that follows it
}
$_POST = [];
remove_all_filters('wp_die_handler');
check('renewal cancellation without a nonce is blocked', $nonce_blocked);
check('the service was not cancelled by the nonceless request', !empty(Services::get((int) $service['id'])['renews_at']));

// ---------- 14. Provisioning failure → customer told → retry → success ----------
wp_set_current_user($alice);
$fail_order = OrderService::create($alice, 'cloud_server', 'g1-2-2-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-fail']);
$fail_ref   = (string) $fail_order['payment_ref'];
$fail_result = PaymentService::handle_order_callback($fail_ref, ['sandbox_proof' => SandboxProvider::proof($fail_ref, (int) $fail_order['amount'], 'order'), 'type' => 'order']);
$fail_status = OrderService::get((int) $fail_order['id'])['status'];
check('transient failure leaves recoverable state', $fail_status === 'provision_failed', "status=$fail_status");
check('the payment page is told the truth about a failed provisioning',
    $fail_result['provision']['state'] === 'failed', 'state=' . $fail_result['provision']['state']);
check('no service created on failure', Services::by_order((int) $fail_order['id']) === null);
// A TRANSIENT failure must NOT page the admin: most self-heal on the next
// attempt, and alerting on each one is how an operator learns to ignore the
// channel. The contract is that the work is handed to the durable queue
// instead, and the admin only hears if it dead-letters. Assert that contract,
// not "somebody was notified" — which was true of the wrong recipient.
check('a transient failure does not page the admin',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}notifications WHERE customer_id = 0 AND type = 'provision_failed'") === 0);
check('a transient failure is handed to the durable queue instead',
    (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$p}jobs WHERE type = 'provision_order' AND payload LIKE %s",
        '%"order_id":' . (int) $fail_order['id'] . '%'
    )) >= 1);
// Retry (as the admin button / job runner would):
$retry = Provisioner::provision((int) $fail_order['id']);
check('retry succeeds', $retry['ok'], $retry['message']);
check('retry reports the kind, not a message the runner has to parse', $retry['kind'] === 'provisioned', 'kind=' . $retry['kind']);
check('order active after retry', OrderService::get((int) $fail_order['id'])['status'] === 'active');
check('money never silently consumed', (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ledger WHERE ref_id = %s", $fail_ref)) === 2);

// A permanently failing provisioning must reach the CUSTOMER, not only the
// admin — a paid buyer being told nothing was the panel's UX critical.
$doomed = OrderService::create($alice, 'cloud_server', 'g1-2-2-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-doomed']);
add_filter('arvrs_arvan_provider', 'e2e_permanently_failing_provider');
// Providers are memoised per mode, so the filter only takes effect once the
// cached instance is dropped.
\ArvanReseller\Plugin::flush_mode_cache();
PaymentService::handle_order_callback((string) $doomed['payment_ref'], [
    'sandbox_proof' => SandboxProvider::proof((string) $doomed['payment_ref'], (int) $doomed['amount'], 'order'),
    'type'          => 'order',
]);
remove_filter('arvrs_arvan_provider', 'e2e_permanently_failing_provider');
\ArvanReseller\Plugin::flush_mode_cache();
$customer_told = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}notifications WHERE customer_id = %d AND type = 'provision_failed'", $alice));
check('the customer is told when their paid order cannot be provisioned', $customer_told >= 1, "notices=$customer_told");
$admin_told = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}notifications WHERE customer_id = 0 AND type = 'provision_failed'");
check('a permanent failure DOES page the admin', $admin_told >= 1, "notices=$admin_told");
check('a permanently failed order is left recoverable, not active',
    OrderService::get((int) $doomed['id'])['status'] === 'provision_failed');

// ---------- 15. Stuck-order recovery ----------
// `provisioning` is the only non-terminal state with no timeout of its own; a
// worker killed inside it used to strand a paid order forever.
$stuck = OrderService::create($alice, 'cloud_server', 'g1-1-1-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04', 'name' => 'demo-stuck']);
$wpdb->query($wpdb->prepare(
    "UPDATE {$p}orders SET status = 'provisioning', updated_at = %s WHERE id = %d",
    gmdate('Y-m-d H:i:s', time() - 3600), (int) $stuck['id']
));
check('a fresh provisioning claim is not reclaimed', Provisioner::reclaim_stale(600) === 0);
$reclaimed = Provisioner::reclaim_stale(20);
check('an abandoned provisioning claim is reclaimed', $reclaimed >= 1, "moved=$reclaimed");
check('the reclaimed order is claimable again', OrderService::get((int) $stuck['id'])['status'] === 'provision_failed');
$requeued = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$p}jobs WHERE type = 'provision_order' AND payload LIKE %s", '%"order_id":' . (int) $stuck['id'] . '%'));
check('reclaiming also hands the work back to the queue', $requeued >= 1, "jobs=$requeued");

// ---------- 16. Stale-job reaping ----------
$stale_job = JobRunner::enqueue('usage_sync', ['e2e' => 1]);
$wpdb->query($wpdb->prepare(
    "UPDATE {$p}jobs SET status = 'running', attempts = 1, claimed_at = %s WHERE id = %d",
    gmdate('Y-m-d H:i:s', time() - 3600), $stale_job
));
check('an abandoned claim is visible to the operator', JobRunner::stats()['stale_running'] >= 1);
$reaped = JobRunner::reap_stale();
check('reap_stale reclaims the abandoned job', $reaped >= 1, "reaped=$reaped");
$reaped_status = (string) $wpdb->get_var($wpdb->prepare("SELECT status FROM {$p}jobs WHERE id = %d", $stale_job));
check('the reclaimed job is runnable again', $reaped_status === 'pending', "status=$reaped_status");
check('the reclaim reason is recorded on the row',
    strpos((string) $wpdb->get_var($wpdb->prepare("SELECT last_error FROM {$p}jobs WHERE id = %d", $stale_job)), 'reclaimed') !== false);

// ---------- 17. Term renewals: the recurring-revenue engine ----------
// Ownership of the wallet ladder only becomes real once something debits it on
// a schedule; a checkout payment is net-zero.
$renewal_service = Services::by_order((int) $order['id']);
$renewal_id      = (int) $renewal_service['id'];
e2e_topup($alice, 20000000);
$before_renewal = Ledger::balance($alice)['available'];
$due_at = gmdate('Y-m-d H:i:s', time() - 3600);
$wpdb->query($wpdb->prepare("UPDATE {$p}services SET renews_at = %s WHERE id = %d", $due_at, $renewal_id));

check('a due service is in the renewal batch', count(Renewals::due(50)) >= 1);
$charge = Renewals::charge($renewal_id);
check('renewal charged', $charge['ok'] && $charge['kind'] === 'charged', 'kind=' . $charge['kind']);
check('the renewal debited the wallet', Ledger::balance($alice)['available'] === $before_renewal - $charge['charged'],
    'charged=' . $charge['charged']);
$advanced = (string) $wpdb->get_var($wpdb->prepare("SELECT renews_at FROM {$p}services WHERE id = %d", $renewal_id));
check('the billing clock advanced by one term',
    $advanced === gmdate('Y-m-d H:i:s', strtotime($due_at . ' UTC') + 30 * DAY_IN_SECONDS), "renews_at=$advanced");
check('renewal_count incremented once',
    (int) $wpdb->get_var($wpdb->prepare("SELECT renewal_count FROM {$p}services WHERE id = %d", $renewal_id)) === 1);

// Replay: put the clock back, so a second runner sees the same due term. The
// ledger key absorbs the charge and the customer is not debited twice.
$wpdb->query($wpdb->prepare("UPDATE {$p}services SET renews_at = %s WHERE id = %d", $due_at, $renewal_id));
$balance_before_replay = Ledger::balance($alice)['available'];
$replayed = Renewals::charge($renewal_id);
check('a replayed renewal is recognised, not re-charged', $replayed['ok'] && $replayed['kind'] === 'replay', 'kind=' . $replayed['kind']);
check('the replay debited nothing', Ledger::balance($alice)['available'] === $balance_before_replay);
check('exactly one renewal ledger entry for the term',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}ledger WHERE ref_type = 'renewal' AND ref_id = %s", $renewal_id . ':' . $due_at)) === 1);
check('exactly one usage record for the term',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}usage_records WHERE service_id = %d AND period_start = %s", $renewal_id, $due_at)) === 1);

// Cancelling stops future charges without touching the remote resource.
check('renewal cancellation succeeds', Renewals::cancel($renewal_id, 'e2e'));
check('a cancelled service never charges again', Renewals::charge($renewal_id)['kind'] === 'cancelled');
check('the remote resource is untouched by a renewal cancellation',
    (string) $wpdb->get_var($wpdb->prepare("SELECT remote_id FROM {$p}services WHERE id = %d", $renewal_id)) !== '');

// ---------- 18. Per-customer spending limit (review fix) ----------
// credit_limit is deliberately NOT a checkout gate (orders settle via the
// gateway, net-zero on the wallet) — only spending_limit caps checkout.
$carol = Customers::register('carol@example.com', 'password123', 'کارول');
wp_set_current_user($carol);
\ArvanReseller\Customers\Rules::save($carol, ['spending_limit' => 1000000, 'status' => 'active']);
$blocked = OrderService::create($carol, 'cloud_server', 'g1-8-8-100', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04']);
check('spending_limit blocks over-limit purchase', is_wp_error($blocked) && $blocked->get_error_code() === 'spending_limit',
    is_wp_error($blocked) ? $blocked->get_error_code() : 'no error');
\ArvanReseller\Customers\Rules::save($carol, ['spending_limit' => '', 'credit_limit' => 0, 'status' => 'active']);
$ok_order = OrderService::create($carol, 'cloud_server', 'g1-1-1-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04']);
check('credit_limit does NOT block a gateway order (regression fix)', is_array($ok_order),
    is_wp_error($ok_order) ? $ok_order->get_error_code() : 'ok');

// ---------- 19. suspend_service applies then lifts on top-up (review fix) ----------
// Isolated customer 'dave' with one directly-seeded service and a debt dated
// past the grace window, so apply_policy reaches 'restricted' synchronously.
update_option('arvrs_settings', array_merge((array) get_option('arvrs_settings', []),
    ['policy_actions' => ['notify_customer', 'block_purchases', 'suspend_service'], 'policy_grace_days' => 3]));
$dave = Customers::register('dave@example.com', 'password123', 'داوود');
$wpdb->insert("{$p}services", [
    'order_id' => 999001, 'customer_id' => $dave, 'credential_id' => null, 'product' => 'cloud_server',
    'plan_id' => 'g1-1-1-25', 'remote_id' => 'demo-seed-dave', 'status' => 'active',
    'config' => '{}', 'connection' => '{}', 'is_demo' => 1,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
// Debit dated 6 days ago → negative_since_days ≈ 6 > grace 3 → restricted.
$wpdb->insert("{$p}ledger", [
    'customer_id' => $dave, 'type' => 'usage_debit', 'direction' => 'debit', 'amount' => 500000,
    'currency' => 'IRT', 'ref_type' => 'usage', 'ref_id' => 'dave-old-debt', 'description' => 'aged debt',
    'actor' => 'system', 'is_demo' => 1, 'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400),
]);
Ledger::flush_cache($dave);
check('negative_since_days measures the crossing point, not the newest debit',
    Ledger::negative_since_days($dave) >= 6, 'days=' . var_export(Ledger::negative_since_days($dave), true));
$rstage = UsageSync::apply_policy($dave);
check('customer reaches restricted stage', $rstage === 'restricted', "stage=$rstage");
$sus = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE customer_id = %d AND status = 'suspended'", $dave));
check('services suspended at restricted', $sus >= 1, "suspended=$sus");
check('purchases blocked while restricted', UsageSync::purchases_blocked($dave));
e2e_topup($dave, 10000000);
$sus_after = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE customer_id = %d AND status = 'suspended'", $dave));
check('top-up lifts suspension', $sus_after === 0, "suspended_after=$sus_after");
check('purchases unblocked after top-up', !UsageSync::purchases_blocked($dave));
check('a repaired balance clears the negative clock', Ledger::negative_since_days($dave) === null);

// ---------- 20. partial top-up into CRITICAL band still lifts suspension ----------
// (convergence-review fix: lift must fire at any non-restricted stage, not
//  only HEALTHY/WARNING).
$erin = Customers::register('erin@example.com', 'password123', 'ایرین');
$wpdb->insert("{$p}services", [
    'order_id' => 999002, 'customer_id' => $erin, 'credential_id' => null, 'product' => 'cloud_server',
    'plan_id' => 'g1-1-1-25', 'remote_id' => 'demo-seed-erin', 'status' => 'active',
    'config' => '{}', 'connection' => '{}', 'is_demo' => 1,
    'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
$wpdb->insert("{$p}ledger", [
    'customer_id' => $erin, 'type' => 'usage_debit', 'direction' => 'debit', 'amount' => 400000,
    'currency' => 'IRT', 'ref_type' => 'usage', 'ref_id' => 'erin-old-debt', 'description' => 'aged debt',
    'actor' => 'system', 'is_demo' => 1, 'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * 86400),
]);
Ledger::flush_cache($erin);
UsageSync::apply_policy($erin); // restricted → suspended
$erin_sus = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE customer_id = %d AND status = 'suspended'", $erin));
check('erin suspended at restricted', $erin_sus === 1, "suspended=$erin_sus");
// Partial top-up: balance goes -400000 → +50000, which is <= critical (100000) → CRITICAL stage.
e2e_topup($erin, 450000);
$erin_stage = get_user_meta($erin, 'arvrs_policy_stage', true);
$erin_sus2  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}services WHERE customer_id = %d AND status = 'suspended'", $erin));
check('partial top-up lands in critical band', $erin_stage === 'critical', "stage=$erin_stage");
check('suspension lifted even in critical band', $erin_sus2 === 0, "suspended_after=$erin_sus2");

// ---------- 21. Demo/real ledger isolation (reconciliation isolation) ----------
// The whole run has been in demo mode, so EVERY ledger row must be stamped —
// counting only the stamped ones cannot detect a row that escaped.
$demo_ledger = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ledger WHERE is_demo = 1");
$real_ledger = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ledger WHERE is_demo = 0");
check('demo-mode ledger rows are is_demo stamped', $demo_ledger > 0, "demo_rows=$demo_ledger");
check('no unstamped ledger row escaped demo mode', $real_ledger === 0, "real_rows=$real_ledger");
$demo_usage_unstamped = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}usage_records WHERE is_demo = 0");
check('demo-mode usage rows are is_demo stamped too', $demo_usage_unstamped === 0, "unstamped=$demo_usage_unstamped");
// …and a live store must not be able to spend demo credit.
$alice_demo_balance = Ledger::balance($alice, true)['available'];
$alice_real_balance = Ledger::balance($alice, false)['available'];
check('demo credit is excluded from the real-money view', $alice_real_balance === 0 && $alice_demo_balance > 0,
    "demo=$alice_demo_balance real=$alice_real_balance");

// ---------- 22. The sandbox gateway must never settle real money ----------
// The entire run above was in demo mode, where sandbox_blocked() is false by
// construction — which is exactly why this guard had no coverage. Flip the
// store live and prove every settlement path refuses.
$blocked_order = OrderService::create($alice, 'cloud_server', 'g1-1-1-25', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04']);
$blocked_ref   = (string) $blocked_order['payment_ref'];
$blocked_topup_ref = e2e_start_topup($alice, 1000000);
$ledger_before_live = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ledger");

Options::set('demo_mode', false);
// Leaving demo mode also requires a credential that has passed a connection
// test, or Plugin::demo_mode() forces demo back on (spec §11).
$wpdb->insert("{$p}credentials", [
    'name' => 'e2e-live', 'token_enc' => \ArvanReseller\Support\Crypto::encrypt('e2e-not-a-real-token'),
    'token_last4' => 'oken', 'enabled' => 1, 'products' => '', 'priority' => 10, 'is_default' => 1,
    'last_ok_at' => gmdate('Y-m-d H:i:s'), 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
]);
\ArvanReseller\Plugin::flush_mode_cache();
check('the store is genuinely out of demo mode', \ArvanReseller\Plugin::demo_mode() === false);
check('sandbox_blocked() is true once the store is live', PaymentService::sandbox_blocked());
check('gateway_status reports the store cannot take money', PaymentService::gateway_status()['ok'] === false);

$blocked_result = PaymentService::handle_order_callback($blocked_ref, [
    'sandbox_proof' => SandboxProvider::proof($blocked_ref, (int) $blocked_order['amount'], 'order'),
    'type'          => 'order',
]);
check('a sandbox proof cannot settle a real order', !$blocked_result['ok']);
check('the blocked order stayed unpaid', OrderService::get((int) $blocked_order['id'])['status'] === 'pending_payment');

$blocked_topup = PaymentService::handle_topup_callback($blocked_topup_ref, [
    'sandbox_proof' => SandboxProvider::proof($blocked_topup_ref, 1000000, 'topup'),
    'type'          => 'topup',
]);
check('a sandbox proof cannot credit a real wallet', !$blocked_topup['ok']);
$ledger_after_live = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ledger");
check('no ledger row was written through the blocked gateway', $ledger_after_live === $ledger_before_live,
    "before=$ledger_before_live after=$ledger_after_live");

wp_set_current_user($alice);
$orders_before_checkout = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}orders WHERE customer_id = %d", $alice));
$checkout_request = new WP_REST_Request('POST', '/arvan-reseller/v1/checkout');
$checkout_request->set_param('product', 'cloud_server');
$checkout_request->set_param('plan_id', 'g1-1-1-25');
$checkout_request->set_param('config', ['region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04']);
$blocked_checkout = rest_get_server()->dispatch($checkout_request);
check('checkout itself refuses while no real gateway is registered',
    $blocked_checkout->get_status() === 503, 'status=' . $blocked_checkout->get_status());
check('the refused checkout created no order',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}orders WHERE customer_id = %d", $alice)) === $orders_before_checkout);

$wpdb->query("DELETE FROM {$p}credentials WHERE name = 'e2e-live'");
Options::set('demo_mode', true);
\ArvanReseller\Plugin::flush_mode_cache();

// ---------- 23. Migrations on the real database ----------
// Stamped LAST, because a data migration rewrites rows the checks above read.
// This is the only place the v3→v4 back-stamp — the one migration that decides
// whether a live reseller's whole ledger counts as demo money — ever executes.
$wpdb->insert("{$p}ledger", [
    'customer_id' => $alice, 'type' => 'promo_credit', 'direction' => 'credit', 'amount' => 1,
    'currency' => 'IRT', 'ref_type' => 'migration', 'ref_id' => 'e2e-v3-row', 'description' => 'pre-v4 row',
    'actor' => 'system', 'is_demo' => 0, 'created_at' => gmdate('Y-m-d H:i:s'),
]);
$wpdb->query("UPDATE {$p}services SET renews_at = NULL, renewal_price = 0 WHERE remote_id = 'demo-seed-dave'");
$wpdb->insert($wpdb->options, ['option_name' => 'arvrs_topup_TOP-LEGACY01', 'option_value' => serialize(['customer_id' => $alice, 'amount' => 777000, 'at' => time()]), 'autoload' => 'no']);
update_option('arvrs_schema_version', 3);
Schema::migrate();

check('v3→v4 back-stamped the pre-existing ledger row as demo',
    (int) $wpdb->get_var($wpdb->prepare("SELECT is_demo FROM {$p}ledger WHERE ref_id = %s", 'e2e-v3-row')) === 1);
$dave_renews = (string) $wpdb->get_var("SELECT renews_at FROM {$p}services WHERE remote_id = 'demo-seed-dave'");
check('v4→v5 gave a clockless service a renewal date', $dave_renews !== '' && $dave_renews !== null, "renews_at=$dave_renews");
check('v4→v5 moved a legacy top-up option into the topups table',
    (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$p}topups WHERE ref = %s", 'TOP-LEGACY01')) === 1
    && get_option('arvrs_topup_TOP-LEGACY01', null) === null);
check('the migration stamped the version only after verifying the keys',
    (int) get_option('arvrs_schema_version') === ARVRS_SCHEMA_VERSION, 'version=' . get_option('arvrs_schema_version'));
check('the backfill was audited with its row count',
    (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}audit_log WHERE action = 'schema.backfill'") >= 1);
check('schema verification still passes after migrating', !empty(Schema::verify()['ok']));


echo "\n" . $GLOBALS['checks'] . " checks run\n";
echo ($GLOBALS['fails'] === 0 ? 'ALL E2E CHECKS PASSED' : $GLOBALS['fails'] . ' E2E CHECKS FAILED') . "\n";
exit($GLOBALS['fails'] === 0 ? 0 : 1);
