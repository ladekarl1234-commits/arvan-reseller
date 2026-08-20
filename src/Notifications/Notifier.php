<?php
namespace ArvanReseller\Notifications;

use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * In-app notifications + best-effort email, with per-(recipient, type)
 * cooldown so nobody is flooded with duplicate warnings (spec: notification
 * system). customer_id = 0 addresses the admin.
 */
final class Notifier
{
    /** Types that repeat and therefore respect the cooldown window. */
    // Repeating, customer-scoped warnings that must not flood. Per-event
    // admin alerts (provision_failed, job_dead) are deliberately NOT here —
    // each names a distinct order/job and must always surface.
    private const COOLDOWN_TYPES = ['low_balance', 'critical_balance', 'suspension_warning', 'credential_failed', 'usage_sync_failed'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_notifications';
    }

    public static function customer(int $customer_id, string $type, string $title, string $body): void
    {
        // Email rides the SAME cooldown as the in-app notice: push() returns
        // false when suppressed, so a steady-state warning does not mail hourly.
        if (!self::push($customer_id, $type, $title, $body)) {
            return;
        }
        $user = get_userdata($customer_id);
        if ($user && in_array($type, ['payment_success', 'provisioned', 'provision_failed', 'low_balance', 'critical_balance', 'suspension_warning'], true)) {
            wp_mail($user->user_email, wp_specialchars_decode($title), $body); // best-effort
        }
    }

    public static function admin(string $type, string $title, string $body): void
    {
        self::push(0, $type, $title, $body);
    }

    /** @return bool true when a notice was actually written (false = cooldown-suppressed) */
    private static function push(int $recipient, string $type, string $title, string $body): bool
    {
        global $wpdb;
        if (in_array($type, self::COOLDOWN_TYPES, true)) {
            $hours  = max(1, (int) Options::get('notify_cooldown', 24));
            $recent = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . self::table() . ' WHERE customer_id = %d AND type = %s AND created_at > %s LIMIT 1',
                $recipient, $type, gmdate('Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS)
            ));
            if ($recent) {
                return false; // still inside cooldown — no duplicate
            }
        }
        $wpdb->insert(self::table(), [
            'customer_id' => $recipient,
            'type'        => substr($type, 0, 48),
            'title'       => substr($title, 0, 191),
            'body'        => $body,
            'created_at'  => Helpers::now(),
        ]);
        return true;
    }

    public static function for_user(int $customer_id, int $limit = 10): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, title, body, is_read, created_at FROM ' . self::table() .
            ' WHERE customer_id = %d ORDER BY id DESC LIMIT %d',
            $customer_id, $limit
        ), ARRAY_A) ?: [];
    }

    public static function mark_read(int $customer_id, int $notification_id): void
    {
        global $wpdb;
        $wpdb->update(self::table(), ['is_read' => 1], ['id' => $notification_id, 'customer_id' => $customer_id]);
    }

    public static function unread_count(int $customer_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE customer_id = %d AND is_read = 0',
            $customer_id
        ));
    }
}
