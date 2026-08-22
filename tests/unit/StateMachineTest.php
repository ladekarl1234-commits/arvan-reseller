<?php

// Direct-access guard: this harness ships inside the plugin directory (EX-116).
defined('ABSPATH') || exit;

use ArvanReseller\Orders\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    public function test_happy_path_is_legal(): void
    {
        $path = [
            [StateMachine::DRAFT, StateMachine::PENDING_PAYMENT],
            [StateMachine::PENDING_PAYMENT, StateMachine::PAID],
            [StateMachine::PAID, StateMachine::PROVISIONING],
            [StateMachine::PROVISIONING, StateMachine::ACTIVE],
        ];
        foreach ($path as [$from, $to]) {
            $this->assertTrue(StateMachine::can($from, $to), "$from → $to must be legal");
        }
    }

    public function test_failure_and_retry_path(): void
    {
        $this->assertTrue(StateMachine::can(StateMachine::PROVISIONING, StateMachine::PROVISION_FAILED));
        $this->assertTrue(StateMachine::can(StateMachine::PROVISION_FAILED, StateMachine::PROVISIONING));
        $this->assertTrue(StateMachine::can(StateMachine::PROVISION_FAILED, StateMachine::REFUNDED));
    }

    public function test_illegal_jumps_rejected(): void
    {
        $this->assertFalse(StateMachine::can(StateMachine::DRAFT, StateMachine::ACTIVE));
        $this->assertFalse(StateMachine::can(StateMachine::PENDING_PAYMENT, StateMachine::PROVISIONING));
        $this->assertFalse(StateMachine::can(StateMachine::ACTIVE, StateMachine::PAID));
        $this->assertFalse(StateMachine::can(StateMachine::PROVISIONING, StateMachine::PAID));
    }

    public function test_terminal_states_have_no_exits(): void
    {
        foreach ([StateMachine::CANCELLED, StateMachine::REFUNDED] as $terminal) {
            $this->assertTrue(StateMachine::is_terminal($terminal));
            foreach (StateMachine::all() as $to) {
                $this->assertFalse(StateMachine::can($terminal, $to));
            }
        }
    }

    public function test_unknown_state_never_transitions(): void
    {
        $this->assertFalse(StateMachine::can('hacked_state', StateMachine::ACTIVE));
        $this->assertFalse(StateMachine::can(StateMachine::PAID, 'hacked_state'));
    }

    public function test_payable_states(): void
    {
        $this->assertSame([StateMachine::PENDING_PAYMENT, StateMachine::PAYMENT_PROCESSING], StateMachine::payable());
    }
}
