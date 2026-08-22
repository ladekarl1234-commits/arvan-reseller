<?php
namespace ArvanReseller\Admin;

defined('ABSPATH') || exit;

/**
 * One-shot admin messages, carried in a per-user transient instead of in the
 * URL.
 *
 * The old `?arvrs_notice=<text>` round-trip meant anyone could hand an
 * administrator a link that rendered arbitrary text inside a first-party
 * WordPress banner — on the very page that hosts the ArvanCloud token form
 * ("your token expired, re-enter it below"). Escaping made it not-XSS but it
 * was still a phishing primitive. Here nothing an attacker controls can reach
 * the sink, because the message never travels through the request at all, and
 * a real upstream error can still be shown verbatim.
 */
final class Flash
{
    /** Long enough to survive the redirect, short enough not to leak into a later session. */
    private const TTL = 120;

    private static function key(): string
    {
        return 'arvrs_flash_' . get_current_user_id();
    }

    public static function notice(string $message): void
    {
        self::set('notice', $message);
    }

    public static function error(string $message): void
    {
        self::set('error', $message);
    }

    private static function set(string $kind, string $message): void
    {
        $message = trim(wp_strip_all_tags($message));
        if ($message === '' || !get_current_user_id()) {
            return;
        }
        $flash         = self::read();
        $flash[$kind]  = substr($message, 0, 500);
        set_transient(self::key(), $flash, self::TTL);
    }

    /**
     * Read and consume. Both keys are always present so templates can render
     * without isset() gymnastics.
     *
     * @return array{notice:string,error:string}
     */
    public static function take(): array
    {
        $flash = self::read();
        if ($flash) {
            delete_transient(self::key());
        }
        return [
            'notice' => isset($flash['notice']) ? (string) $flash['notice'] : '',
            'error'  => isset($flash['error']) ? (string) $flash['error'] : '',
        ];
    }

    private static function read(): array
    {
        if (!get_current_user_id()) {
            return [];
        }
        $raw = get_transient(self::key());
        return is_array($raw) ? $raw : [];
    }
}
