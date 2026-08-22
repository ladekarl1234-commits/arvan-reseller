<?php
/**
 * Base case for every test that needs a database: a fresh in-memory SQLite
 * schema, built by the plugin's own `Schema::migrate()`, per test method.
 *
 * Building it through `migrate()` rather than a hand-written fixture is
 * deliberate — it means the unique keys under test are the ones the plugin
 * actually ships, so deleting `UNIQUE KEY uniq_ref` from Schema.php makes the
 * ledger idempotency tests fail instead of quietly passing (EX-019).
 */

defined('ABSPATH') || exit;

use ArvanReseller\Install\Schema;
use ArvanReseller\Plugin;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Usage\UsageSync;
use PHPUnit\Framework\TestCase;

abstract class Arvrs_DbTestCase extends TestCase
{
    /** @var Arvrs_FakeWpdb */
    protected $db;

    protected function setUp(): void
    {
        arvrs_test_reset_state();
        $_POST = [];
        $_GET  = [];

        $this->db = new Arvrs_FakeWpdb();
        $GLOBALS['wpdb'] = $this->db;

        Schema::migrate();

        // Static memos would otherwise carry a previous test's world across.
        Plugin::flush_mode_cache();
        UsageSync::flush_markup();
    }

    protected function tearDown(): void
    {
        $this->db->intercept = null;
        parent::tearDown();
    }

    /** A customer id that also exists for the user-facing shims. */
    protected function customer(int $id = 101): int
    {
        arvrs_test_add_user($id, ['user_email' => 'c' . $id . '@example.test']);
        return $id;
    }

    /**
     * Insert an order row directly, bypassing checkout — the money tests are
     * about what happens after an order exists, not about how it was priced.
     *
     * @return array{0:int,1:string} [order_id, payment_ref]
     */
    protected function seed_order(int $customer_id, int $amount, string $status = 'pending_payment', array $extra = []): array
    {
        $ref = 'ARV-' . strtoupper(bin2hex(random_bytes(4)));
        $this->db->insert($this->db->prefix . 'arvrs_orders', array_merge([
            'customer_id' => $customer_id,
            'product'     => 'cloud_server',
            'plan_id'     => 'g1-1-1-25',
            'config'      => '{"region":"ir-thr-simin","image":"ubuntu-24.04"}',
            'status'      => $status,
            'pricing'     => '{}',
            'amount'      => $amount,
            'base_cost'   => (int) round($amount / 1.2),
            'margin'      => $amount - (int) round($amount / 1.2),
            'currency'    => 'IRT',
            'payment_ref' => $ref,
            'is_demo'     => 1,
            'created_at'  => Helpers::now(),
            'updated_at'  => Helpers::now(),
        ], $extra));
        return [(int) $this->db->insert_id, $ref];
    }

    /** Insert a service row with its billing clock already set. */
    protected function seed_service(int $customer_id, int $order_id, array $extra = []): int
    {
        $this->db->insert($this->db->prefix . 'arvrs_services', array_merge([
            'order_id'      => $order_id,
            'customer_id'   => $customer_id,
            'credential_id' => null,
            'product'       => 'cloud_server',
            'plan_id'       => 'g1-1-1-25',
            'remote_id'     => 'demo-' . $order_id,
            'status'        => 'active',
            'config'        => '{}',
            'connection'    => '{}',
            'renews_at'     => gmdate('Y-m-d H:i:s', time() - 3600),
            'term_days'     => 30,
            'renewal_price' => 1200000,
            'renewal_count' => 0,
            'is_demo'       => 1,
            'created_at'    => Helpers::now(),
            'updated_at'    => Helpers::now(),
        ], $extra));
        return (int) $this->db->insert_id;
    }

    protected function count_rows(string $table, string $where = '1=1'): int
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM ' . $this->db->prefix . 'arvrs_' . $table . ' WHERE ' . $where);
    }

    /**
     * Take the plugin out of demo mode for real: `Plugin::demo_mode()` only
     * answers false when the setting is off AND a credential has passed a
     * connection test, so both have to be true.
     */
    protected function go_live(): void
    {
        \ArvanReseller\Support\Options::set('demo_mode', false);
        $this->db->insert($this->db->prefix . 'arvrs_credentials', [
            'name'        => 'live',
            // A real encrypted token, so Credentials::select_for() can decrypt
            // it and the credential-routing paths are genuinely exercised.
            'token_enc'   => \ArvanReseller\Support\Crypto::encrypt('arvan-live-token-1234'),
            'token_last4' => '1234',
            'enabled'     => 1,
            'products'    => '',
            'priority'    => 10,
            'is_default'  => 1,
            'last_ok_at'  => Helpers::now(),
            'created_at'  => Helpers::now(),
            'updated_at'  => Helpers::now(),
        ]);
        Plugin::flush_mode_cache();
    }
}
