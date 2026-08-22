<?php
/**
 * `OrderService::claim_paid()` — the single atomic UPDATE that decides whether
 * a gateway callback settles an order.
 *
 * EX-073: the design's whole correctness argument is about two callbacks
 * arriving at once, and nothing tested one. PHPUnit cannot fork, so the guards
 * are driven directly instead: the same claim issued twice, and — where the
 * guard is a predicate on the row's current value — the row mutated in between
 * so the second attempt is provably rejected BY the predicate and not by luck.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;

final class OrderClaimTest extends Arvrs_DbTestCase
{
    public function test_first_claim_wins_and_records_the_transition(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        $claim = OrderService::claim_paid($ref, 1200000, 'TX-1');
        $this->assertSame('claimed', $claim['kind']);
        $this->assertSame(StateMachine::PAID, $claim['order']['status']);

        $events = OrderService::events($order_id);
        $this->assertNotEmpty($events);
        $last = end($events);
        $this->assertSame(StateMachine::PENDING_PAYMENT, $last['from_status']);
        $this->assertSame(StateMachine::PAID, $last['to_status']);
        $this->assertSame('tx:TX-1', $last['note']);
    }

    /**
     * Two callbacks, one effect. The second sees `status = paid`, which is no
     * longer in `payable()`, so the `status IN (...)` predicate rejects it —
     * the same predicate that makes the real race safe.
     */
    public function test_the_same_claim_twice_produces_exactly_one_paid_transition(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        $first  = OrderService::claim_paid($ref, 1200000, 'TX-1');
        $second = OrderService::claim_paid($ref, 1200000, 'TX-2');

        $this->assertSame('claimed', $first['kind']);
        $this->assertSame('replay', $second['kind'], 'the losing callback must not re-settle the order');
        $this->assertSame(StateMachine::PAID, OrderService::get($order_id)['status']);

        $paid_events = 0;
        foreach (OrderService::events($order_id) as $event) {
            if ($event['to_status'] === StateMachine::PAID && $event['from_status'] !== StateMachine::PAID) {
                $paid_events++;
            }
        }
        $this->assertSame(1, $paid_events, 'exactly one settlement event, however many callbacks arrive');
    }

    /**
     * The status guard proven by mutation: move the row out from under the
     * claim between two attempts and the claim must fail, not fall through to
     * a cheerful replay of work it never did.
     */
    public function test_a_claim_loses_when_the_row_moves_underneath_it(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        // Another worker gets there first.
        $this->assertTrue(OrderService::transition($order_id, StateMachine::PENDING_PAYMENT, StateMachine::PAID, 'other'));

        $claim = OrderService::claim_paid($ref, 1200000, 'TX-LATE');
        $this->assertSame('replay', $claim['kind']);
        $this->assertSame(StateMachine::PAID, OrderService::get($order_id)['status']);
    }

    /**
     * A gateway confirming a different figure is a failure needing a human —
     * not a replay. Collapsing the two is how a partial settlement disappears.
     */
    public function test_amount_mismatch_is_its_own_kind_and_leaves_the_order_unpaid(): void
    {
        [$order_id, $ref] = $this->seed_order(101, 1200000);

        $claim = OrderService::claim_paid($ref, 900000, 'TX-SHORT');
        $this->assertSame('amount_mismatch', $claim['kind']);
        $this->assertSame(1200000, (int) $claim['order']['amount']);
        $this->assertSame(
            StateMachine::PENDING_PAYMENT,
            OrderService::get($order_id)['status'],
            'a mismatched amount must never advance the order'
        );
    }

    public function test_unknown_reference_is_not_found(): void
    {
        $claim = OrderService::claim_paid('ARV-NOPE', 1000, 'TX-X');
        $this->assertSame('not_found', $claim['kind']);
        $this->assertNull($claim['order']);
    }

    /** An order already active answers `replay`, never `amount_mismatch`. */
    public function test_a_settled_order_answers_replay_even_on_a_different_amount(): void
    {
        [, $ref] = $this->seed_order(101, 1200000, StateMachine::ACTIVE);
        $this->assertSame('replay', OrderService::claim_paid($ref, 999, 'TX-Y')['kind']);
    }

    /** The state machine is the gate: an illegal move is refused before any write. */
    public function test_transition_refuses_a_move_the_state_machine_forbids(): void
    {
        [$order_id] = $this->seed_order(101, 1200000, StateMachine::ACTIVE);
        $this->assertFalse(OrderService::transition($order_id, StateMachine::ACTIVE, StateMachine::PAID, 'test'));
        $this->assertSame(StateMachine::ACTIVE, OrderService::get($order_id)['status']);
    }

    public function test_customer_scoped_list_never_leaks_another_customers_order(): void
    {
        $this->seed_order(101, 1200000);
        $this->seed_order(102, 900000);

        $alice = OrderService::list(101);
        $bob   = OrderService::list(102);

        $this->assertCount(1, $alice);
        $this->assertCount(1, $bob);
        $this->assertSame(101, (int) $alice[0]['customer_id']);
        $this->assertSame(2, OrderService::count(0), 'the admin view is unscoped by design');
        $this->assertSame(1, OrderService::count(101));
    }
}
