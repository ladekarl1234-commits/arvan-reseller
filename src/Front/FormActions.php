<?php
namespace ArvanReseller\Front;

use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Classic form handlers for login/register/logout (no-JS friendly). Nonce +
 * rate limit on both; wp_signon handles credential checking.
 */
final class FormActions
{
    public static function register_hooks(): void
    {
        add_action('admin_post_nopriv_arvrs_login', [self::class, 'login']);
        add_action('admin_post_nopriv_arvrs_register', [self::class, 'register']);
        add_action('admin_post_arvrs_login', [self::class, 'already']);
        add_action('admin_post_arvrs_register', [self::class, 'already']);
        add_action('admin_post_arvrs_logout', [self::class, 'logout']);
    }

    private static function back(string $key, string $message): void
    {
        wp_safe_redirect(add_query_arg($key, rawurlencode($message), PageFactory::url('auth')));
        exit;
    }

    public static function login(): void
    {
        check_admin_referer('arvrs_auth', 'arvrs_nonce');
        if (!Helpers::rate_limit('login:' . Helpers::client_ip(), 10, 600)) {
            self::back('arvrs_error', __('تلاش‌های ورود زیاد است. ده دقیقه بعد دوباره امتحان کنید.', 'arvan-reseller'));
        }
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered
        $user     = wp_signon(['user_login' => $email, 'user_password' => $password, 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            self::back('arvrs_error', __('ایمیل یا گذرواژه نادرست است.', 'arvan-reseller'));
        }
        wp_safe_redirect(PageFactory::url('dashboard'));
        exit;
    }

    public static function register(): void
    {
        check_admin_referer('arvrs_auth', 'arvrs_nonce');
        if (!Helpers::rate_limit('register:' . Helpers::client_ip(), 5, 600)) {
            self::back('arvrs_error', __('تعداد ثبت‌نام‌ها از این نشانی زیاد است. بعداً تلاش کنید.', 'arvan-reseller'));
        }
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $name     = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        $result   = Customers::register($email, $password, $name);
        if (is_wp_error($result)) {
            self::back('arvrs_error', $result->get_error_message());
        }
        $user = wp_signon(['user_login' => $email, 'user_password' => $password, 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            self::back('arvrs_notice', __('ثبت‌نام انجام شد. اکنون وارد شوید.', 'arvan-reseller'));
        }
        wp_safe_redirect(PageFactory::url('dashboard'));
        exit;
    }

    public static function already(): void
    {
        wp_safe_redirect(PageFactory::url('dashboard'));
        exit;
    }

    public static function logout(): void
    {
        check_admin_referer('arvrs_logout', 'arvrs_nonce');
        wp_logout();
        wp_safe_redirect(PageFactory::url('storefront'));
        exit;
    }
}
