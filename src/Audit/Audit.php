<?php
namespace ArvanReseller\Audit;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Append-only audit log for security-sensitive actions (SEC-10) plus a
 * redacted application log channel for observability. One table, `level`
 * distinguishes audit ('audit') from diagnostics ('info'/'error').
 */
final class Audit
{
    private const REDACT_KEYS = ['token', 'api_token', 'password', 'secret', 'authorization', 'pat'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_audit_log';
    }

    /** @param array<string,mixed> $detail redacted before storage */
    public static function log(int $user_id, string $action, string $object_type = '', string $object_id = '', array $detail = [], string $level = 'audit'): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id'     => $user_id ?: get_current_user_id(),
            'action'      => substr($action, 0, 64),
            'object_type' => substr($object_type, 0, 32),
            'object_id'   => substr($object_id, 0, 64),
            'detail'      => wp_json_encode(self::redact($detail)),
            'ip'          => Helpers::client_ip(),
            'level'       => $level,
            'created_at'  => Helpers::now(),
        ]);
    }

    public static function error(string $action, array $detail = []): void
    {
        self::log(0, $action, '', '', $detail, 'error');
    }

    /** Recursively strip secret-looking keys (SEC-5: never log secrets). */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::REDACT_KEYS as $needle) {
                if (strpos($lk, $needle) !== false) {
                    $out[$k] = '[REDACTED]';
                    continue 2;
                }
            }
            $out[$k] = is_array($v) ? self::redact($v) : $v;
        }
        return $out;
    }

    public static function recent(int $limit = 50, string $level = ''): array
    {
        global $wpdb;
        if ($level !== '') {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE level = %s ORDER BY id DESC LIMIT %d',
                $level, $limit
            ), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit
        ), ARRAY_A) ?: [];
    }
}
