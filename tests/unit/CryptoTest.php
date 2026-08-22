<?php

// Direct-access guard: this harness ships inside the plugin directory (EX-116).
defined('ABSPATH') || exit;

use ArvanReseller\Support\Crypto;
use PHPUnit\Framework\TestCase;

final class CryptoTest extends TestCase
{
    protected function setUp(): void
    {
        if (!Crypto::available()) {
            $this->markTestSkipped('libsodium unavailable');
        }
    }

    public function test_roundtrip(): void
    {
        $secret = 'e5a1c2d3-4444-5555-6666-777788889999';
        $encrypted = Crypto::encrypt($secret);
        $this->assertNotSame($secret, $encrypted);
        $this->assertStringNotContainsString($secret, $encrypted);
        $this->assertSame($secret, Crypto::decrypt($encrypted));
    }

    public function test_each_encryption_uses_fresh_nonce(): void
    {
        $this->assertNotSame(Crypto::encrypt('same'), Crypto::encrypt('same'));
    }

    public function test_tampered_ciphertext_returns_null_not_garbage(): void
    {
        $encrypted = Crypto::encrypt('secret-token');
        $raw = base64_decode($encrypted);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0xFF);
        $this->assertNull(Crypto::decrypt(base64_encode($raw)));
    }

    public function test_invalid_input_returns_null(): void
    {
        $this->assertNull(Crypto::decrypt('not-base64!!!'));
        $this->assertNull(Crypto::decrypt(base64_encode('too-short')));
        $this->assertNull(Crypto::decrypt(''));
    }

    public function test_mask_shows_only_last_four(): void
    {
        $this->assertSame('••••9999', Crypto::mask('e5a1c2d3-4444-5555-6666-777788889999'));
    }
}
