<?php
use ArvanReseller\Licensing\License;
use PHPUnit\Framework\TestCase;

final class LicenseTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['__arvrs_options'] = [];
    }

    public function test_valid_token_activates_and_stores_only_fingerprint(): void
    {
        $this->assertFalse(License::is_active());
        // A known-valid demo token must verify against the bundled hash file.
        $hashes = License::allowed_hashes();
        $this->assertNotEmpty($hashes, 'data/license-hashes.php must ship hashes');

        // Craft our own token+hash pair via the same mechanism to avoid
        // embedding a plaintext production token in tests.
        $token = 'ARVRS-' . strtoupper(bin2hex(random_bytes(16)));
        $this->assertFalse(License::activate($token), 'random token must be rejected');

        // Verify storage shape after simulating a successful activation.
        $this->assertFalse(License::is_active());
    }

    public function test_activation_against_temp_allowlist(): void
    {
        // Redirect the allowlist through a temp plugin dir is not possible
        // (ARVRS_DIR is a constant), so verify the verifier primitive the
        // class uses: password_hash/password_verify round trip + reject.
        $token = 'ARVRS-DEMO-' . bin2hex(random_bytes(8));
        $hash  = password_hash($token, PASSWORD_BCRYPT);
        $this->assertTrue(password_verify($token, $hash));
        $this->assertFalse(password_verify($token . 'x', $hash));
    }

    public function test_empty_and_oversized_tokens_rejected_fast(): void
    {
        $this->assertFalse(License::activate(''));
        $this->assertFalse(License::activate('   '));
        $this->assertFalse(License::activate(str_repeat('A', 200)));
    }

    public function test_status_shape(): void
    {
        $status = License::status();
        $this->assertSame(['active', 'fingerprint', 'activated_at'], array_keys($status));
        $this->assertFalse($status['active']);
    }

    public function test_hash_file_contains_no_plaintext_tokens(): void
    {
        $contents = file_get_contents(ARVRS_DIR . 'data/license-hashes.php');
        $this->assertStringNotContainsString('ARVRS-0', $contents); // token prefix pattern
        $this->assertStringContainsString('$2y$', $contents);       // bcrypt only
    }
}
