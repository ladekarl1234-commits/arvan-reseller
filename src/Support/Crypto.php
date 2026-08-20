<?php
namespace ArvanReseller\Support;

defined('ABSPATH') || exit;

/**
 * Authenticated encryption for secrets at rest (SEC-5 / ADR-0008).
 *
 * libsodium XSalsa20-Poly1305 secretbox; key derived from WordPress salts via
 * HMAC-SHA256 with a fixed context string, so the key never exists on disk as
 * itself and rotates only when the site's salts rotate.
 */
final class Crypto
{
    private const CONTEXT = 'arvrs-credential-v1';

    public static function available(): bool
    {
        return function_exists('sodium_crypto_secretbox');
    }

    private static function key(): string
    {
        $material = wp_salt('auth') . '|' . wp_salt('secure_auth');
        return hash_hmac('sha256', self::CONTEXT, $material, true); // 32 bytes
    }

    /** @return string base64(nonce || ciphertext) */
    public static function encrypt(string $plaintext): string
    {
        if (!self::available()) {
            throw new \RuntimeException('libsodium unavailable: cannot store secrets safely.');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = sodium_crypto_secretbox($plaintext, $nonce, self::key());
        return base64_encode($nonce . $ct);
    }

    /** @return string|null null on tamper/invalid input — callers treat as missing secret */
    public static function decrypt(string $encoded): ?string
    {
        if (!self::available()) {
            return null;
        }
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct    = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $pt    = sodium_crypto_secretbox_open($ct, $nonce, self::key());
        return $pt === false ? null : $pt;
    }

    /** Mask a secret for UI display: `••••1a2b`. */
    public static function mask(string $secret): string
    {
        $tail = substr($secret, -4);
        return '••••' . $tail;
    }
}
