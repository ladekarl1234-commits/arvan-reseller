<?php
/**
 * `Admin\Actions` handlers that were reachable in production but had zero
 * test coverage of their own — the 15-judge review's findings on
 * `credential_delete()` (a refused delete flashed success) and
 * `order_action('refund')` (a duplicated `Ledger::append()` bypassed the
 * settlement guard `PaymentService::refund_order()` exists for) both shipped
 * because nothing here exercised the admin-post handlers directly.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Admin\Actions;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Crypto;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Wallet\Ledger;

final class AdminActionsTest extends Arvrs_DbTestCase
{
    private function as_admin(int $id = 900): int
    {
        $admin = $this->customer($id);
        arvrs_test_grant($admin, ['manage_options']);
        wp_set_current_user($admin);
        return $admin;
    }

    private function seed_credential(int $in_use_by_customer = 0): int
    {
        $this->db->insert(Credentials::table(), [
            'name' => 'primary', 'token_enc' => Crypto::encrypt('tok-1234'), 'token_last4' => '1234',
            'enabled' => 1, 'products' => '', 'priority' => 10, 'is_default' => 1,
            'created_at' => Helpers::now(), 'updated_at' => Helpers::now(),
        ]);
        $id = (int) $this->db->insert_id;
        if ($in_use_by_customer > 0) {
            [$order_id] = $this->seed_order($in_use_by_customer, 1200000, 'active');
            $this->seed_service($in_use_by_customer, $order_id, ['credential_id' => $id]);
        }
        return $id;
    }

    /* --------------------------------------------------- credential_delete */

    public function test_deleting_a_credential_with_live_services_reports_the_real_refusal(): void
    {
        $admin      = $this->as_admin();
        $credential = $this->seed_credential($this->customer(801));
        $_POST = ['credential_id' => (string) $credential, 'arvrs_nonce' => wp_create_nonce('arvrs_credential_delete')];

        try {
            Actions::credential_delete();
            $this->fail('the handler always ends in a redirect');
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertNotEmpty($redirect->url);
        }

        $flash = \ArvanReseller\Admin\Flash::take();
        $this->assertNotSame('', $flash['error'], 'the operator must be told the delete was refused, not silence');
        $this->assertSame(1, $this->count_rows('credentials'), 'a credential with a live service must not vanish');
    }

    public function test_deleting_an_unused_credential_actually_removes_it(): void
    {
        $this->as_admin();
        $credential = $this->seed_credential(0);
        $_POST = ['credential_id' => (string) $credential, 'arvrs_nonce' => wp_create_nonce('arvrs_credential_delete')];

        try {
            Actions::credential_delete();
        } catch (Arvrs_Test_Redirect $redirect) {
            // Admin\Actions::back() strips arvrs_notice/arvrs_error from the
            // URL and carries the message via Flash instead (SEC: the old
            // ?arvrs_notice=<text> round-trip was a phishing primitive) — the
            // success/failure signal here is the Flash message, not the URL.
            $this->assertNotSame('', \ArvanReseller\Admin\Flash::take()['notice']);
        }
        $this->assertSame(0, $this->count_rows('credentials'));
    }

    /* --------------------------------------------------------- order refund */

    /**
     * The admin refund button used to write `Ledger::append('refund', ...)`
     * directly, with none of the settlement guard `PaymentService::refund_order()`
     * enforces. An order whose `purchase` debit is missing (the exact failure
     * `repair_ledger` exists for) must still be refused here, or a refund
     * mints wallet credit backed by no debit.
     */
    public function test_admin_refund_is_refused_when_the_purchase_entry_is_missing(): void
    {
        $this->as_admin();
        [$order_id, $ref] = $this->seed_order(101, 1200000, StateMachine::ACTIVE);
        // Deliberately no Ledger::repair_order_entries() — the settlement pair
        // was never written, exactly the state `refund_order()` refuses.
        $_POST = ['order_id' => (string) $order_id, 'do' => 'refund', 'arvrs_nonce' => wp_create_nonce('arvrs_order_action')];

        try {
            Actions::order_action();
            $this->fail('the handler always ends in a redirect');
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertNotSame('', \ArvanReseller\Admin\Flash::take()['error']);
        }

        $this->assertSame(0, $this->count_rows('ledger'), 'no credit may be minted against a missing settlement debit');
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status'], 'a refused refund must not transition the order');
    }

    public function test_admin_refund_succeeds_once_the_order_is_actually_settled(): void
    {
        $this->as_admin();
        [$order_id, $ref] = $this->seed_order(101, 1200000, StateMachine::ACTIVE);
        Ledger::repair_order_entries(101, $ref, 1200000, $order_id);
        $_POST = ['order_id' => (string) $order_id, 'do' => 'refund', 'arvrs_nonce' => wp_create_nonce('arvrs_order_action')];

        try {
            Actions::order_action();
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertNotSame('', \ArvanReseller\Admin\Flash::take()['notice']);
        }

        $this->assertSame(StateMachine::REFUNDED, OrderService::get($order_id)['status']);
        $this->assertSame(1200000, Ledger::balance(101)['available']);
        $this->assertSame(1, $this->count_rows('ledger', "type = 'refund'"));
    }
}
