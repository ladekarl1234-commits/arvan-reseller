<?php
namespace ArvanReseller\Admin;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\RealProvider;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * All admin state changes: admin-post handlers with capability + nonce
 * (SEC-1) and per-field whitelisting (SEC-3). Every security-sensitive
 * action audits (SEC-10).
 */
final class Actions
{
    private const ACTIONS = [
        'arvrs_save_branding', 'arvrs_save_pricing', 'arvrs_save_policies',
        'arvrs_credential_save', 'arvrs_credential_delete', 'arvrs_credential_test',
        'arvrs_rules_save', 'arvrs_wallet_adjust', 'arvrs_order_action',
        'arvrs_job_retry', 'arvrs_run_jobs', 'arvrs_sync_now', 'arvrs_flush_catalog',
        'arvrs_license_reset',
    ];

    public static function register_hooks(): void
    {
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_' . $action, [self::class, substr($action, 6)]);
        }
    }

    /** Shared guard: capability + nonce; returns sanitized redirect target. */
    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی غیرمجاز.', 'arvan-reseller'), 403);
        }
        check_admin_referer($action, 'arvrs_nonce');
    }

    private static function back(string $notice = '', string $error = ''): void
    {
        $url = wp_get_referer() ?: admin_url('admin.php?page=arvan-reseller');
        $url = remove_query_arg(['arvrs_notice', 'arvrs_error'], $url);
        if ($notice) {
            $url = add_query_arg('arvrs_notice', rawurlencode($notice), $url);
        }
        if ($error) {
            $url = add_query_arg('arvrs_error', rawurlencode($error), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    public static function save_branding(): void
    {
        self::guard('arvrs_save_branding');
        Options::set_many([
            'brand_name'        => sanitize_text_field(wp_unslash($_POST['brand_name'] ?? '')),
            'brand_description' => sanitize_text_field(wp_unslash($_POST['brand_description'] ?? '')),
            'brand_about'       => sanitize_textarea_field(wp_unslash($_POST['brand_about'] ?? '')),
            'support_email'     => sanitize_email(wp_unslash($_POST['support_email'] ?? '')),
            'support_phone'     => preg_replace('/[^0-9+\-\s]/', '', (string) wp_unslash($_POST['support_phone'] ?? '')),
            'brand_color'       => sanitize_hex_color(wp_unslash($_POST['brand_color'] ?? '')) ?: '#0c6960',
            'demo_mode'         => !empty($_POST['demo_mode']),
            'data_retention_on_uninstall' => !empty($_POST['data_retention']),
        ]);

        // Logo upload through the WP media pipeline, hard-restricted (SEC-8).
        if (!empty($_FILES['brand_logo']['name'])) {
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            if ((int) ($_FILES['brand_logo']['size'] ?? 0) > MB_IN_BYTES) {
                self::back('', __('حجم لوگو نباید بیش از ۱ مگابایت باشد.', 'arvan-reseller'));
            }
            $check = wp_check_filetype_and_ext($_FILES['brand_logo']['tmp_name'], $_FILES['brand_logo']['name'], $allowed); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            if (empty($check['type']) || !isset($allowed[$check['type']])) {
                self::back('', __('فرمت لوگو باید PNG، JPG یا WebP باشد.', 'arvan-reseller'));
            }
            $id = media_handle_upload('brand_logo', 0, [], ['test_form' => false, 'mimes' => array_flip($allowed)]);
            if (!is_wp_error($id)) {
                Options::set('brand_logo_id', (int) $id);
            }
        }
        Audit::log(0, 'branding.saved', 'settings', '', ['demo_mode' => !empty($_POST['demo_mode'])]);
        self::back(__('تنظیمات برند ذخیره شد.', 'arvan-reseller'));
    }

    public static function save_pricing(): void
    {
        self::guard('arvrs_save_pricing');
        $product_markup = [];
        foreach (Catalog::PRODUCTS as $product) {
            $value = wp_unslash($_POST['product_markup'][$product] ?? '');
            if ($value !== '' && is_numeric($value)) {
                $product_markup[$product] = max(-100, (float) $value);
            }
        }
        Options::set_many([
            'global_markup'    => max(-100, (float) wp_unslash($_POST['global_markup'] ?? 20)),
            'product_markup'   => $product_markup,
            'fixed_adjustment' => (int) wp_unslash($_POST['fixed_adjustment'] ?? 0),
        ]);
        // Base-cost table rows: base_cost[product][plan_id] = IRT
        $base = (array) ($_POST['base_cost'] ?? []);
        foreach ($base as $product => $plans) {
            if (!in_array($product, Catalog::PRODUCTS, true)) {
                continue;
            }
            foreach ((array) $plans as $plan_id => $cost) {
                $plan_id = sanitize_text_field($plan_id);
                if ($plan_id !== '' && is_numeric($cost)) {
                    BaseCosts::set($product, $plan_id, (int) $cost, 'admin @ ' . gmdate('Y-m-d'));
                }
            }
        }
        // New plan row (optional).
        $new_product = sanitize_key($_POST['new_product'] ?? '');
        $new_plan    = sanitize_text_field(wp_unslash($_POST['new_plan_id'] ?? ''));
        $new_cost    = wp_unslash($_POST['new_base_cost'] ?? '');
        if (in_array($new_product, Catalog::PRODUCTS, true) && $new_plan !== '' && is_numeric($new_cost)) {
            BaseCosts::set($new_product, $new_plan, (int) $new_cost, 'admin @ ' . gmdate('Y-m-d'));
        }
        Catalog::flush();
        Audit::log(0, 'pricing.saved', 'settings', '', ['global_markup' => (float) wp_unslash($_POST['global_markup'] ?? 20)]);
        self::back(__('قیمت‌گذاری ذخیره شد.', 'arvan-reseller'));
    }

    public static function save_policies(): void
    {
        self::guard('arvrs_save_policies');
        $known_actions = ['notify_customer', 'notify_admin', 'block_purchases', 'mark_at_risk', 'suspend_service'];
        Options::set_many([
            'policy_warning'    => max(0, (int) wp_unslash($_POST['policy_warning'] ?? 500000)),
            'policy_critical'   => max(0, (int) wp_unslash($_POST['policy_critical'] ?? 100000)),
            'policy_grace_days' => max(0, (int) wp_unslash($_POST['policy_grace_days'] ?? 3)),
            'policy_actions'    => array_values(array_intersect((array) ($_POST['policy_actions'] ?? []), $known_actions)),
            'notify_cooldown'   => max(1, (int) wp_unslash($_POST['notify_cooldown'] ?? 24)),
        ]);
        Audit::log(0, 'policies.saved', 'settings', '');
        self::back(__('سیاست اعتبار ذخیره شد.', 'arvan-reseller'));
    }

    public static function credential_save(): void
    {
        self::guard('arvrs_credential_save');
        if (!\ArvanReseller\Support\Crypto::available()) {
            self::back('', __('افزونه sodium در PHP فعال نیست؛ ذخیره امن توکن ممکن نیست.', 'arvan-reseller'));
        }
        $id    = (int) ($_POST['credential_id'] ?? 0);
        $token = trim((string) wp_unslash($_POST['api_token'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secret, encrypted not echoed
        if (strlen($token) > 256) {
            self::back('', __('توکن API نامعتبر است.', 'arvan-reseller'));
        }
        $saved = Credentials::save([
            'name'       => (string) wp_unslash($_POST['name'] ?? ''),
            'enabled'    => !empty($_POST['enabled']),
            'products'   => (array) ($_POST['products'] ?? []),
            'priority'   => (int) ($_POST['priority'] ?? 10),
            'is_default' => !empty($_POST['is_default']),
            'notes'      => (string) wp_unslash($_POST['notes'] ?? ''),
        ], $token, $id);
        if (!$saved) {
            self::back('', __('برای اتصال جدید، وارد کردن توکن الزامی است.', 'arvan-reseller'));
        }
        self::back(__('اتصال ArvanCloud ذخیره شد. برای اطمینان، «آزمایش اتصال» را بزنید.', 'arvan-reseller'));
    }

    public static function credential_delete(): void
    {
        self::guard('arvrs_credential_delete');
        Credentials::delete((int) ($_POST['credential_id'] ?? 0));
        self::back(__('اتصال حذف شد.', 'arvan-reseller'));
    }

    public static function credential_test(): void
    {
        self::guard('arvrs_credential_test');
        $id  = (int) ($_POST['credential_id'] ?? 0);
        $row = null;
        foreach (Credentials::all() as $credential) {
            if ((int) $credential['id'] === $id) {
                $row = $credential;
                break;
            }
        }
        if (!$row) {
            self::back('', __('اتصال یافت نشد.', 'arvan-reseller'));
        }
        global $wpdb;
        $enc   = $wpdb->get_var($wpdb->prepare('SELECT token_enc FROM ' . Credentials::table() . ' WHERE id = %d', $id));
        $token = \ArvanReseller\Support\Crypto::decrypt((string) $enc);
        if ($token === null) {
            Credentials::record_test($id, false, 'decryption failed (salts rotated?)');
            self::back('', __('رمزگشایی توکن ممکن نیست (کلیدهای امنیتی سایت تغییر کرده‌اند). توکن را دوباره وارد کنید.', 'arvan-reseller'));
        }
        $provider = new RealProvider(['id' => $id, 'token' => $token]);
        $result   = $provider->test_connection();
        Credentials::record_test($id, $result['ok'], $result['ok'] ? '' : $result['message']);
        Audit::log(0, 'credential.tested', 'credential', (string) $id, ['ok' => $result['ok']]);
        $result['ok'] ? self::back($result['message']) : self::back('', $result['message']);
    }

    public static function rules_save(): void
    {
        self::guard('arvrs_rules_save');
        $customer_id = (int) ($_POST['customer_id'] ?? 0);
        if (!$customer_id || !\ArvanReseller\Identity\Customers::is_customer($customer_id)) {
            self::back('', __('مشتری یافت نشد.', 'arvan-reseller'));
        }
        Rules::save($customer_id, [
            'markup_percent'   => wp_unslash($_POST['markup_percent'] ?? ''),
            'discount_percent' => wp_unslash($_POST['discount_percent'] ?? ''),
            'fixed_adjustment' => wp_unslash($_POST['fixed_adjustment'] ?? ''),
            'credit_limit'     => wp_unslash($_POST['credit_limit'] ?? ''),
            'spending_limit'   => wp_unslash($_POST['spending_limit'] ?? ''),
            'allowed_products' => (array) ($_POST['allowed_products'] ?? []),
            'status'           => sanitize_key($_POST['status'] ?? 'active'),
            'grace_days'       => wp_unslash($_POST['grace_days'] ?? ''),
            'notes'            => (string) wp_unslash($_POST['notes'] ?? ''),
        ]);
        self::back(__('قوانین مشتری ذخیره شد.', 'arvan-reseller'));
    }

    public static function wallet_adjust(): void
    {
        self::guard('arvrs_wallet_adjust');
        $customer_id = (int) ($_POST['customer_id'] ?? 0);
        $amount      = (int) wp_unslash($_POST['amount'] ?? 0);
        $kind        = sanitize_key($_POST['kind'] ?? '');
        $note        = sanitize_text_field(wp_unslash($_POST['note'] ?? ''));
        if (!$customer_id || $amount <= 0 || !in_array($kind, ['promo_credit', 'adjustment', 'refund'], true)) {
            self::back('', __('مقادیر واردشده معتبر نیست.', 'arvan-reseller'));
        }
        Ledger::append($customer_id, $kind, $amount, 'admin', 'adj-' . bin2hex(random_bytes(6)),
            $note ?: __('اصلاح دستی توسط مدیر', 'arvan-reseller'), 'admin:' . get_current_user_id());
        UsageSync::apply_policy($customer_id);
        Audit::log(0, 'wallet.adjusted', 'user', (string) $customer_id, ['kind' => $kind, 'amount' => $amount]);
        self::back(__('تراکنش ثبت شد.', 'arvan-reseller'));
    }

    public static function order_action(): void
    {
        self::guard('arvrs_order_action');
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $do       = sanitize_key($_POST['do'] ?? '');
        $order    = OrderService::get($order_id);
        if (!$order) {
            self::back('', __('سفارش یافت نشد.', 'arvan-reseller'));
        }
        if ($do === 'retry_provision') {
            JobRunner::enqueue('provision_order', ['order_id' => $order_id]);
            try {
                $result = Provisioner::provision($order_id);
                self::back($result['ok'] ? __('راه‌اندازی با موفقیت انجام شد.', 'arvan-reseller') : $result['message']);
            } catch (\Throwable $e) {
                self::back('', __('راه‌اندازی همچنان ناموفق است؛ در پس‌زمینه دوباره تلاش می‌شود.', 'arvan-reseller'));
            }
        }
        if ($do === 'refund') {
            $from = (string) $order['status'];
            if (!OrderService::transition($order_id, $from, StateMachine::REFUNDED, 'admin:' . get_current_user_id(), 'manual refund')) {
                self::back('', __('بازپرداخت از این وضعیت مجاز نیست.', 'arvan-reseller'));
            }
            Ledger::append((int) $order['customer_id'], 'refund', (int) $order['amount'], 'order_refund', (string) $order['payment_ref'],
                sprintf(__('بازپرداخت سفارش #%d', 'arvan-reseller'), $order_id), 'admin:' . get_current_user_id());
            Audit::log(0, 'order.refunded', 'order', (string) $order_id, ['amount' => (int) $order['amount']]);
            self::back(__('سفارش بازپرداخت شد و مبلغ به کیف پول مشتری برگشت.', 'arvan-reseller'));
        }
        if ($do === 'cancel') {
            $ok = OrderService::transition($order_id, (string) $order['status'], StateMachine::CANCELLED, 'admin:' . get_current_user_id(), 'manual cancel');
            self::back($ok ? __('سفارش لغو شد.', 'arvan-reseller') : '', $ok ? '' : __('لغو از این وضعیت مجاز نیست.', 'arvan-reseller'));
        }
        self::back('', __('عملیات ناشناخته.', 'arvan-reseller'));
    }

    public static function job_retry(): void
    {
        self::guard('arvrs_job_retry');
        JobRunner::retry((int) ($_POST['job_id'] ?? 0));
        self::back(__('وظیفه دوباره در صف قرار گرفت.', 'arvan-reseller'));
    }

    public static function run_jobs(): void
    {
        self::guard('arvrs_run_jobs');
        $ran = JobRunner::run_due();
        self::back(sprintf(__('%d وظیفه اجرا شد.', 'arvan-reseller'), $ran));
    }

    public static function sync_now(): void
    {
        self::guard('arvrs_sync_now');
        $stats = UsageSync::sync_all();
        Audit::log(0, 'usage.sync_now', 'system', '', $stats);
        self::back(sprintf(
            __('همگام‌سازی انجام شد: %1$d سرویس، %2$d رکورد جدید، %3$d برداشت.', 'arvan-reseller'),
            $stats['services'], $stats['ingested'], $stats['debited']
        ));
    }

    public static function flush_catalog(): void
    {
        self::guard('arvrs_flush_catalog');
        Catalog::flush();
        self::back(__('کش کاتالوگ خالی شد.', 'arvan-reseller'));
    }

    public static function license_reset(): void
    {
        self::guard('arvrs_license_reset');
        \ArvanReseller\Licensing\License::deactivate();
        Audit::log(0, 'license.reset', 'license', '', [], 'audit');
        self::back(__('فعال‌سازی افزونه بازنشانی شد. برای فروش دوباره، توکن دسترسی را وارد کنید.', 'arvan-reseller'));
    }
}
