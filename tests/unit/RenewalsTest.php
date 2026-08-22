<?php
/**
 * `Billing\Renewals` — the recurring-revenue engine, and the only thing that
 * ever drives a customer's wallet downward in production.
 *
 * Its three idempotency layers all get their own test, and the third — the
 * `UPDATE … WHERE renews_at = <old>` clock advance — is the one that decides
 * whether two cron runners double-charge (EX-073). It is driven the only way
 * PHPUnit can: the same charge twice, and the row mutated between attempts so
 * the predicate is provably what rejected the loser.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Billing\Renewals;
use ArvanReseller\Policies\PolicyEngine;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Wallet\Ledger;

final class RenewalsTest extends Arvrs_DbTestCase
{
    public function test_a_due_service_is_charged_once_and_its_clock_advances(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-R1', 'x');
        BaseCosts::set('cloud_server', 'g1-1-1-25', 800000, 'test');

        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $due_at     = gmdate('Y-m-d H:i:s', time() - 3600);
        $service_id = $this->seed_service($customer, $order_id, ['renews_at' => $due_at]);

        $result = Renewals::charge($service_id);

        $this->assertTrue($result['ok']);
        $this->assertSame('charged', $result['kind']);
        $this->assertSame(1200000, $result['charged']);
        $this->assertSame(8800000, Ledger::balance($customer)['available']);

        $service = Services::get($service_id);
        $this->assertSame(1, (int) $service['renewal_count']);
        $this->assertSame(
            gmdate('Y-m-d H:i:s', strtotime($due_at . ' UTC') + 30 * DAY_IN_SECONDS),
            (string) $service['renews_at']
        );

        // Both cost and price are recorded, so recurring margin is a fact and
        // not an assumption.
        $usage = $this->db->get_row($this->db->prepare(
            'SELECT * FROM ' . $this->db->prefix . 'arvrs_usage_records WHERE service_id = %d', $service_id
        ), ARRAY_A);
        $this->assertSame(800000, (int) $usage['cost']);
        $this->assertSame(1200000, (int) $usage['price']);
        $this->assertSame('renewal', (string) $usage['source']);
        $this->assertSame('term', (string) $usage['unit']);
    }

    /**
     * Two runners, one charge. The second sees a clock that has already moved,
     * so its ledger key is a replay and its UPDATE matches nothing.
     */
    public function test_charging_the_same_term_twice_debits_the_customer_once(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-R2', 'x');
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $first  = Renewals::charge($service_id);
        $second = Renewals::charge($service_id);

        $this->assertSame('charged', $first['kind']);
        $this->assertSame('not_due', $second['kind'], 'the advanced clock is the first thing that stops a re-run');
        $this->assertSame(8800000, Ledger::balance($customer)['available']);
        $this->assertSame(1, $this->count_rows('ledger', "ref_type = 'renewal'"));
        $this->assertSame(1, (int) Services::get($service_id)['renewal_count']);
    }

    /**
     * The crash-recovery case, and the one that really exercises layer 2+3: a
     * previous run wrote the ledger entry and died before advancing the clock.
     * Re-running must recognise the replay, advance the clock anyway, and debit
     * nothing further.
     */
    public function test_a_run_that_died_after_the_ledger_write_replays_and_still_advances_the_clock(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-R3', 'x');
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $due_at     = gmdate('Y-m-d H:i:s', time() - 3600);
        $service_id = $this->seed_service($customer, $order_id, ['renews_at' => $due_at]);

        // Exactly what the dead run left behind: the entry on this period's key.
        Ledger::append($customer, 'service_charge', 1200000, 'renewal', $service_id . ':' . $due_at, 'تمدید');
        $balance_after_crash = Ledger::balance($customer)['available'];

        $result = Renewals::charge($service_id);

        $this->assertTrue($result['ok']);
        $this->assertSame('replay', $result['kind']);
        $this->assertSame(0, $result['charged']);
        $this->assertSame($balance_after_crash, Ledger::balance($customer)['available'], 'a replay must not debit again');
        $this->assertSame(1, $this->count_rows('ledger', "ref_type = 'renewal'"));
        $this->assertNotSame($due_at, (string) Services::get($service_id)['renews_at'], 'the stuck clock must still move on');
    }

    /**
     * The clock guard proven by mutation: move `renews_at` out from under the
     * charge and the advance must fail — that failing UPDATE is what makes the
     * losing runner harmless.
     */
    public function test_advance_renewal_only_matches_the_clock_it_read(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $due_at     = gmdate('Y-m-d H:i:s', time() - 3600);
        $service_id = $this->seed_service($customer, $order_id, ['renews_at' => $due_at]);
        $next       = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);

        $this->assertTrue(Services::advance_renewal($service_id, $due_at, $next), 'the runner that read the current clock wins');
        $this->assertFalse(Services::advance_renewal($service_id, $due_at, $next), 'the runner holding a stale clock must lose');
        $this->assertSame(1, (int) Services::get($service_id)['renewal_count'], 'renewal_count is incremented exactly once');
    }

    public function test_a_service_not_yet_due_is_not_charged(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id, [
            'renews_at' => gmdate('Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS),
        ]);

        $this->assertSame('not_due', Renewals::charge($service_id)['kind']);
        $this->assertSame(0, $this->count_rows('ledger'));
    }

    public function test_a_cancelled_renewal_never_charges_again(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $this->assertTrue(Renewals::cancel($service_id, 'customer'));
        $this->assertSame('cancelled', Renewals::charge($service_id)['kind']);
        $this->assertSame(0, $this->count_rows('ledger'));
        $this->assertSame(1, $this->count_rows('notifications', "type = 'renewal_cancelled'"));
        $this->assertFalse(Renewals::cancel($service_id, 'customer'), 'cancelling twice changes nothing');
    }

    /**
     * A suspended service is still running upstream and still costing the
     * reseller, so the hold must not become a free ride.
     */
    public function test_a_suspended_service_still_renews(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['status' => 'suspended']);

        $this->assertCount(1, Renewals::due(50), 'a suspended service is in the due batch');
        $this->assertSame('charged', Renewals::charge($service_id)['kind']);
    }

    public function test_renewing_at_zero_is_refused_and_the_admin_is_told(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 0, 'active', ['amount' => 0]);
        $service_id = $this->seed_service($customer, $order_id, ['renewal_price' => 0]);

        $result = Renewals::charge($service_id);
        $this->assertSame('no_price', $result['kind']);
        $this->assertSame(0, $this->count_rows('ledger'), 'a service must never be given away silently');
        $this->assertSame(1, $this->count_rows('notifications', "customer_id = 0 AND type = 'renewal_no_price'"));
    }

    /** With no `renewal_price` the term falls back to what the order was paid at. */
    public function test_price_falls_back_to_the_original_order_amount(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-R4', 'x');
        [$order_id] = $this->seed_order($customer, 990000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['renewal_price' => 0]);

        $this->assertSame(990000, Renewals::charge($service_id)['charged']);
    }

    public function test_run_due_reports_what_it_did_and_records_the_run(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-R5', 'x');
        [$order_a] = $this->seed_order($customer, 1200000, 'active');
        [$order_b] = $this->seed_order($customer, 1200000, 'active');
        $this->seed_service($customer, $order_a);
        $this->seed_service($customer, $order_b);

        $stats = Renewals::run_due();
        $this->assertSame(2, $stats['due']);
        $this->assertSame(2, $stats['charged']);
        $this->assertSame(2400000, $stats['amount']);
        $this->assertSame(0, $stats['errors']);
        $this->assertNotSame('', Renewals::last_run()['at'], 'an empty or busy run must both be visible');

        $again = Renewals::run_due();
        $this->assertSame(0, $again['due'], 'nothing is due once the clocks have moved');
    }

    /** Charging into an empty wallet must move the customer down the credit ladder. */
    public function test_a_charge_that_empties_the_wallet_restages_the_customer(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 1200000, 'topup', 'TOP-R6', 'x');
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $result = Renewals::charge($service_id);
        $this->assertSame(0, Ledger::balance($customer)['available']);
        $this->assertSame(PolicyEngine::GRACE, $result['stage'], 'a zero balance is inside the grace window, not healthy');
    }

    public function test_reminders_go_out_once_per_term(): void
    {
        $customer = $this->customer();
        // Deliberately NOT the constant's default (5): if Options::set() were
        // still the silent no-op it used to be (`renewal_reminder_days` was
        // absent from Options::DEFAULTS), this would coincidentally pass at 5
        // regardless of what was "saved" — asserting window_days below is what
        // actually proves the write took.
        Options::set('renewal_reminder_days', 7);
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $this->seed_service($customer, $order_id, [
            'renews_at' => gmdate('Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS),
        ]);

        $first = Renewals::remind();
        $this->assertSame(7, $first['window_days'], 'Options::set() must not be a silent no-op');
        $this->assertSame(1, $first['found']);
        $this->assertSame(1, $first['notified']);

        $second = Renewals::remind();
        $this->assertSame(0, $second['notified'], 'a daily job must not remind daily');
        $this->assertSame(1, $this->count_rows('notifications', "type = 'renewal_reminder'"));
    }

    public function test_mrr_normalises_terms_to_thirty_days(): void
    {
        $customer = $this->customer();
        [$order_a] = $this->seed_order($customer, 1200000, 'active');
        [$order_b] = $this->seed_order($customer, 600000, 'active');
        $this->seed_service($customer, $order_a, ['renewal_price' => 1200000, 'term_days' => 30, 'is_demo' => 0]);
        $this->seed_service($customer, $order_b, ['renewal_price' => 600000, 'term_days' => 60, 'is_demo' => 0]);

        // 1,200,000 per 30 days + 600,000 per 60 days = 1,500,000 per 30 days.
        $this->assertSame(1500000, Renewals::mrr(false));
    }

    public function test_due_never_returns_a_cancelled_service(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id);
        Services::terminate($service_id);

        $this->assertSame([], Renewals::due(50));
        $this->assertSame('cancelled', Renewals::charge($service_id)['kind']);
    }

    /** The `after_id` cursor is honoured: a run seeded past the first due service charges only the rest. */
    public function test_run_due_honours_a_resume_cursor(): void
    {
        $customer = $this->customer();
        Ledger::append($customer, 'topup', 10000000, 'topup', 'TOP-PAGE', 'x');
        [$order_a] = $this->seed_order($customer, 100000, 'active');
        [$order_b] = $this->seed_order($customer, 100000, 'active');
        $service_a = $this->seed_service($customer, $order_a);
        $service_b = $this->seed_service($customer, $order_b);

        $stats = Renewals::run_due(['after_id' => $service_a]);

        $this->assertSame(1, $stats['due'], 'the cursor must skip the service at/below it');
        $this->assertSame(1, $stats['charged']);
        $this->assertSame(0, $this->count_rows('ledger', "ref_type = 'renewal' AND ref_id LIKE '" . $service_a . ":%'"), 'the service at the cursor must not have been charged');
        $this->assertGreaterThan(0, $this->count_rows('ledger', "ref_type = 'renewal' AND ref_id LIKE '" . $service_b . ":%'"));
    }

    /**
     * `Services::due_for_renewal()` is cursor-paged on id like
     * `active_for_sync()` — the same population, split across two bounded
     * pages, must add up to the same total `run_due()` sees in one call. This
     * is the SQL-level guarantee `run_due()`'s wall-clock requeue loop
     * depends on (a real budget timeout is not something a fast unit test can
     * trigger deterministically, so this pins the piece that IS deterministic:
     * the cursor never skips or repeats a row across pages).
     */
    public function test_due_for_renewal_pages_the_same_population_the_cursor_walks(): void
    {
        $customer = $this->customer();
        [$order_a] = $this->seed_order($customer, 100000, 'active');
        [$order_b] = $this->seed_order($customer, 100000, 'active');
        [$order_c] = $this->seed_order($customer, 100000, 'active');
        $this->seed_service($customer, $order_a);
        $this->seed_service($customer, $order_b);
        $this->seed_service($customer, $order_c);

        $whole = Renewals::due(50);
        $this->assertCount(3, $whole);

        $first_page  = Renewals::due(2, 0);
        $second_page = Renewals::due(2, (int) $first_page[1]['id']);
        $this->assertCount(2, $first_page);
        $this->assertCount(1, $second_page);
        $this->assertSame(
            array_column($whole, 'id'),
            array_merge(array_column($first_page, 'id'), array_column($second_page, 'id')),
            'paging must neither skip nor repeat a row the single call also found'
        );
    }

    /** Demo services must never renew as real money once the site is live. */
    public function test_a_demo_service_is_excluded_from_the_due_batch_once_live(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['is_demo' => 1]);

        $this->assertCount(1, Renewals::due(50), 'still due while the site is in demo mode');

        $this->go_live();
        $this->assertSame([], Renewals::due(50), 'a demo service must not renew as real money once live');
    }

    /**
     * Charging a demo service directly (e.g. an operator forcing it) must
     * still stamp the resulting usage row and ledger entry as demo — from the
     * SERVICE's own flag, never from the ambient mode, which by this point is
     * real.
     */
    public function test_a_demo_services_renewal_charge_is_stamped_demo_even_once_live(): void
    {
        $customer = $this->customer();
        BaseCosts::set('cloud_server', 'g1-1-1-25', 800000, 'test');
        [$order_id] = $this->seed_order($customer, 1200000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['is_demo' => 1]);
        $this->go_live();

        $result = Renewals::charge($service_id);

        $this->assertSame('charged', $result['kind']);
        $usage = $this->db->get_row($this->db->prepare(
            'SELECT is_demo FROM ' . $this->db->prefix . 'arvrs_usage_records WHERE service_id = %d', $service_id
        ), ARRAY_A);
        $this->assertSame(1, (int) $usage['is_demo']);
        $this->assertSame(1, $this->count_rows('ledger', "ref_type = 'renewal' AND is_demo = 1"));
    }
}
