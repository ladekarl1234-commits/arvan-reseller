<?php

// Direct-access guard: this harness ships inside the plugin directory (EX-116).
defined('ABSPATH') || exit;

use ArvanReseller\Payments\SandboxProvider;
use PHPUnit\Framework\TestCase;

final class PaymentVerificationTest extends TestCase
{
    public function test_valid_proof_verifies(): void
    {
        $provider = new SandboxProvider();
        $proof    = SandboxProvider::proof('ARV-ABC123', 12000000, 'order');
        $result   = $provider->verify('ARV-ABC123', 12000000, ['sandbox_proof' => $proof, 'type' => 'order']);
        $this->assertTrue($result['ok']);
        $this->assertNotSame('', $result['transaction_id']);
    }

    public function test_tampered_amount_fails_verification(): void
    {
        // Customer paid a "price" they edited client-side: the gateway proof
        // was issued for the real amount and must not verify for another.
        $provider = new SandboxProvider();
        $proof    = SandboxProvider::proof('ARV-ABC123', 12000000, 'order');
        $result   = $provider->verify('ARV-ABC123', 999, ['sandbox_proof' => $proof, 'type' => 'order']);
        $this->assertFalse($result['ok']);
    }

    public function test_proof_for_one_ref_rejected_for_another(): void
    {
        $provider = new SandboxProvider();
        $proof    = SandboxProvider::proof('ARV-AAAA', 100000, 'order');
        $this->assertFalse($provider->verify('ARV-BBBB', 100000, ['sandbox_proof' => $proof, 'type' => 'order'])['ok']);
    }

    public function test_order_proof_rejected_for_topup_flow(): void
    {
        $provider = new SandboxProvider();
        $proof    = SandboxProvider::proof('TOP-XYZ', 100000, 'order');
        $this->assertFalse($provider->verify('TOP-XYZ', 100000, ['sandbox_proof' => $proof, 'type' => 'topup'])['ok']);
    }

    public function test_missing_proof_fails(): void
    {
        $provider = new SandboxProvider();
        $this->assertFalse($provider->verify('ARV-ABC123', 100, [])['ok']);
    }
}
