<?php

// Direct-access guard: this harness ships inside the plugin directory (EX-116).
defined('ABSPATH') || exit;

use ArvanReseller\Policies\PolicyEngine;
use PHPUnit\Framework\TestCase;

final class PolicyEngineTest extends TestCase
{
    public function test_stages_by_threshold(): void
    {
        $this->assertSame(PolicyEngine::HEALTHY, PolicyEngine::stage(1000000, 500000, 100000, 3));
        $this->assertSame(PolicyEngine::WARNING, PolicyEngine::stage(400000, 500000, 100000, 3));
        $this->assertSame(PolicyEngine::WARNING, PolicyEngine::stage(500000, 500000, 100000, 3)); // boundary inclusive
        $this->assertSame(PolicyEngine::CRITICAL, PolicyEngine::stage(100000, 500000, 100000, 3));
        $this->assertSame(PolicyEngine::CRITICAL, PolicyEngine::stage(50000, 500000, 100000, 3));
    }

    public function test_zero_or_negative_enters_grace_then_restricted(): void
    {
        $this->assertSame(PolicyEngine::GRACE, PolicyEngine::stage(0, 500000, 100000, 3, 0));
        $this->assertSame(PolicyEngine::GRACE, PolicyEngine::stage(-50000, 500000, 100000, 3, 2));
        $this->assertSame(PolicyEngine::GRACE, PolicyEngine::stage(-50000, 500000, 100000, 3, 3)); // still within grace
        $this->assertSame(PolicyEngine::RESTRICTED, PolicyEngine::stage(-50000, 500000, 100000, 3, 4));
    }

    public function test_actions_respect_admin_configuration(): void
    {
        $enabled = ['notify_customer', 'block_purchases'];
        $this->assertSame([], PolicyEngine::actions_for(PolicyEngine::HEALTHY, $enabled));
        $this->assertSame(['notify_customer'], PolicyEngine::actions_for(PolicyEngine::WARNING, $enabled));
        // Admin did not enable notify_admin → never fired even in critical.
        $this->assertSame(['notify_customer'], PolicyEngine::actions_for(PolicyEngine::CRITICAL, $enabled));
        $this->assertSame(['notify_customer', 'block_purchases'], PolicyEngine::actions_for(PolicyEngine::RESTRICTED, $enabled));
    }

    public function test_destructive_action_only_in_restricted_stage(): void
    {
        $all = ['notify_customer', 'notify_admin', 'block_purchases', 'mark_at_risk', 'suspend_service'];
        foreach ([PolicyEngine::HEALTHY, PolicyEngine::WARNING, PolicyEngine::CRITICAL] as $stage) {
            $this->assertNotContains('suspend_service', PolicyEngine::actions_for($stage, $all));
            $this->assertNotContains('block_purchases', PolicyEngine::actions_for($stage, $all));
        }
        $this->assertContains('suspend_service', PolicyEngine::actions_for(PolicyEngine::RESTRICTED, $all));
    }
}
