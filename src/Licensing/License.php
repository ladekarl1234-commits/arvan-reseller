<?php
namespace ArvanReseller\Licensing;

defined('ABSPATH') || exit;

/**
 * Plugin Access Token verification (ADR-0009).
 *
 * Hackathon licensing model: the plugin ships a static allowlist of bcrypt
 * hashes (data/license-hashes.php). The reseller receives a plaintext token
 * out-of-band; activation = password_verify() against the allowlist. Only a
 * non-reversible fingerprint of the accepted token is persisted.
 *
 * This is deliberately NOT a commercial DRM system (documented limitation).
 * The verifier is behind this one class so a future remote/signed-license
 * provider replaces the internals without touching callers.
 */
final class License
{
    private const OPTION = 'arvrs_license';

    /** @return string[] bcrypt hashes bundled with the plugin */
    public static function allowed_hashes(): array
    {
        $file = ARVRS_DIR . 'data/license-hashes.php';
        if (!is_file($file)) {
            return [];
        }
        $hashes = include $file;
        return is_array($hashes) ? array_values(array_filter($hashes, 'is_string')) : [];
    }

    /**
     * Verify a plaintext token and, on success, persist the activation.
     * Constant-time comparison is delegated to password_verify().
     */
    public static function activate(string $token): bool
    {
        $token = trim($token);
        if ($token === '' || strlen($token) > 128) {
            return false;
        }
        foreach (self::allowed_hashes() as $hash) {
            if (password_verify($token, $hash)) {
                update_option(self::OPTION, [
                    'active'       => true,
                    // Fingerprint only — lets support identify WHICH token was
                    // used without ever storing the token itself.
                    'fingerprint'  => substr(hash('sha256', $token), 0, 12),
                    'activated_at' => gmdate('Y-m-d H:i:s'),
                ], false);
                return true;
            }
        }
        return false;
    }

    public static function is_active(): bool
    {
        $state = get_option(self::OPTION, []);
        return is_array($state) && !empty($state['active']);
    }

    /** @return array{active:bool,fingerprint:string,activated_at:string} */
    public static function status(): array
    {
        $state = get_option(self::OPTION, []);
        return [
            'active'       => is_array($state) && !empty($state['active']),
            'fingerprint'  => is_array($state) ? (string) ($state['fingerprint'] ?? '') : '',
            'activated_at' => is_array($state) ? (string) ($state['activated_at'] ?? '') : '',
        ];
    }

    public static function deactivate(): void
    {
        delete_option(self::OPTION);
    }
}
