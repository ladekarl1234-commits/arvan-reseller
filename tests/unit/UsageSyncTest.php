<?php
/**
 * `Usage\UsageSync::sync_all()` end to end.
 *
 * Nothing exercised the full sync loop before this file: the per-service
 * watermark stamp and the demo-vs-real stamping on the rows it writes both
 * shipped with defects a 15-judge review caught by reading the code, not by
 * a failing test — a failed debit still had its service's watermark advanced
 * (the failed period slides behind `since` and is never retried, silent
 * revenue loss), and both `usage_records.is_demo` and the ledger debit were
 * stamped from the ambient plugin mode instead of the service's own flag (a
 * demo service manufactures real revenue the moment the site goes live).
 */

defined('ABSPATH') || exit;

use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

final class UsageSyncTest extends Arvrs_DbTestCase
{
    /** Register a remote id with the demo provider so usage() returns real rows for it. */
    private function register_demo_resource(string $remote_id, string $plan_id): void
    {
        $registry = (array) get_option('arvrs_demo_resources', []);
        $registry[$remote_id] = [
            'product' => 'cloud_server', 'plan_id' => $plan_id,
            'created_at' => Helpers::now(), 'status' => 'active',
        ];
        update_option('arvrs_demo_resources', $registry, false);
    }

    public function test_a_failed_debit_does_not_advance_that_services_watermark(): void
    {
        BaseCosts::set('cloud_server', 'g1-1-1-25', 720000, 'test');
        $good = $this->customer(801);
        $bad  = $this->customer(802);
        [$good_order] = $this->seed_order($good, 1200000, 'active');
        [$bad_order]  = $this->seed_order($bad, 1200000, 'active');
        $good_service = $this->seed_service($good, $good_order, ['remote_id' => 'demo-good']);
        $bad_service  = $this->seed_service($bad, $bad_order, ['remote_id' => 'demo-bad']);
        $this->register_demo_resource('demo-good', 'g1-1-1-25');
        $this->register_demo_resource('demo-bad', 'g1-1-1-25');

        // Every ledger debit for the "bad" customer fails (a stand-in for a
        // deadlock/disk-full mid-run); every other write succeeds normally.
        $db = $this->db;
        $this->db->intercept = static function (string $sql) use ($db, $bad) {
            if (stripos($sql, 'INSERT OR IGNORE INTO ' . $db->prefix . 'arvrs_ledger') !== 0) {
                return null;
            }
            if (strpos($sql, '(' . $bad . ", 'usage_debit'") === false) {
                return null;
            }
            $db->last_error = 'Lost connection to MySQL server during query';
            return 0;
        };

        $stats = UsageSync::sync_all();
        $this->db->intercept = null;

        $this->assertGreaterThan(0, $stats['errors'], 'the intercepted debit(s) must be counted as failures');
        $this->assertNotNull(Services::get($good_service)['last_synced_at'], 'a clean service is stamped as synced');
        $this->assertNull(
            Services::get($bad_service)['last_synced_at'],
            'a service with a failed debit must keep its old watermark so the same period is retried next run'
        );
        // The failed rows are still on disk with no matching debit — exactly
        // what orphaned_debits() exists to find.
        $orphans = UsageSync::orphaned_debits(50);
        $found = array_filter($orphans, static function ($row) use ($bad_service) {
            return (int) $row['service_id'] === $bad_service;
        });
        $this->assertNotEmpty($found, 'a usage row with no ledger debit must be findable');
    }

    public function test_orphaned_debits_excludes_renewal_rows_and_debited_rows(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        // A clean metered row with its debit: not an orphan.
        UsageSync::record($service_id, $customer, '2026-01-01 00:00:00', '2026-01-01 01:00:00', 1.0, 'hour', 1000, 1200, 'provider', 0);
        $clean_id = (int) $this->db->get_var($this->db->prepare(
            'SELECT id FROM ' . UsageSync::table() . " WHERE period_start = %s", '2026-01-01 00:00:00'
        ));
        Ledger::append($customer, 'usage_debit', 1200, 'usage', (string) $clean_id, 'x');

        // A metered row with NO debit: an orphan.
        UsageSync::record($service_id, $customer, '2026-01-02 00:00:00', '2026-01-02 01:00:00', 1.0, 'hour', 1000, 1200, 'provider', 0);

        // A renewal row: never considered, it debits through a different key.
        UsageSync::record($service_id, $customer, '2026-01-03 00:00:00', '2026-01-31 00:00:00', 1.0, 'term', 800000, 1200000, 'renewal', 0);

        $orphans = UsageSync::orphaned_debits(50);
        $periods = array_map(static function ($row) { return (string) $row['period_start']; }, $orphans);

        $this->assertContains('2026-01-02 00:00:00', $periods);
        $this->assertNotContains('2026-01-01 00:00:00', $periods, 'a row with its debit is not an orphan');
        $this->assertNotContains('2026-01-03 00:00:00', $periods, 'renewal rows debit through ref_type=renewal, not this check');
    }

    /**
     * A demo service must stay demo-stamped forever, even after the site
     * leaves demo mode — the row is a permanent fact about the service, not
     * about the request that happened to write it.
     */
    public function test_a_demo_services_usage_is_stamped_demo_even_once_the_site_is_live(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['is_demo' => 1]);

        // go_live() also swaps DemoProvider for RealProvider, which is not
        // what this test is about — it exercises ingest()'s stamping directly,
        // the same call sync_page() makes, with the site genuinely out of
        // demo mode so an ambient-mode read would get this wrong.
        $this->go_live();
        $row = new \ArvanReseller\Arvan\UsageRow('demo-x', '2026-01-01 00:00:00', '2026-01-01 01:00:00', 1.0, 'hour', 1000);
        UsageSync::ingest($service_id, $customer, $row, 'provider', 1);

        $usage = $this->db->get_row($this->db->prepare(
            'SELECT is_demo FROM ' . UsageSync::table() . ' WHERE service_id = %d', $service_id
        ), ARRAY_A);
        $this->assertSame(1, (int) $usage['is_demo'], 'a demo service must not manufacture real revenue once live');
        $this->assertSame(
            1,
            $this->count_rows('ledger', "ref_type = 'usage' AND is_demo = 1"),
            'the debit itself must be stamped demo, not read from the (now real) ambient mode'
        );
    }
}
