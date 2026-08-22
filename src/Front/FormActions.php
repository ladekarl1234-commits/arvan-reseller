<?php
namespace ArvanReseller\Front;

use ArvanReseller\Billing\Renewals;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Services\Services;
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
        add_action('admin_post_arvrs_cancel_renewal', [self::class, 'cancel_renewal']);
    }

    /**
     * Redirect back to the auth page with a flash CODE.
     *
     * A code, never a sentence: the query string is attacker-supplied, and the
     * text is looked up server-side by Shortcodes::flash() (EX-115). `$extra`
     * carries the values a failed registration must not lose (EX-066).
     */
    private static function back(string $key, string $code, array $extra = []): void
    {
        $url = add_query_arg(array_merge([$key => $code], $extra), PageFactory::url('auth'));
        wp_safe_redirect($url);
        exit;
    }

    public static function login(): void
    {
        check_admin_referer('arvrs_auth', 'arvrs_nonce');
        if (!Helpers::rate_limit('login:' . Helpers::client_ip(), 10, 600)) {
            self::back('arvrs_error', 'login_throttled');
        }
        $email    = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passwords must not be altered
        $user     = wp_signon(['user_login' => $email, 'user_password' => $password, 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            self::back('arvrs_error', 'login_failed', ['arvrs_email' => $email]);
        }
        wp_safe_redirect(PageFactory::url('dashboard'));
        exit;
    }

    public static function register(): void
    {
        check_admin_referer('arvrs_auth', 'arvrs_nonce');
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name  = isset($_POST['display_name']) ? sanitize_text_field(wp_unslash($_POST['display_name'])) : '';
        // Everything the user typed, so the panel can be re-rendered filled in.
        $keep  = ['tab' => 'register', 'arvrs_email' => $email, 'arvrs_name' => $name];

        if (!Customers::registration_open()) {
            self::back('arvrs_error', 'register_closed', $keep);
        }
        // Two buckets: per IP against a flood, and per address so the
        // "does this account exist?" probe cannot be parallelised across IPs.
        if (!Helpers::rate_limit('register:' . Helpers::client_ip(), 5, 600)
            || ($email !== '' && !Helpers::rate_limit('register_email:' . strtolower($email), 3, 3600))) {
            self::back('arvrs_error', 'register_throttled', $keep);
        }
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $result   = Customers::register($email, $password, $name);
        if (is_wp_error($result)) {
            $codes = [
                'registration_closed' => 'register_closed',
                'invalid_email'       => 'register_invalid_email',
                'weak_password'       => 'register_weak_password',
                // Same code as a completed sign-up whose auto-login failed:
                // the two outcomes must read identically (EX-114).
                'exists'              => 'register_check_inbox',
            ];
            $code = $result->get_error_code();
            self::back('arvrs_error', isset($codes[$code]) ? $codes[$code] : 'register_failed', $keep);
        }
        $user = wp_signon(['user_login' => $email, 'user_password' => $password, 'remember' => true], is_ssl());
        if (is_wp_error($user)) {
            self::back('arvrs_notice', 'register_check_inbox', ['arvrs_email' => $email]);
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

    /**
     * Customer-initiated "stop renewing this service".
     *
     * Ownership is resolved from the session, never from the request: the id
     * goes through Services::get_owned() before Renewals::cancel() — which is
     * an admin-facing call with no owner check of its own — so one customer
     * cannot cancel another's renewal.
     */
    public static function cancel_renewal(): void
    {
        check_admin_referer('arvrs_cancel_renewal', 'arvrs_nonce');
        $dashboard = add_query_arg('tab', 'services', PageFactory::url('dashboard'));
        if (!Customers::is_customer()) {
            wp_safe_redirect($dashboard);
            exit;
        }
        $service_id = isset($_POST['service_id']) ? absint(wp_unslash($_POST['service_id'])) : 0;
        $owned      = $service_id ? Services::get_owned($service_id, get_current_user_id()) : null;
        $ok         = $owned ? Renewals::cancel($service_id, 'customer') : false;
        wp_safe_redirect(add_query_arg($ok ? 'arvrs_notice' : 'arvrs_error', $ok ? 'renewal_cancelled' : 'renewal_failed', $dashboard));
        exit;
    }
}
