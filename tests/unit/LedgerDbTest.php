<?php
/**
 * The ledger against a real schema and a real unique key.
 *
 * `LedgerDerivationTest` covers the pure arithmetic. Everything here is about
 * the part the panel found untested (EX-019, EX-072, EX-078): that replay
 * safety is enforced by `UNIQUE KEY uniq_ref (ref_type, ref_id, type)` and not
 * by a status check upstream, and that `append()` tells a duplicate apart from
 * a swallowed write.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Install\Schema;
use ArvanReseller\Wallet\Ledger;

final class LedgerDbTest extends Arvrs_DbTestCase
{
    public function test_schema_actually_carries_the_unique_key_replay_safety_rests_on(): void
    {
        $verify = Schema::verify();
        $this->assertTrue($verify['ok'], 'missing: ' . implode(',', $verify['missing']));
        $this->assertContains('ledger', $verify['tables']);

        // Not just "verify says so": a plain INSERT of the same business key
        // must be rejected by the database. Drop the index from Schema.php and
        // this is the assertion that fails.
        $columns = '(customer_id, type, direction, amount, currency, ref_type, ref_id, description, actor, is_demo, created_at)';
        $values  = "(5, 'topup', 'credit', 1000, 'IRT', 'topup', 'DUP-1', '', 'system', 1, '2026-01-01 00:00:00')";
        $this->assertNotFalse($this->db->query('INSERT INTO ' . Ledger::table() . ' ' . $columns . ' VALUES ' . $values));
        $this->assertFalse(
            $this->db->query('INSERT INTO ' . Ledger::table() . ' ' . $columns . ' VALUES ' . $values),
            'a second row on the same (ref_type, ref_id, type) must be refused by the unique key'
        );
    }

    public function test_replayed_append_returns_zero_and_writes_no_second_row(): void
    {
        $first = Ledger::append(11, 'topup', 5000000, 'topup', 'TOP-A', 'شارژ');
        $this->assertGreaterThan(0, $first);

        $replay = Ledger::append(11, 'topup', 5000000, 'topup', 'TOP-A', 'شارژ');
        $this->assertSame(0, $replay, 'the unique key must absorb the replay');
        $this->assertSame(1, $this->count_rows('ledger', "ref_id = 'TOP-A'"));
        $this->assertSame(5000000, Ledger::balance(11)['available'], 'a replay must not credit twice');
    }

    /**
     * EX-019: the E2E's order replay short-circuited on the order status long
     * before reaching this guard. Driven directly, the pair of order entries
     * is idempotent on its own — which is what makes two simultaneous gateway
     * callbacks safe, since both of those read a payable status.
     */
    public function test_order_settlement_pair_is_idempotent_on_the_business_key(): void
    {
        $ref = 'ARV-REPLAY';
        $this->assertGreaterThan(0, Ledger::append(12, 'payment', 1200000, 'order', $ref, 'پرداخت'));
        $this->assertGreaterThan(0, Ledger::append(12, 'purchase', 1200000, 'order', $ref, 'خرید'));

        $this->assertSame(0, Ledger::append(12, 'payment', 1200000, 'order', $ref, 'پرداخت'));
        $this->assertSame(0, Ledger::append(12, 'purchase', 1200000, 'order', $ref, 'خرید'));

        $this->assertSame(2, $this->count_rows('ledger', "ref_id = '" . $ref . "'"));
        $this->assertSame(0, Ledger::balance(12)['available'], 'a gateway order nets to zero, replayed or not');
    }

    public function test_repair_writes_only_the_missing_half_then_nothing(): void
    {
        $ref = 'ARV-REPAIR';
        Ledger::append(13, 'payment', 900000, 'order', $ref, 'پرداخت');

        $this->assertSame(1, Ledger::repair_order_entries(13, $ref, 900000, 77), 'only the purchase leg was missing');
        $this->assertSame(0, Ledger::repair_order_entries(13, $ref, 900000, 77), 'the second repair is a no-op');
        $this->assertSame(2, $this->count_rows('ledger', "ref_id = '" . $ref . "'"));
    }

    /**
     * EX-072. MySQL's `INSERT IGNORE` downgrades value truncation and
     * out-of-range writes to warnings: zero rows affected AND an empty
     * `last_error`, indistinguishable from a replay unless the code looks on
     * disk. SQLite cannot produce that shape, so it is fabricated at the $wpdb
     * seam — the point of the test is that `append()` refuses to report a
     * dropped credit as a benign replay.
     */
    public function test_swallowed_write_is_not_reported_as_a_replay(): void
    {
        $this->db->intercept = static function (string $sql) {
            return stripos($sql, 'INSERT OR IGNORE INTO') === 0 ? 0 : null;
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/silently dropped/');
        Ledger::append(14, 'topup', 7000000, 'topup', 'TOP-SWALLOWED', 'x');
    }

    public function test_real_db_error_on_append_throws_rather_than_returning_zero(): void
    {
        $db = $this->db;
        $this->db->intercept = static function (string $sql) use ($db) {
            if (stripos($sql, 'INSERT OR IGNORE INTO') !== 0) {
                return null;
            }
            $db->last_error = 'Deadlock found when trying to get lock';
            return 0;
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Deadlock/');
        Ledger::append(15, 'topup', 7000000, 'topup', 'TOP-DEADLOCK', 'x');
    }

    public function test_balances_reads_many_customers_in_one_query(): void
    {
        foreach ([21, 22, 23] as $i => $customer) {
            Ledger::append($customer, 'topup', 1000000 * ($i + 1), 'topup', 'TOP-B' . $customer, 'x');
        }
        Ledger::flush_cache(0);
        $GLOBALS['__arvrs_cache'] = [];

        $before = $this->db->num_queries;
        $rows   = Ledger::balances([21, 22, 23, 24]);
        $after  = $this->db->num_queries;

        $this->assertSame(1, $after - $before, 'batch balances must be ONE aggregate, not one per customer');
        $this->assertSame(1000000, $rows[21]['available']);
        $this->assertSame(3000000, $rows[23]['available']);
        $this->assertSame(0, $rows[24]['available'], 'a customer with no rows comes back as a zero row, never missing');
    }

    public function test_demo_rows_stop_counting_once_the_site_goes_live(): void
    {
        Ledger::append(31, 'topup', 4000000, 'topup', 'TOP-DEMO', 'x'); // demo mode is the default
        $this->assertSame(4000000, Ledger::balance(31)['available']);

        $this->go_live();
        Ledger::flush_cache(31);
        $this->assertSame(0, Ledger::balance(31)['available'], 'demo credit must not be spendable in real mode');
        $this->assertSame([], Ledger::entries(31), 'entries() must apply the same demo rule as balance()');
        $this->assertSame(4000000, Ledger::balance(31, true)['available']);
    }

    /**
     * The finding this replaces: `negative_since_days` measured the age of the
     * newest debit, so with recurring charges it was always ~0 and
     * PolicyEngine::RESTRICTED was unreachable. It must report the crossing
     * point, and go back to null the moment a top-up repairs the balance.
     */
    public function test_negative_since_days_reports_the_crossing_point_and_clears_on_topup(): void
    {
        $this->seed_entry(41, 'topup', 'credit', 1000000, 'topup', 'old-credit', 20);
        $this->seed_entry(41, 'usage_debit', 'debit', 1500000, 'usage', 'crossing', 10);
        $this->seed_entry(41, 'usage_debit', 'debit', 100000, 'usage', 'recent', 1);
        Ledger::flush_cache(41);

        $this->assertSame(-600000, Ledger::balance(41)['available']);
        $days = Ledger::negative_since_days(41);
        $this->assertSame(10, $days, 'the clock starts at the entry that pushed the balance non-positive, not the newest debit');

        Ledger::append(41, 'topup', 2000000, 'topup', 'TOP-RESCUE', 'شارژ');
        $this->assertSame(1400000, Ledger::balance(41)['available']);
        $this->assertNull(Ledger::negative_since_days(41), 'a positive balance has no negative period');
    }

    public function test_append_rejects_an_unknown_entry_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ledger::append(51, 'steal_money', 100, 'order', 'X', '');
    }

    public function test_append_rejects_a_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Ledger::append(52, 'topup', -100, 'topup', 'NEG-1', '');
    }

    /** Direct insert so the entry's age — which `negative_since_days` walks — is controllable. */
    private function seed_entry(int $customer, string $type, string $direction, int $amount, string $ref_type, string $ref_id, int $days_ago): void
    {
        $this->db->insert(Ledger::table(), [
            'customer_id' => $customer,
            'type'        => $type,
            'direction'   => $direction,
            'amount'      => $amount,
            'currency'    => 'IRT',
            'ref_type'    => $ref_type,
            'ref_id'      => $ref_id,
            'description' => '',
            'actor'       => 'system',
            'is_demo'     => 1,
            'created_at'  => gmdate('Y-m-d H:i:s', time() - $days_ago * DAY_IN_SECONDS),
        ]);
        // Sanity: the fixture itself must have landed, or the assertions above
        // would be measuring an empty ledger.
        $this->assertSame('', (string) $this->db->last_error);
    }
}
