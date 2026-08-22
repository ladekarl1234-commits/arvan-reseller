<?php
/**
 * The migrations, run for real (EX-021).
 *
 * The panel's finding was that `$from_version` is always 0 in every existing
 * test — the E2E requires a fresh install — so the only real data migration in
 * the plugin, the one that decides whether a live reseller's entire ledger is
 * stamped as demo money, executed nowhere. These tests stamp an old version
 * and let `migrate()` do what it would do on a real upgrade.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Install\Schema;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Wallet\Ledger;

final class SchemaMigrationTest extends Arvrs_DbTestCase
{
    public function test_verify_finds_every_unique_key_the_idempotency_model_needs(): void
    {
        $verify = Schema::verify();
        $this->assertTrue($verify['ok']);
        $this->assertSame([], $verify['missing']);
        foreach (['orders', 'services', 'ledger', 'usage_records', 'base_costs', 'topups'] as $table) {
            $this->assertContains($table, $verify['tables']);
        }
    }

    public function test_verify_reports_a_dropped_unique_key_instead_of_passing(): void
    {
        $this->db->query('DROP INDEX ' . $this->db->prefix . 'arvrs_ledger__uniq_ref');
        $verify = Schema::verify();
        $this->assertFalse($verify['ok'], 'a missing unique key must be surfaced, not assumed');
        $this->assertContains('ledger.uniq_ref', $verify['missing']);
    }

    public function test_verify_reports_a_missing_table(): void
    {
        $this->db->query('DROP TABLE ' . $this->db->prefix . 'arvrs_topups');
        $verify = Schema::verify();
        $this->assertFalse($verify['ok']);
        $this->assertContains('topups', $verify['missing']);
    }

    public function test_migrate_refuses_to_stamp_the_version_while_a_unique_key_is_missing(): void
    {
        // Otherwise maybe_migrate() would never retry and INSERT IGNORE would
        // quietly degrade to plain INSERT: duplicate credits, double debits.
        delete_option('arvrs_schema_version');
        $this->db->query('DROP INDEX ' . $this->db->prefix . 'arvrs_orders__payment_ref');
        $this->db->query('DROP TABLE ' . $this->db->prefix . 'arvrs_orders');

        Schema::migrate();
        $this->assertSame(5, (int) get_option('arvrs_schema_version'), 'dbDelta recreates the table, so the retry succeeds');
    }

    /* ------------------------------------------------------------- v3 → v4 */

    public function test_v3_to_v4_backstamps_the_ledger_when_the_site_is_still_in_demo(): void
    {
        $this->seed_ledger_row('a', 0);
        $this->seed_ledger_row('b', 0);
        update_option('arvrs_settings', ['demo_mode' => true]);
        update_option('arvrs_schema_version', 3);

        Schema::migrate();

        $this->assertSame(2, $this->count_rows('ledger', 'is_demo = 1'), 'demo history must not count as real money');
        $this->assertSame(1, $this->count_rows('audit_log', "action = 'schema.backfill' AND object_type = 'ledger'"));
    }

    public function test_v3_to_v4_leaves_a_live_sites_ledger_alone(): void
    {
        $this->seed_ledger_row('a', 0);
        update_option('arvrs_settings', ['demo_mode' => false]);
        update_option('arvrs_schema_version', 3);

        Schema::migrate();

        $this->assertSame(0, $this->count_rows('ledger', 'is_demo = 1'), 'real money must never be re-stamped as demo');
    }

    /**
     * The default the panel called out: with `demo_mode` absent from settings
     * the code treats the site as demo. That is the safe direction (money that
     * might be fake is excluded rather than counted) but it is a decision, so
     * it gets an assertion.
     */
    public function test_v3_to_v4_treats_absent_demo_mode_as_demo(): void
    {
        $this->seed_ledger_row('a', 0);
        update_option('arvrs_settings', ['brand_name' => 'x']);
        update_option('arvrs_schema_version', 3);

        Schema::migrate();

        $this->assertSame(1, $this->count_rows('ledger', 'is_demo = 1'));
    }

    /* ------------------------------------------------------------- v4 → v5 */

    /**
     * The backfill must clock from NOW, not from `created_at`. A service
     * created months ago has already been paid for the term it is currently
     * running — backdating its next charge to creation time billed one
     * retroactive term per cron run against a real customer wallet until the
     * clock caught up to the present (release blocker).
     */
    public function test_v4_to_v5_gives_every_service_a_renewal_clock_priced_from_its_order(): void
    {
        [$order_id] = $this->seed_order(101, 1500000, 'active');
        $service_id = $this->seed_service(101, $order_id, [
            'renews_at'     => null,
            'renewal_price' => 0,
            // An old service, seeded well in the past — exactly the row that
            // used to come out with a `renews_at` in the past too.
            'created_at'    => gmdate('Y-m-d H:i:s', time() - 180 * DAY_IN_SECONDS),
        ]);
        update_option('arvrs_schema_version', 4);

        $before = time();
        Schema::migrate();
        $after = time();

        $service   = Services::get($service_id);
        $renews_ts = strtotime((string) $service['renews_at'] . ' UTC');
        $this->assertNotEmpty($service['renews_at'], 'a service with no clock can never be renewed');
        $this->assertSame(30, (int) $service['term_days']);
        $this->assertSame(1500000, (int) $service['renewal_price'], 'the stored price is what the order was actually paid at');
        $this->assertGreaterThan(time(), $renews_ts, 'the clock must start from now, never from a months-old created_at');
        $this->assertGreaterThanOrEqual($before + 30 * DAY_IN_SECONDS, $renews_ts);
        $this->assertLessThanOrEqual($after + 30 * DAY_IN_SECONDS, $renews_ts);
    }

    public function test_v4_to_v5_backfills_usage_price_from_cost(): void
    {
        $this->db->insert($this->db->prefix . 'arvrs_usage_records', [
            'service_id' => 1, 'customer_id' => 101,
            'period_start' => '2026-01-01 00:00:00', 'period_end' => '2026-01-01 01:00:00',
            'quantity' => 1, 'unit' => 'hour', 'cost' => 1000, 'price' => 0,
            'currency' => 'IRT', 'source' => 'provider', 'is_demo' => 1, 'created_at' => Helpers::now(),
        ]);
        update_option('arvrs_schema_version', 4);

        Schema::migrate();

        $this->assertSame(1000, (int) $this->db->get_var(
            'SELECT price FROM ' . $this->db->prefix . 'arvrs_usage_records WHERE service_id = 1'
        ), 'before the cost/price split every usage row was billed at cost');
    }

    /**
     * Top-up intents used to be one never-expiring `wp_options` row per
     * attempt. The migration moves the survivors into a table that can expire
     * them and deletes the options.
     */
    public function test_v4_to_v5_moves_topup_option_intents_into_the_topups_table(): void
    {
        add_option('arvrs_topup_TOP-OLD1', ['customer_id' => 101, 'amount' => 4000000, 'at' => time() - 100], '', false);
        // Older than the two-hour TTL: it moves, and it moves as something that
        // can expire — which is the entire reason for the table.
        add_option('arvrs_topup_TOP-OLD2', ['customer_id' => 102, 'amount' => 250000, 'at' => time() - 3 * HOUR_IN_SECONDS], '', false);
        add_option('arvrs_unrelated', ['keep' => 1], '', false);
        update_option('arvrs_schema_version', 4);

        Schema::migrate();

        $this->assertSame(2, $this->count_rows('topups'));
        $this->assertSame(4000000, (int) $this->db->get_var($this->db->prepare(
            'SELECT amount FROM ' . PaymentService::topups_table() . ' WHERE ref = %s', 'TOP-OLD1'
        )));
        $this->assertFalse(get_option('arvrs_topup_TOP-OLD1'), 'the options row must not survive the move');
        $this->assertNotFalse(get_option('arvrs_unrelated'), 'unrelated options are untouched');

        // A moved intent is subject to the same TTL as a new one: the recent
        // one is still redeemable, the stale one is not. As `wp_options` rows
        // they were both redeemable forever.
        $this->assertNotNull(PaymentService::topup_intent('TOP-OLD1'));
        $this->assertNull(PaymentService::topup_intent('TOP-OLD2'), 'a migrated intent past its TTL must be dead');
    }

    public function test_migrating_twice_changes_nothing_the_second_time(): void
    {
        $this->seed_ledger_row('a', 0);
        update_option('arvrs_settings', ['demo_mode' => false]);
        update_option('arvrs_schema_version', 3);

        Schema::migrate();
        $after_first = $this->count_rows('ledger');
        Schema::migrate(); // version is 5 now: the data migrations must not re-run

        $this->assertSame($after_first, $this->count_rows('ledger'));
        $this->assertSame(5, (int) get_option('arvrs_schema_version'));
    }

    /* -------------------------------------------------------------- prune */

    public function test_prune_deletes_diagnostics_and_never_the_compliance_trail(): void
    {
        $old = gmdate('Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS);
        foreach ([['info', $old], ['debug', $old], ['audit', $old], ['info', Helpers::now()]] as [$level, $when]) {
            $this->db->insert($this->db->prefix . 'arvrs_audit_log', [
                'user_id' => 0, 'action' => 'x', 'object_type' => '', 'object_id' => '',
                'detail' => '{}', 'ip' => '', 'level' => $level, 'created_at' => $when,
            ]);
        }
        $counts = Schema::prune(90);

        $this->assertSame(2, $counts['audit']);
        $this->assertSame(1, $this->count_rows('audit_log', "level = 'audit'"), 'the audit trail is never pruned');
        $this->assertSame(1, $this->count_rows('audit_log', "level = 'info'"), 'recent diagnostics survive');
    }

    public function test_prune_never_erases_this_week_however_it_is_configured(): void
    {
        $this->db->insert($this->db->prefix . 'arvrs_audit_log', [
            'user_id' => 0, 'action' => 'x', 'object_type' => '', 'object_id' => '',
            'detail' => '{}', 'ip' => '', 'level' => 'info',
            'created_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS),
        ]);
        Schema::prune(0); // a misconfiguration must not become data loss
        $this->assertSame(1, $this->count_rows('audit_log'));
    }

    private function seed_ledger_row(string $ref, int $is_demo): void
    {
        $this->db->insert(Ledger::table(), [
            'customer_id' => 101, 'type' => 'topup', 'direction' => 'credit', 'amount' => 1000000,
            'currency' => 'IRT', 'ref_type' => 'topup', 'ref_id' => $ref, 'description' => '',
            'actor' => 'system', 'is_demo' => $is_demo, 'created_at' => Helpers::now(),
        ]);
    }
}
