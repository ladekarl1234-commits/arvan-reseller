<?php
/**
 * Plugin Access Token verification.
 *
 * EX-022: two of the five cases here used to assert nothing about the license
 * code — one never activated anything despite its name, and one asserted that
 * PHP's own `password_verify()` round-trips, containing zero plugin symbols.
 * Nothing verified the invariant the design exists for: that a SUCCESSFUL
 * activation persists a fingerprint and never the token.
 *
 * That needs a token the shipped allowlist accepts. `License::allowed_hashes()`
 * reads `data/license-hashes.php` with no seam to inject one, so the token is
 * taken from where the E2E already takes it — `ARVRS_DEMO_TOKEN`, or the demo
 * token documented in DEVELOPMENT.md. No plaintext token is embedded here.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Licensing\License;

final class LicenseTest extends Arvrs_DbTestCase
{
    /** @var string */
    private $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = self::demo_token();
    }

    /**
     * @return string a plaintext token the shipped allowlist accepts
     */
    private static function demo_token(): string
    {
        static $memo = null; // bcrypt at cost 12 is slow; search once per run
        if ($memo !== null) {
            return $memo;
        }
        $candidates = [];
        $from_env = (string) getenv('ARVRS_DEMO_TOKEN');
        if ($from_env !== '') {
            $candidates[] = $from_env;
        }
        $doc = ARVRS_DIR . 'DEVELOPMENT.md';
        if (is_file($doc) && preg_match_all('/\bARVRS-[A-Z0-9]{8,}\b/', (string) file_get_contents($doc), $m)) {
            $candidates = array_merge($candidates, $m[0]);
        }
        foreach (array_unique($candidates) as $candidate) {
            foreach (License::allowed_hashes() as $hash) {
                if (password_verify($candidate, $hash)) {
                    $memo = $candidate;
                    return $memo;
                }
            }
        }
        self::fail(
            'No demo token matching data/license-hashes.php could be found. Set ARVRS_DEMO_TOKEN, '
            . 'or keep the judges\' token documented in DEVELOPMENT.md — the E2E depends on the same fact.'
        );
    }

    public function test_a_successful_activation_stores_a_fingerprint_and_never_the_token(): void
    {
        $this->assertFalse(License::is_active(), 'a fresh install is not licensed');

        $this->assertTrue(License::activate($this->token));
        $this->assertTrue(License::is_active());

        $stored = get_option('arvrs_license');
        $this->assertIsArray($stored);
        $this->assertSame(['active', 'fingerprint', 'activated_at'], array_keys($stored));

        // The leak the design exists to prevent: nothing persisted may contain
        // the token, in any casing, whole or in part.
        $serialised = json_encode($stored);
        $this->assertStringNotContainsString($this->token, $serialised);
        $this->assertStringNotContainsString(strtolower($this->token), strtolower($serialised));
        $this->assertStringNotContainsString(substr($this->token, 6, 16), $serialised);

        // The fingerprint is a truncated SHA-256, so support can identify WHICH
        // token was used without ever holding it.
        $this->assertSame(substr(hash('sha256', $this->token), 0, 12), $stored['fingerprint']);
        $this->assertSame($stored['fingerprint'], License::status()['fingerprint']);
    }

    public function test_a_token_one_character_off_is_rejected_and_persists_nothing(): void
    {
        $tampered = substr($this->token, 0, -1) . ($this->token[strlen($this->token) - 1] === 'A' ? 'B' : 'A');

        $this->assertFalse(License::activate($tampered));
        $this->assertFalse(License::is_active());
        $this->assertFalse(get_option('arvrs_license'), 'a rejected activation must write no state at all');
    }

    public function test_a_random_token_is_rejected(): void
    {
        $this->assertFalse(License::activate('ARVRS-' . strtoupper(bin2hex(random_bytes(16)))));
        $this->assertFalse(License::is_active());
    }

    public function test_deactivation_removes_the_activation_entirely(): void
    {
        License::activate($this->token);
        License::deactivate();

        $this->assertFalse(License::is_active());
        $this->assertFalse(get_option('arvrs_license'));
        $this->assertSame('', License::status()['fingerprint']);
    }

    public function test_empty_and_oversized_tokens_are_rejected_before_any_hashing(): void
    {
        $this->assertFalse(License::activate(''));
        $this->assertFalse(License::activate('   '));
        $this->assertFalse(License::activate(str_repeat('A', 200)));
        $this->assertFalse(License::is_active());
    }

    public function test_status_shape_is_stable_for_an_unlicensed_install(): void
    {
        $status = License::status();
        $this->assertSame(['active', 'fingerprint', 'activated_at'], array_keys($status));
        $this->assertFalse($status['active']);
    }

    public function test_the_shipped_allowlist_holds_bcrypt_hashes_and_no_plaintext(): void
    {
        $contents = file_get_contents(ARVRS_DIR . 'data/license-hashes.php');
        $this->assertStringContainsString('$2y$', $contents, 'bcrypt only');
        $this->assertStringNotContainsString($this->token, $contents, 'never commit a plaintext token');
        $this->assertNotEmpty(License::allowed_hashes());
        foreach (License::allowed_hashes() as $hash) {
            $this->assertStringStartsWith('$2y$', $hash);
        }
    }
}
