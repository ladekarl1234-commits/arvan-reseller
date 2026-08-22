<?php
/**
 * Metered usage and the credit ladder against a real database.
 *
 * These are the highest-value E2E checks ported down into the fast suite
 * (EX-078): duplicate usage period, the markup that stops the metered path
 * being sold at cost, the ladder from healthy to restricted and back, and the
 * spending limit at checkout.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Arvan\UsageRow;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Policies\PolicyEngine;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

final class UsagePolicyTest extends Arvrs_DbTestCase
{
    private function usage_row(string $remote_id, int $hours_ago, int $cost): UsageRow
    {
        $start = gmdate('Y-m-d H:00:00', time() - $hours_ago * HOUR_IN_SECONDS);
        $end   = gmdate('Y-m-d H:00:00', time() - ($hours_ago - 1) * HOUR_IN_SECONDS);
        return new UsageRow($remote_id, $start, $end, 1.0, 'hour', $cost);
    }

    /* -------------------------------------------------------------- usage */

    /** Reselling at cost is 0% margin on the entire metered path. */
    public function test_the_customer_is_debited_the_marked_up_price_not_the_upstream_cost(): void
    {
        $customer = $this->customer();
        Options::set('global_markup', 30.0);
        UsageSync::flush_markup();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $result = UsageSync::ingest($service_id, $customer, $this->usage_row('demo-1', 2, 100000));

        $this->assertSame(1, $result['ingested']);
        $this->assertSame(1, $result['debited']);
        $row = $this->db->get_row($this->db->prepare(
            'SELECT * FROM ' . UsageSync::table() . ' WHERE service_id = %d', $service_id
        ), ARRAY_A);
        $this->assertSame(100000, (int) $row['cost'], 'the upstream figure is recorded, not assumed');
        $this->assertSame(130000, (int) $row['price'], 'an unconfigured site still sells above cost');
        $this->assertSame(-130000, Ledger::balance($customer)['available'], 'the debit is the price, not the cost');
    }

    /**
     * A usage-specific markup overrides the global one.
     *
     * The option is written through `update_option` rather than
     * `Options::set()` because `usage_markup_percent` is absent from
     * `Options::DEFAULTS`, whose whitelist silently drops unknown keys — so
     * today the override is readable but not settable. That gap belongs to
     * `src/Support/Options.php`; this test pins the resolution ORDER, which is
     * the part `UsageSync` owns.
     */
    public function test_a_usage_specific_markup_overrides_the_global_one(): void
    {
        $customer = $this->customer();
        Options::set('global_markup', 30.0);
        $settings = (array) get_option('arvrs_settings', []);
        $settings['usage_markup_percent'] = 50.0;
        update_option('arvrs_settings', $settings);
        UsageSync::flush_markup();

        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);
        UsageSync::ingest($service_id, $customer, $this->usage_row('demo-1', 2, 100000));

        $this->assertSame(150000, (int) $this->db->get_var($this->db->prepare(
            'SELECT price FROM ' . UsageSync::table() . ' WHERE service_id = %d', $service_id
        )));
    }

    public function test_the_same_period_can_never_be_billed_twice(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);
        $row = $this->usage_row('demo-1', 2, 100000);

        $first  = UsageSync::ingest($service_id, $customer, $row);
        $second = UsageSync::ingest($service_id, $customer, $row);

        $this->assertSame(1, $first['ingested']);
        $this->assertSame(0, $second['ingested'], 'UNIQUE(service_id, period_start, period_end) absorbs the replay');
        $this->assertSame(1, $this->count_rows('usage_records'));
        $this->assertSame(1, $this->count_rows('ledger', "type = 'usage_debit'"));
    }

    /**
     * The crash-recovery back-fill: a prior run wrote the usage row and died
     * before the ledger debit. Re-ingesting must find the missing debit and
     * write it, or the usage is simply never charged.
     */
    public function test_a_usage_row_whose_debit_was_lost_is_back_filled_on_the_next_run(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);
        $row = $this->usage_row('demo-1', 2, 100000);

        UsageSync::record($service_id, $customer, $row->period_start, $row->period_end, 1.0, 'hour', 100000, 120000, 'provider');
        $this->assertSame(0, $this->count_rows('ledger'));

        $result = UsageSync::ingest($service_id, $customer, $row);
        $this->assertSame(0, $result['ingested'], 'the usage row was already there');
        $this->assertSame(1, $result['debited'], 'but the money was not, so it lands now');
        $this->assertSame(1, $this->count_rows('ledger', "type = 'usage_debit'"));
    }

    /* ------------------------------------------------------- credit ladder */

    public function test_the_stage_walks_the_whole_ladder_as_the_balance_falls(): void
    {
        $customer = $this->customer();
        Options::set('policy_warning', 500000);
        Options::set('policy_critical', 100000);

        Ledger::append($customer, 'topup', 5000000, 'topup', 'T1', 'x');
        $this->assertSame(PolicyEngine::HEALTHY, UsageSync::stage_for($customer));

        Ledger::append($customer, 'adjustment', 4600000, 'admin', 'D1', 'x'); // → 400,000
        Ledger::flush_cache($customer);
        $this->assertSame(PolicyEngine::WARNING, UsageSync::stage_for($customer));

        Ledger::append($customer, 'adjustment', 350000, 'admin', 'D2', 'x'); // → 50,000
        Ledger::flush_cache($customer);
        $this->assertSame(PolicyEngine::CRITICAL, UsageSync::stage_for($customer));

        Ledger::append($customer, 'adjustment', 50000, 'admin', 'D3', 'x'); // → 0
        Ledger::flush_cache($customer);
        $this->assertSame(PolicyEngine::GRACE, UsageSync::stage_for($customer), 'a zero balance is inside grace, not restricted');
    }

    /**
     * RESTRICTED needs a debt older than the grace window. That is the stage
     * `negative_since_days` used to make unreachable by measuring the newest
     * debit instead of the crossing point.
     */
    public function test_an_aged_debt_reaches_restricted_and_suspends_the_service(): void
    {
        $customer = $this->customer();
        Options::set('policy_grace_days', 3);
        Options::set('policy_actions', ['notify_customer', 'block_purchases', 'suspend_service']);
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $this->db->insert(Ledger::table(), [
            'customer_id' => $customer, 'type' => 'usage_debit', 'direction' => 'debit', 'amount' => 500000,
            'currency' => 'IRT', 'ref_type' => 'usage', 'ref_id' => 'aged', 'description' => '',
            'actor' => 'system', 'is_demo' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS),
        ]);
        Ledger::flush_cache($customer);

        $stage = UsageSync::apply_policy($customer);
        $this->assertSame(PolicyEngine::RESTRICTED, $stage);
        $this->assertSame('suspended', Services::get($service_id)['status']);
        $this->assertTrue(UsageSync::purchases_blocked($customer));

        // A partial top-up that only reaches the CRITICAL band must still lift
        // the hold — gating the lift on a healthy/warning whitelist was the bug.
        Ledger::append($customer, 'topup', 550000, 'topup', 'RESCUE', 'x');
        $stage_after = UsageSync::apply_policy($customer);
        $this->assertSame(PolicyEngine::CRITICAL, $stage_after);
        $this->assertSame('active', Services::get($service_id)['status'], 'the suspension must lift at any non-restricted stage');
        $this->assertFalse(UsageSync::purchases_blocked($customer));
    }

    /** A steady stage must not re-notify on every hourly run. */
    public function test_a_stage_that_has_not_worsened_does_not_notify_again(): void
    {
        $customer = $this->customer();
        Options::set('policy_actions', ['notify_customer', 'notify_admin']);
        Ledger::append($customer, 'topup', 400000, 'topup', 'T1', 'x'); // WARNING band

        UsageSync::apply_policy($customer);
        $after_first = $this->count_rows('notifications');
        UsageSync::apply_policy($customer);

        $this->assertSame(1, $this->count_rows('notifications', "customer_id = " . $customer . " AND type = 'low_balance'"));
        $this->assertSame($after_first, $this->count_rows('notifications'), 'an hourly job must not send an hourly email');
    }

    /** A per-customer grace override beats the global default. */
    public function test_a_customer_specific_grace_window_is_honoured(): void
    {
        $customer = $this->customer();
        Options::set('policy_grace_days', 3);
        Rules::save($customer, ['grace_days' => 30, 'status' => 'active']);

        $this->db->insert(Ledger::table(), [
            'customer_id' => $customer, 'type' => 'usage_debit', 'direction' => 'debit', 'amount' => 500000,
            'currency' => 'IRT', 'ref_type' => 'usage', 'ref_id' => 'aged', 'description' => '',
            'actor' => 'system', 'is_demo' => 1,
            'created_at' => gmdate('Y-m-d H:i:s', time() - 6 * DAY_IN_SECONDS),
        ]);
        Ledger::flush_cache($customer);

        $this->assertSame(PolicyEngine::GRACE, UsageSync::stage_for($customer), '6 days into a 30-day grace is not restricted');
    }

    /* ------------------------------------------------------ checkout gates */

    public function test_the_spending_limit_blocks_an_over_limit_purchase(): void
    {
        $customer = $this->customer();
        BaseCosts::set('cloud_server', 'g1-1-1-25', 1000000, 'test');
        Rules::save($customer, ['spending_limit' => 500000, 'status' => 'active']);

        $order = \ArvanReseller\Orders\OrderService::create($customer, 'cloud_server', 'g1-1-1-25', [
            'region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04',
        ]);

        $this->assertTrue(is_wp_error($order));
        $this->assertSame('spending_limit', $order->get_error_code());
        $this->assertSame(0, $this->count_rows('orders'), 'a blocked purchase must not leave a draft behind');
    }

    /** Orders settle through the gateway, so credit_limit must not gate them. */
    public function test_a_credit_limit_alone_does_not_block_a_gateway_order(): void
    {
        $customer = $this->customer();
        BaseCosts::set('cloud_server', 'g1-1-1-25', 1000000, 'test');
        Rules::save($customer, ['credit_limit' => 0, 'spending_limit' => '', 'status' => 'active']);

        $order = \ArvanReseller\Orders\OrderService::create($customer, 'cloud_server', 'g1-1-1-25', [
            'region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04',
        ]);

        $this->assertIsArray($order);
        $this->assertSame(1200000, (int) $order['amount'], 'base 1,000,000 × the 20% default markup');
    }

    public function test_a_config_the_catalog_does_not_offer_is_refused(): void
    {
        $customer = $this->customer();
        BaseCosts::set('cloud_server', 'g1-1-1-25', 1000000, 'test');

        $order = \ArvanReseller\Orders\OrderService::create($customer, 'cloud_server', 'g1-1-1-25', [
            'region' => 'somewhere-else', 'image' => 'ubuntu-24.04',
        ]);
        $this->assertTrue(is_wp_error($order), 'paying first and finding out the region is wrong second is the failure mode');
        $this->assertSame('bad_config', $order->get_error_code());
    }

    public function test_an_unpriced_plan_cannot_be_sold(): void
    {
        $customer = $this->customer();
        $order = \ArvanReseller\Orders\OrderService::create($customer, 'cloud_server', 'g1-1-1-25', [
            'region' => 'ir-thr-simin', 'image' => 'ubuntu-24.04',
        ]);
        $this->assertTrue(is_wp_error($order));
        $this->assertSame('unpriced', $order->get_error_code());
    }

    /**
     * CDN/object storage use the customer-supplied domain/bucket as the
     * provider's reconciliation key (RealProvider adopts whatever already
     * exists under that name). Without this check a second customer ordering
     * a domain someone else already has live would have their order routed
     * onto — and later able to delete — the first customer's resource.
     */
    public function test_a_domain_already_live_for_another_customer_cannot_be_ordered(): void
    {
        $alice = $this->customer(711);
        $bob   = $this->customer(712);
        BaseCosts::set('cdn', 'cdn-basic', 100000, 'test');

        [$alice_order] = $this->seed_order($alice, 120000, 'active', ['product' => 'cdn', 'plan_id' => 'cdn-basic']);
        $this->db->insert($this->db->prefix . 'arvrs_services', [
            'order_id' => $alice_order, 'customer_id' => $alice, 'credential_id' => null,
            'product' => 'cdn', 'plan_id' => 'cdn-basic', 'remote_id' => 'alice.example.com',
            'status' => 'active', 'config' => '{}', 'connection' => '{}',
            'renews_at' => null, 'term_days' => 30, 'renewal_price' => 120000, 'renewal_count' => 0,
            'is_demo' => 1, 'created_at' => \ArvanReseller\Support\Helpers::now(), 'updated_at' => \ArvanReseller\Support\Helpers::now(),
        ]);

        $order = \ArvanReseller\Orders\OrderService::create($bob, 'cdn', 'cdn-basic', ['domain' => 'alice.example.com']);

        $this->assertTrue(is_wp_error($order));
        $this->assertSame('name_taken', $order->get_error_code());
    }

    /** The owner ordering their own already-live domain again must not be refused. */
    public function test_the_same_customers_own_domain_can_still_be_ordered(): void
    {
        $alice = $this->customer(711);
        BaseCosts::set('cdn', 'cdn-basic', 100000, 'test');

        [$order_id] = $this->seed_order($alice, 120000, 'active', ['product' => 'cdn', 'plan_id' => 'cdn-basic']);
        $this->db->insert($this->db->prefix . 'arvrs_services', [
            'order_id' => $order_id, 'customer_id' => $alice, 'credential_id' => null,
            'product' => 'cdn', 'plan_id' => 'cdn-basic', 'remote_id' => 'alice.example.com',
            'status' => 'active', 'config' => '{}', 'connection' => '{}',
            'renews_at' => null, 'term_days' => 30, 'renewal_price' => 120000, 'renewal_count' => 0,
            'is_demo' => 1, 'created_at' => \ArvanReseller\Support\Helpers::now(), 'updated_at' => \ArvanReseller\Support\Helpers::now(),
        ]);

        $order = \ArvanReseller\Orders\OrderService::create($alice, 'cdn', 'cdn-basic', ['domain' => 'alice.example.com']);

        $this->assertIsArray($order, 'the owner is not a hijacker of their own resource');
    }
}
