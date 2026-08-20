<?php
namespace ArvanReseller\Support;

defined('ABSPATH') || exit;

final class Helpers
{
    /** Render a template from templates/ with extracted vars, returning HTML. */
    public static function view(string $template, array $vars = []): string
    {
        $file = ARVRS_DIR . 'templates/' . $template . '.php';
        if (!is_file($file)) {
            return '';
        }
        ob_start();
        extract($vars, EXTR_SKIP); // phpcs:ignore WordPress.PHP.DontExtract
        include $file;
        return (string) ob_get_clean();
    }

    /** Format toman with Persian digits and thousands separators: ۱۲٬۰۰۰٬۰۰۰ تومان */
    public static function money(int $amount): string
    {
        $formatted = number_format($amount);
        return self::fa_digits($formatted) . ' ' . __('تومان', 'arvan-reseller');
    }

    public static function fa_digits(string $s): string
    {
        return strtr($s, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
            ',' => '٬',
        ]);
    }

    /** Best-effort client IP for audit rows (never used for auth decisions). */
    public static function client_ip(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        return substr($ip, 0, 45);
    }

    public static function now(): string
    {
        return current_time('mysql', true);
    }

    /**
     * Simple fixed-window rate limiter on transients (SEC-11).
     * @return bool true when the call is allowed
     */
    public static function rate_limit(string $bucket, int $max, int $window_seconds): bool
    {
        $key = 'arvrs_rl_' . md5($bucket); // md5 = cache-key derivation only, not secret storage
        $hits = (int) get_transient($key);
        if ($hits >= $max) {
            return false;
        }
        set_transient($key, $hits + 1, $window_seconds);
        return true;
    }
}
