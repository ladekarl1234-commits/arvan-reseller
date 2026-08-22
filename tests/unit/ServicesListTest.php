<?php
/**
 * `Services::list()` / `Services::count()`.
 *
 * The admin services screen used to fetch one page of 20 rows ordered by id
 * DESC and filter the status client-side in PHP — so a status filter only
 * ever checked whatever happened to be on that one page. On a site with more
 * than 20 services, clicking a status with matches outside that window
 * reported a false "nothing found" and pagination broke alongside it, because
 * `count($filtered) === 20` almost never held once the array had been
 * filtered down.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Services\Services;

final class ServicesListTest extends Arvrs_DbTestCase
{
    public function test_a_status_filter_finds_a_match_outside_the_first_page(): void
    {
        $customer = $this->customer();
        // 25 active services, newest first, so the one suspended row below is
        // pushed past a 20-row page ordered by id DESC.
        for ($i = 0; $i < 25; $i++) {
            [$order_id] = $this->seed_order($customer, 100000, 'active');
            $this->seed_service($customer, $order_id);
        }
        [$order_id] = $this->seed_order($customer, 100000, 'active');
        $suspended = $this->seed_service($customer, $order_id, ['status' => 'suspended']);

        $page1 = Services::list(0, 1, 20, 'suspended');
        $this->assertSame(1, Services::count(0, 'suspended'), 'the SQL-level count must find the match');
        $this->assertCount(1, $page1, 'the SQL-level filter must find it too, not just the count');
        $this->assertSame($suspended, (int) $page1[0]['id']);
    }

    public function test_search_matches_remote_id_or_numeric_id(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 100000, 'active');
        $service_id = $this->seed_service($customer, $order_id, ['remote_id' => 'srv-3f2a']);

        $by_remote = Services::list(0, 1, 20, '', 'srv-3f2a');
        $by_id     = Services::list(0, 1, 20, '', (string) $service_id);

        $this->assertCount(1, $by_remote);
        $this->assertSame($service_id, (int) $by_remote[0]['id']);
        $this->assertCount(1, $by_id);
        $this->assertSame($service_id, (int) $by_id[0]['id']);
    }

    public function test_search_matches_a_customers_email(): void
    {
        $customer = $this->customer(950);
        [$order_id] = $this->seed_order($customer, 100000, 'active');
        $service_id = $this->seed_service($customer, $order_id);

        $found = Services::list(0, 1, 20, '', 'c950@example.test');

        $this->assertCount(1, $found);
        $this->assertSame($service_id, (int) $found[0]['id']);
    }

    public function test_an_unknown_email_search_returns_nothing_rather_than_everything(): void
    {
        $customer = $this->customer();
        [$order_id] = $this->seed_order($customer, 100000, 'active');
        $this->seed_service($customer, $order_id);

        $this->assertSame([], Services::list(0, 1, 20, '', 'nobody@example.test'));
        $this->assertSame(0, Services::count(0, '', 'nobody@example.test'));
    }
}
