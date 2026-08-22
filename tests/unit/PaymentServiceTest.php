<?php
/**
 * `PaymentService` end to end against a real schema.
 *
 * EX-020 is the reason this file exists: `sandbox_blocked()` — the guard that
 * stops a proof the *buyer* can compute from settling real money — had zero
 * coverage. The E2E ran entirely in demo mode, where the guard is false by
 * construction, so a reordering of the checks in either callback would have
 * shipped green.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Payments\SandboxProvider;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Wallet\Ledger;

final class PaymentServiceTest extends Arvrs_DbTestCase
{
    /* ------------------------------------------------ the sandbox block */

    public function test_sandbox_is_allowed_in_demo_mode_and_blocked_once_live(): void
    {
        $this->assertFalse(PaymentService::sandbox_blocked(), 'demo mode is what the sandbox exists for');
        $this->go_live();
        $this->assertTrue(PaymentService::sandbox_blocked(), 'a self-verifiable proof must not settle real money');
    }

    public function test_order_callback_is_refused_before_any_ledger_write_when_the_gateway_is_the_sandbox(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);
        $this->go_live();

        $proof  = SandboxProvider::proof($ref, 1200000, 'order');
        $result = PaymentService::handle_order_callback($ref, ['sandbox_proof' => $proof, 'type' => 'order']);

        $this->assertFalse($result['ok']);
        $this->assertFalse($result['replay']);
        $this->assertSame(0, $this->count_rows('ledger'), 'no money may be recorded through a blocked gateway');
        $this->assertSame(
            StateMachine::PENDING_PAYMENT,
            OrderService::get($order_id)['status'],
            'the order must stay unpaid'
        );
        $this->assertSame(0, $this->count_rows('services'));
    }

    public function test_topup_callback_is_refused_when_the_gateway_is_the_sandbox(): void
    {
        $this->seed_topup('TOP-LIVE', 101, 5000000);
        $this->go_live();

        $result = PaymentService::handle_topup_callback('TOP-LIVE', [
            'sandbox_proof' => SandboxProvider::proof('TOP-LIVE', 5000000, 'topup'),
            'type'          => 'topup',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->count_rows('ledger'));
        $this->assertSame(0, Ledger::balance(101, true)['available'], 'a blocked gateway must credit nothing');
    }

    public function test_gateway_status_reports_the_block_machine_readably(): void
    {
        $ok = PaymentService::gateway_status();
        $this->assertTrue($ok['ok']);
        $this->assertSame('success', $ok['level']);

        $this->go_live();
        $blocked = PaymentService::gateway_status();
        $this->assertFalse($blocked['ok']);
        $this->assertSame('danger', $blocked['level']);
        $this->assertNotSame('', $blocked['message']);
    }

    /* ------------------------------------------------- the settlement path */

    public function test_a_verified_callback_settles_the_order_and_writes_the_pair(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        $result = PaymentService::handle_order_callback($ref, [
            'sandbox_proof' => SandboxProvider::proof($ref, 1200000, 'order'),
            'type'          => 'order',
        ]);

        $this->assertTrue($result['ok']);
        $this->assertFalse($result['replay']);
        $this->assertSame(2, $this->count_rows('ledger', "ref_id = '" . $ref . "'"), 'payment + purchase');
        $this->assertSame(0, Ledger::balance(101)['available'], 'a gateway order is net-zero on the wallet');
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
        $this->assertSame('active', $result['provision']['state']);
    }

    public function test_a_tampered_amount_fails_verification_and_writes_nothing(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        $result = PaymentService::handle_order_callback($ref, [
            'sandbox_proof' => SandboxProvider::proof($ref, 1, 'order'), // buyer says they paid 1 rial
            'type'          => 'order',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->count_rows('ledger'));
        $this->assertSame(StateMachine::PENDING_PAYMENT, OrderService::get($order_id)['status']);
    }

    /** EX-073 / EX-019: two callbacks, one settlement, one service, one pair of entries. */
    public function test_a_duplicate_callback_settles_nothing_a_second_time(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);
        $payload = ['sandbox_proof' => SandboxProvider::proof($ref, 1200000, 'order'), 'type' => 'order'];

        PaymentService::handle_order_callback($ref, $payload);
        $replay = PaymentService::handle_order_callback($ref, $payload);

        $this->assertTrue($replay['ok']);
        $this->assertTrue($replay['replay']);
        $this->assertSame(2, $this->count_rows('ledger', "ref_id = '" . $ref . "'"));
        $this->assertSame(1, $this->count_rows('services', 'order_id = ' . $order_id));
        $this->assertSame(0, Ledger::balance(101)['available']);
    }

    /**
     * The payment page renders from `provision.state` and from nothing else,
     * so it must never be 'active' for an order that is not.
     */
    public function test_provision_state_never_claims_a_service_the_order_does_not_have(): void
    {
        $this->assertSame('failed', PaymentService::provision_state(null)['state']);
        $this->assertSame('pending', PaymentService::provision_state(['status' => StateMachine::PAID])['state']);
        $this->assertSame('pending', PaymentService::provision_state(['status' => StateMachine::PROVISIONING])['state']);
        $this->assertSame('active', PaymentService::provision_state(['status' => StateMachine::ACTIVE])['state']);
        $this->assertSame('failed', PaymentService::provision_state(['status' => StateMachine::PROVISION_FAILED])['state']);
        $this->assertSame('failed', PaymentService::provision_state(['status' => StateMachine::REFUNDED])['state']);
        $this->assertSame('failed', PaymentService::provision_state(['status' => 'pending_payment'])['state']);
    }

    /** A ledger failure on a settled payment queues the repair instead of losing it. */
    public function test_a_failed_ledger_write_queues_a_repair_job_and_alerts(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);
        $db = $this->db;
        $this->db->intercept = static function (string $sql) use ($db) {
            if (stripos($sql, 'INSERT OR IGNORE INTO ' . $db->prefix . 'arvrs_ledger') !== 0) {
                return null;
            }
            $db->last_error = 'Lost connection to MySQL server during query';
            return 0;
        };

        $result = PaymentService::handle_order_callback($ref, [
            'sandbox_proof' => SandboxProvider::proof($ref, 1200000, 'order'),
            'type'          => 'order',
        ]);
        $this->db->intercept = null;

        $this->assertTrue($result['ok'], 'a paid order must not be stranded by a ledger fault');
        $this->assertSame(1, $this->count_rows('jobs', "type = 'repair_ledger'"));
        $this->assertSame(1, $this->count_rows('notifications', "customer_id = 0 AND type = 'ledger_repair_queued'"));
        $this->assertSame(0, $this->count_rows('ledger'));

        // …and the queued repair actually restores both entries.
        \ArvanReseller\Jobs\Handlers::repair_ledger([
            'customer_id' => 101, 'payment_ref' => $ref, 'amount' => 1200000, 'order_id' => $order_id,
        ]);
        $this->assertSame(2, $this->count_rows('ledger', "ref_id = '" . $ref . "'"));
    }

    /* ------------------------------------------------------------ top-ups */

    public function test_topup_credits_once_and_the_replay_is_a_no_op(): void
    {
        $this->seed_topup('TOP-OK', 101, 5000000);
        $payload = ['sandbox_proof' => SandboxProvider::proof('TOP-OK', 5000000, 'topup'), 'type' => 'topup'];

        $first = PaymentService::handle_topup_callback('TOP-OK', $payload);
        $this->assertTrue($first['ok']);
        $this->assertFalse($first['replay']);

        $second = PaymentService::handle_topup_callback('TOP-OK', $payload);
        $this->assertTrue($second['ok']);
        $this->assertTrue($second['replay']);

        $this->assertSame(5000000, Ledger::balance(101)['available']);
        $this->assertSame(1, $this->count_rows('ledger', "ref_id = 'TOP-OK'"));
    }

    /** An intent is a checkout session, not a coupon: past its expiry it is dead. */
    public function test_an_expired_topup_intent_is_refused(): void
    {
        $this->seed_topup('TOP-OLD', 101, 5000000, gmdate('Y-m-d H:i:s', time() - 60));

        $this->assertNull(PaymentService::topup_intent('TOP-OLD'));
        $result = PaymentService::handle_topup_callback('TOP-OLD', [
            'sandbox_proof' => SandboxProvider::proof('TOP-OLD', 5000000, 'topup'), 'type' => 'topup',
        ]);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, $this->count_rows('ledger'));
    }

    public function test_expired_intents_are_purged_and_settled_ones_are_kept(): void
    {
        $this->seed_topup('TOP-DEAD', 101, 100000, gmdate('Y-m-d H:i:s', time() - 60));
        $this->seed_topup('TOP-LIVE2', 101, 100000);
        $this->assertSame(1, PaymentService::purge_expired_topups());
        $this->assertSame(1, $this->count_rows('topups'));
        $this->assertSame('TOP-LIVE2', (string) $this->db->get_var(
            'SELECT ref FROM ' . PaymentService::topups_table()
        ));
    }

    /**
     * A refund is a net credit that assumes the settlement debit exists. When
     * the settlement write was dropped, refunding would mint wallet credit
     * backed by nothing — so it has to refuse.
     */
    public function test_refund_is_refused_until_the_purchase_entry_exists(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000, StateMachine::ACTIVE);
        $order = OrderService::get($order_id);

        $blocked = PaymentService::refund_order($order, 'admin');
        $this->assertFalse($blocked['ok']);
        $this->assertSame(0, $this->count_rows('ledger'));
        $this->assertSame(1, $this->count_rows('notifications', "type = 'refund_blocked'"));

        Ledger::repair_order_entries(101, $ref, 1200000, $order_id);
        $allowed = PaymentService::refund_order($order, 'admin');
        $this->assertTrue($allowed['ok']);
        $this->assertSame(1200000, Ledger::balance(101)['available']);
    }

    private function seed_topup(string $ref, int $customer_id, int $amount, string $expires_at = ''): void
    {
        $this->db->insert(PaymentService::topups_table(), [
            'ref'         => $ref,
            'customer_id' => $customer_id,
            'amount'      => $amount,
            'status'      => 'pending',
            'created_at'  => Helpers::now(),
            'expires_at'  => $expires_at !== '' ? $expires_at : gmdate('Y-m-d H:i:s', time() + 7200),
        ]);
    }
}
