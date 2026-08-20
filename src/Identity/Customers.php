<?php
namespace ArvanReseller\Identity;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Customer identity on top of WP users (ADR-0003): role `arvrs_customer`
 * with zero wp-admin capabilities. All operational data lives in the
 * plugin's custom tables keyed by user ID.
 */
final class Customers
{
    public const ROLE = 'arvrs_customer';

    public static function register_hooks(): void
    {
        add_action('init', [self::class, 'ensure_role']);
        // Customers never see wp-admin or the admin bar (spec: customer dashboard is front-end).
        add_action('admin_init', [self::class, 'block_wp_admin']);
        add_filter('show_admin_bar', [self::class, 'hide_admin_bar']);
    }

    public static function ensure_role(): void
    {
        if (!get_role(self::ROLE)) {
            add_role(self::ROLE, __('مشتری سرویس ابری', 'arvan-reseller'), ['read' => true]);
        }
    }

    public static function is_customer(int $user_id = 0): bool
    {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        return $user && in_array(self::ROLE, (array) $user->roles, true);
    }

    public static function block_wp_admin(): void
    {
        if (wp_doing_ajax() || !self::is_customer()) {
            return;
        }
        wp_safe_redirect(\ArvanReseller\Install\PageFactory::url('dashboard'));
        exit;
    }

    public static function hide_admin_bar(bool $show): bool
    {
        return self::is_customer() ? false : $show;
    }

    /**
     * Register a new customer (server-side validation; rate-limited caller).
     * @return int|\WP_Error user ID
     */
    public static function register(string $email, string $password, string $display_name)
    {
        $email = sanitize_email($email);
        if (!is_email($email)) {
            return new \WP_Error('invalid_email', __('نشانی ایمیل معتبر نیست.', 'arvan-reseller'));
        }
        if (email_exists($email)) {
            return new \WP_Error('exists', __('با این ایمیل قبلاً ثبت‌نام شده است. وارد شوید.', 'arvan-reseller'));
        }
        if (strlen($password) < 8) {
            return new \WP_Error('weak_password', __('گذرواژه باید دست‌کم ۸ نویسه باشد.', 'arvan-reseller'));
        }
        $user_id = wp_insert_user([
            'user_login'   => $email,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => sanitize_text_field($display_name),
            'role'         => self::ROLE,
        ]);
        if (is_wp_error($user_id)) {
            return $user_id;
        }
        \ArvanReseller\Audit\Audit::log(0, 'customer.registered', 'user', (string) $user_id, ['email' => $email]);
        return $user_id;
    }
}
