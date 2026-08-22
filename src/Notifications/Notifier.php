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
    private const COOLDOWN_TYPES = ['low_balance', 'critical_balance', 'suspension_warning', 'credential_failed', 'usage_sync_failed', 'renewal_no_price'];

    /**
     * Types worth an email as well as an in-app notice. Every renewal event
     * is here: a recurring charge the customer only discovers in-app is a
     * surprise on their card, and a renewal they were never reminded of is a
     * service that stops without warning.
     */
    private const EMAIL_TYPES = [
        'payment_success', 'provisioned', 'provision_failed',
        'low_balance', 'critical_balance', 'suspension_warning',
        'renewal_reminder', 'renewal_charged', 'renewal_cancelled',
    ];

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
        if ($user && in_array($type, self::EMAIL_TYPES, true)) {
            wp_mail($user->user_email, wp_specialchars_decode($title), $body); // best-effort
        }
    }

    public static function admin(string $type, string $title, string $body): void
    {
        self::push(0, $type, $title, $body);
    }

    /**
     * A provisioning failure that the CUSTOMER hears about, not just the
     * admin. The panel's UX critical was that a paid order could fail and the
     * buyer would be told nothing, ever — so this is one call that always
     * reaches both, and callers must not choose only half of it.
     *
     * @param string $reason customer-safe text (ProviderError::customer_message()),
     *                       never a raw upstream body
     * @param string $detail admin-only diagnostic (error kind, correlation id)
     */
    public static function provision_failed(int $customer_id, int $order_id, string $reason, string $detail = ''): void
    {
        self::customer(
            $customer_id,
            'provision_failed',
            __('راه‌اندازی سرویس شما ناموفق بود', 'arvan-reseller'),
            sprintf(
                __('راه‌اندازی سفارش #%1$d با مشکل روبه‌رو شد: %2$s مبلغ پرداختی شما محفوظ است و تیم پشتیبانی در حال بررسی است. در صورت نیاز با شماره سفارش #%1$d با ما تماس بگیرید.', 'arvan-reseller'),
                $order_id,
                $reason !== '' ? $reason : __('خطای نامشخص.', 'arvan-reseller')
            )
        );
        self::admin(
            'provision_failed',
            __('خطای راه‌اندازی سرویس', 'arvan-reseller'),
            sprintf(__('سفارش #%1$d با خطا مواجه شد: %2$s', 'arvan-reseller'), $order_id, $detail !== '' ? $detail : $reason)
        );
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
