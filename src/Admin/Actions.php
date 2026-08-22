<?php
namespace ArvanReseller\Admin;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Arvan\RealProvider;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Install\Schema;
use ArvanReseller\Jobs\Handlers;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Services\Services;
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
        'arvrs_job_action', 'arvrs_run_jobs', 'arvrs_sync_now', 'arvrs_flush_catalog',
        'arvrs_license_reset', 'arvrs_service_action', 'arvrs_notification_action',
        'arvrs_import_plans', 'arvrs_audit_export', 'arvrs_prune_now', 'arvrs_invoice_save',
    ];

    public static function register_hooks(): void
    {
        foreach (self::ACTIONS as $action) {
            add_action('admin_post_' . $action, [self::class, substr($action, 6)]);
        }
    }

    /** Shared guard: capability + nonce. */
    private static function guard(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی غیرمجاز.', 'arvan-reseller'), 403);
        }
        check_admin_referer($action, 'arvrs_nonce');
    }

    /**
     * Redirect back to the referring screen with a one-shot message.
     *
     * Terminates the request — and every call site still writes an explicit
     * `return` after it. Handlers used to be a chain of `if (…) { … back(); }`
     * blocks with no returns at all, so their correctness rested entirely on
     * this function's hidden `exit`: adding a shutdown hook or an early return
     * here would have turned every one of them into fall-through, and a
     * successful «cancel» would also have emitted "unknown operation".
     */
    private static function back(string $notice = '', string $error = ''): void
    {
        if ($notice !== '') {
            Flash::notice($notice);
        }
        if ($error !== '') {
            Flash::error($error);
        }
        $url = wp_get_referer() ?: admin_url('admin.php?page=' . Menu::SLUG);
        // The old flash mechanism echoed these back out of the URL; strip any
        // that an attacker-supplied referer still carries.
        $url = remove_query_arg(['arvrs_notice', 'arvrs_error'], $url);
        wp_safe_redirect($url);
        exit;
    }

    public static function save_branding(): void
    {
        self::guard('arvrs_save_branding');

        // The brand colour paints every white-on-brand surface in the product
        // (CTA, chip, active nav, focus ring). `sanitize_hex_color` only
        // validates the format, so a light teal or lime silently dropped all
        // of them below AA. Keep the hue, darken until white clears 4.5:1, and
        // say so — never repaint the storefront illegibly without a word.
        $submitted = sanitize_hex_color(wp_unslash($_POST['brand_color'] ?? '')) ?: Brand::FALLBACK;
        $brand     = Brand::accessible($submitted);

        Options::set_many([
            'brand_name'        => sanitize_text_field(wp_unslash($_POST['brand_name'] ?? '')),
            'brand_description' => sanitize_text_field(wp_unslash($_POST['brand_description'] ?? '')),
            'brand_about'       => sanitize_textarea_field(wp_unslash($_POST['brand_about'] ?? '')),
            'support_email'     => sanitize_email(wp_unslash($_POST['support_email'] ?? '')),
            'support_phone'     => preg_replace('/[^0-9+\-\s]/', '', (string) wp_unslash($_POST['support_phone'] ?? '')),
            'brand_color'       => $brand['color'],
            'demo_mode'         => !empty($_POST['demo_mode']),
            'data_retention_on_uninstall' => !empty($_POST['data_retention']),
        ]);
        Plugin::flush_mode_cache();

        // Logo upload through the WP media pipeline, hard-restricted (SEC-8).
        if (!empty($_FILES['brand_logo']['name'])) {
            $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            if ((int) ($_FILES['brand_logo']['size'] ?? 0) > MB_IN_BYTES) {
                self::back('', __('حجم لوگو نباید بیش از ۱ مگابایت باشد.', 'arvan-reseller'));
                return;
            }
            $check = wp_check_filetype_and_ext($_FILES['brand_logo']['tmp_name'], $_FILES['brand_logo']['name'], $allowed); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            if (empty($check['type']) || !isset($allowed[$check['type']])) {
                self::back('', __('فرمت لوگو باید PNG، JPG یا WebP باشد.', 'arvan-reseller'));
                return;
            }
            $id = media_handle_upload('brand_logo', 0, [], ['test_form' => false, 'mimes' => array_flip($allowed)]);
            if (!is_wp_error($id)) {
                Options::set('brand_logo_id', (int) $id);
            }
        }
        Audit::log(0, 'branding.saved', 'settings', '', [
            'demo_mode'      => !empty($_POST['demo_mode']),
            'brand_color'    => $brand['color'],
            'color_adjusted' => $brand['adjusted'],
        ]);

        if ($brand['adjusted']) {
            self::back(sprintf(
                /* translators: 1: submitted hex, 2: stored hex, 3: contrast ratio */
                __('تنظیمات برند ذخیره شد. رنگ %1$s برای متن سفید کنتراست کافی نداشت و به %2$s (نسبت %3$s:۱) تیره شد تا خوانایی حفظ شود.', 'arvan-reseller'),
                $brand['submitted'],
                $brand['color'],
                number_format_i18n($brand['ratio'], 2)
            ));
            return;
        }
        self::back(__('تنظیمات برند ذخیره شد.', 'arvan-reseller'));
        return;
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
        Menu::flush_dashboard_cache();
        Audit::log(0, 'pricing.saved', 'settings', '', ['global_markup' => (float) wp_unslash($_POST['global_markup'] ?? 20)]);
        self::back(__('قیمت‌گذاری ذخیره شد.', 'arvan-reseller'));
        return;
    }

    /**
     * Pull every upstream plan id that has no base-cost row into the pricing
     * table at cost 0.
     *
     * Upstream flavor ids (`GET /regions/{r}/sizes`) never match the seeded
     * demo ids, and an unpriced plan is dropped from the storefront and
     * rejected at checkout — so without this the first thing a real reseller
     * sees after onboarding is an empty shop, recoverable only by hand-typing
     * every ArvanCloud flavor id into a one-row form.
     */
    public static function import_plans(): void
    {
        self::guard('arvrs_import_plans');
        $product = sanitize_key($_POST['product'] ?? '');
        if (!in_array($product, Catalog::PRODUCTS, true)) {
            self::back('', __('محصول نامعتبر است.', 'arvan-reseller'));
            return;
        }
        $credential = Credentials::select_for($product);
        if (!$credential) {
            self::back('', __('برای درون‌ریزی پلن‌ها ابتدا یک اتصال ArvanCloud فعال ثبت کنید.', 'arvan-reseller'));
            return;
        }
        try {
            $plans = (new RealProvider($credential))->importable_plans($product);
        } catch (ProviderError $e) {
            Audit::error('pricing.import_failed', ['product' => $product, 'kind' => $e->kind]);
            self::back('', sprintf(__('دریافت پلن‌ها از آروان ناموفق بود: %s', 'arvan-reseller'), $e->customer_message()));
            return;
        } catch (\Throwable $e) {
            Audit::error('pricing.import_failed', ['product' => $product, 'error' => $e->getMessage()]);
            self::back('', __('دریافت پلن‌ها از آروان ناموفق بود.', 'arvan-reseller'));
            return;
        }

        $imported = 0;
        foreach ($plans as $plan) {
            $plan_id = sanitize_text_field((string) ($plan['plan_id'] ?? ''));
            if ($plan_id === '' || BaseCosts::get($product, $plan_id) > 0) {
                continue;
            }
            // Cost 0 is deliberate: it makes the row visible and editable on
            // this screen while keeping the plan out of the storefront until
            // the reseller has actually priced it.
            BaseCosts::set($product, $plan_id, 0, 'arvan import @ ' . gmdate('Y-m-d'));
            $imported++;
        }
        Catalog::flush();
        Audit::log(0, 'pricing.imported', 'settings', $product, ['imported' => $imported]);
        self::back(sprintf(
            __('%s پلن از آروان درون‌ریزی شد. هزینه پایه هرکدام را وارد کنید تا قابل فروش شوند.', 'arvan-reseller'),
            number_format_i18n($imported)
        ));
        return;
    }

    /** The reseller's real upstream bill for a month, so margin can be checked against it. */
    public static function invoice_save(): void
    {
        self::guard('arvrs_invoice_save');
        $month  = sanitize_text_field(wp_unslash($_POST['month'] ?? ''));
        $amount = max(0, (int) wp_unslash($_POST['invoice_amount'] ?? 0));
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            self::back('', __('ماه نامعتبر است.', 'arvan-reseller'));
            return;
        }
        $invoices = (array) get_option('arvrs_upstream_invoice', []);
        $invoices[$month] = $amount;
        update_option('arvrs_upstream_invoice', $invoices, false);
        Audit::log(0, 'reports.invoice_saved', 'settings', $month, ['amount' => $amount]);
        self::back(__('صورت‌حساب آروان ثبت شد.', 'arvan-reseller'));
        return;
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
        return;
    }

    public static function credential_save(): void
    {
        self::guard('arvrs_credential_save');
        if (!\ArvanReseller\Support\Crypto::available()) {
            self::back('', __('افزونه sodium در PHP فعال نیست؛ ذخیره امن توکن ممکن نیست.', 'arvan-reseller'));
            return;
        }
        $id    = (int) ($_POST['credential_id'] ?? 0);
        $token = trim((string) wp_unslash($_POST['api_token'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secret, encrypted not echoed
        if (strlen($token) > 256) {
            self::back('', __('توکن API نامعتبر است.', 'arvan-reseller'));
            return;
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
            return;
        }
        Plugin::flush_mode_cache();
        self::back(__('اتصال ArvanCloud ذخیره شد. برای اطمینان، «آزمایش اتصال‌ها» را بزنید.', 'arvan-reseller'));
        return;
    }

    public static function credential_delete(): void
    {
        self::guard('arvrs_credential_delete');
        $deleted = Credentials::delete((int) ($_POST['credential_id'] ?? 0));
        Plugin::flush_mode_cache();
        // Credentials::delete() returns false — and refuses the delete — when
        // live services still reference the credential. Discarding that used
        // to flash "حذف شد" while the token stayed enabled and kept routing
        // provisioning, so an operator believing a compromised token was gone
        // never actually revoked it.
        if (!$deleted) {
            self::back('', __('حذف اتصال ممکن نیست: سرویس‌های فعال هنوز از آن استفاده می‌کنند. ابتدا آن‌ها را غیرفعال یا به اتصال دیگری منتقل کنید.', 'arvan-reseller'));
            return;
        }
        self::back(__('اتصال حذف شد.', 'arvan-reseller'));
        return;
    }

    /**
     * Run the same connection test the daily `credential_health` job runs.
     *
     * This handler used to `SELECT token_enc` and decrypt it itself, which put
     * secret handling in the presentation layer and meant any change to
     * storage or encryption had to be chased into wp-admin. The job owns that
     * loop; the button just runs it now, and it records freshness and emits
     * the `credential_failed` alert for free.
     */
    public static function credential_test(): void
    {
        self::guard('arvrs_credential_test');
        if (!Credentials::all()) {
            self::back('', __('اتصالی ثبت نشده است.', 'arvan-reseller'));
            return;
        }
        Handlers::credential_health([]);

        $failed = 0;
        foreach (Credentials::all() as $credential) {
            if (!empty($credential['enabled']) && !empty($credential['last_error'])) {
                $failed++;
            }
        }
        if ($failed) {
            self::back('', sprintf(
                __('آزمایش انجام شد؛ %s اتصال با خطا مواجه شد. جزئیات در جدول زیر آمده است.', 'arvan-reseller'),
                number_format_i18n($failed)
            ));
            return;
        }
        self::back(__('همه اتصال‌های فعال با موفقیت آزمایش شدند.', 'arvan-reseller'));
        return;
    }

    public static function rules_save(): void
    {
        self::guard('arvrs_rules_save');
        $customer_id = (int) ($_POST['customer_id'] ?? 0);
        if (!$customer_id || !\ArvanReseller\Identity\Customers::is_customer($customer_id)) {
            self::back('', __('مشتری یافت نشد.', 'arvan-reseller'));
            return;
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
        return;
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
            return;
        }
        try {
            Ledger::append($customer_id, $kind, $amount, 'admin', 'adj-' . bin2hex(random_bytes(6)),
                $note ?: __('اصلاح دستی توسط مدیر', 'arvan-reseller'), 'admin:' . get_current_user_id());
        } catch (\Throwable $e) {
            Audit::error('wallet.adjust_failed', ['customer' => $customer_id, 'error' => $e->getMessage()]);
            self::back('', __('خطای موقت در ثبت تراکنش. دوباره تلاش کنید.', 'arvan-reseller'));
            return;
        }
        UsageSync::apply_policy($customer_id);
        Menu::flush_dashboard_cache();
        Audit::log(0, 'wallet.adjusted', 'user', (string) $customer_id, ['kind' => $kind, 'amount' => $amount]);
        self::back(__('تراکنش ثبت شد.', 'arvan-reseller'));
        return;
    }

    public static function order_action(): void
    {
        self::guard('arvrs_order_action');
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $do       = sanitize_key($_POST['do'] ?? '');
        $order    = OrderService::get($order_id);
        if (!$order) {
            self::back('', __('سفارش یافت نشد.', 'arvan-reseller'));
            return;
        }
        Menu::flush_dashboard_cache();

        switch ($do) {
            case 'reclaim':
                // An order stuck in `provisioning` could not be moved by any
                // admin action: provision() refuses to claim it and the retry
                // job reported success. This runs the reaper against that one
                // order — it completes it when the service actually exists and
                // fails it otherwise, which is what unlocks retry and refund.
                $moved = Provisioner::reclaim_stale(0, $order_id);
                Audit::log(0, 'order.reclaimed', 'order', (string) $order_id, ['moved' => $moved]);
                if (!$moved) {
                    self::back('', __('این سفارش در وضعیت «در حال راه‌اندازی» نیست یا هم‌زمان توسط فرآیند دیگری تغییر کرد.', 'arvan-reseller'));
                    return;
                }
                $after = OrderService::get($order_id);
                if ($after && $after['status'] === StateMachine::ACTIVE) {
                    self::back(__('سرویس این سفارش موجود بود؛ سفارش به وضعیت «فعال» رسید.', 'arvan-reseller'));
                    return;
                }
                self::back(__('سفارش آزاد شد و اکنون قابل «تلاش دوباره راه‌اندازی» یا بازپرداخت است.', 'arvan-reseller'));
                return;

            case 'retry_provision':
                // Queue first, then try inline: the durable job is the
                // guarantee (it survives a fatal here) and the inline attempt
                // is only so the operator sees the outcome immediately. The
                // job is idempotent, so both running is not a double-provision.
                JobRunner::enqueue('provision_order', ['order_id' => $order_id]);
                try {
                    $result = Provisioner::provision($order_id);
                } catch (\Throwable $e) {
                    Audit::error('order.retry_failed', ['order' => $order_id, 'error' => $e->getMessage()]);
                    self::back('', __('راه‌اندازی همچنان ناموفق است؛ در پس‌زمینه دوباره تلاش می‌شود.', 'arvan-reseller'));
                    return;
                }
                if (!empty($result['ok'])) {
                    self::back(__('راه‌اندازی با موفقیت انجام شد.', 'arvan-reseller'));
                    return;
                }
                self::back('', (string) $result['message']);
                return;

            case 'refund':
                $from = (string) $order['status'];
                if (!StateMachine::can($from, StateMachine::REFUNDED)) {
                    self::back('', __('بازپرداخت از این وضعیت مجاز نیست.', 'arvan-reseller'));
                    return;
                }
                // Route through the same guard the gateway refund path uses
                // (PaymentService::refund_order): it refuses when the
                // settlement `purchase` debit is missing, so an admin refund
                // can never mint wallet credit backed by no debit — a
                // duplicated Ledger::append() here had none of that guard.
                $result = PaymentService::refund_order($order, 'admin:' . get_current_user_id());
                if (!$result['ok']) {
                    self::back('', $result['message']);
                    return;
                }
                OrderService::transition($order_id, $from, StateMachine::REFUNDED, 'admin:' . get_current_user_id(), 'manual refund');
                Audit::log(0, 'order.refunded', 'order', (string) $order_id, ['amount' => (int) $order['amount']]);
                self::back(__('سفارش بازپرداخت شد و مبلغ به کیف پول مشتری برگشت.', 'arvan-reseller'));
                return;

            case 'cancel':
                $ok = OrderService::transition($order_id, (string) $order['status'], StateMachine::CANCELLED, 'admin:' . get_current_user_id(), 'manual cancel');
                if ($ok) {
                    self::back(__('سفارش لغو شد.', 'arvan-reseller'));
                    return;
                }
                self::back('', __('لغو از این وضعیت مجاز نیست.', 'arvan-reseller'));
                return;
        }
        self::back('', __('عملیات ناشناخته.', 'arvan-reseller'));
        return;
    }

    /**
     * Per-service operations. Until now this screen was read-only, so a
     * resource deleted or changed upstream drifted from the local row forever
     * and every correction was a database edit.
     */
    public static function service_action(): void
    {
        self::guard('arvrs_service_action');
        $service_id = (int) ($_POST['service_id'] ?? 0);
        $do         = sanitize_key($_POST['do'] ?? '');
        $service    = Services::get($service_id);
        if (!$service) {
            self::back('', __('سرویس یافت نشد.', 'arvan-reseller'));
            return;
        }
        $product   = (string) $service['product'];
        $remote_id = (string) $service['remote_id'];

        switch ($do) {
            case 'resync':
                if ($remote_id === '') {
                    self::back('', __('این سرویس شناسه ابری ندارد؛ چیزی برای همگام‌سازی نیست.', 'arvan-reseller'));
                    return;
                }
                try {
                    $remote = Plugin::arvan($product)->status($product, $remote_id);
                } catch (ProviderError $e) {
                    Audit::error('service.resync_failed', ['service' => $service_id, 'kind' => $e->kind]);
                    self::back('', sprintf(__('دریافت وضعیت از آروان ناموفق بود: %s', 'arvan-reseller'), $e->customer_message()));
                    return;
                } catch (\Throwable $e) {
                    Audit::error('service.resync_failed', ['service' => $service_id, 'error' => $e->getMessage()]);
                    self::back('', __('دریافت وضعیت از آروان ناموفق بود.', 'arvan-reseller'));
                    return;
                }
                // 'creating' means nothing new is known yet, so the local
                // status is left alone rather than overwritten with a guess.
                $map    = ['active' => 'active', 'suspended' => 'suspended', 'failed' => 'at_risk'];
                $status = $map[$remote->status] ?? '';
                Services::update_connection($service_id, $remote->connection, $status);
                Services::mark_synced($service_id);
                Audit::log(0, 'service.resynced', 'service', (string) $service_id, ['remote_status' => $remote->status]);
                self::back(sprintf(__('وضعیت سرویس #%1$d از آروان به‌روزرسانی شد (وضعیت ابری: %2$s).', 'arvan-reseller'), $service_id, $remote->status));
                return;

            case 'suspend':
                Services::set_status($service_id, 'suspended');
                Audit::log(0, 'service.suspended', 'service', (string) $service_id, [], 'audit');
                self::back(sprintf(__('سرویس #%d معلق شد.', 'arvan-reseller'), $service_id));
                return;

            case 'resume':
                Services::set_status($service_id, 'active');
                Audit::log(0, 'service.resumed', 'service', (string) $service_id, [], 'audit');
                self::back(sprintf(__('سرویس #%d دوباره فعال شد.', 'arvan-reseller'), $service_id));
                return;

            case 'cancel_renewal':
                if (!Services::cancel_renewal($service_id)) {
                    self::back('', __('این سرویس تمدید فعالی ندارد.', 'arvan-reseller'));
                    return;
                }
                Audit::log(0, 'service.renewal_cancelled', 'service', (string) $service_id, [], 'audit');
                self::back(sprintf(__('تمدید خودکار سرویس #%d متوقف شد؛ سرویس تا پایان دوره فعال می‌ماند.', 'arvan-reseller'), $service_id));
                return;

            case 'terminate':
                // Typed confirmation, not a JS confirm(): this destroys the
                // customer's resource upstream and is not undoable.
                if ((int) wp_unslash($_POST['confirm'] ?? 0) !== $service_id) {
                    self::back('', __('برای حذف سرویس باید شناسه آن را دقیقاً وارد کنید.', 'arvan-reseller'));
                    return;
                }
                $deleted = false;
                if ($remote_id !== '') {
                    try {
                        $deleted = Plugin::arvan($product)->delete($product, $remote_id);
                    } catch (ProviderError $e) {
                        Audit::error('service.terminate_failed', ['service' => $service_id, 'kind' => $e->kind]);
                        self::back('', sprintf(__('حذف منبع در آروان ناموفق بود: %s — وضعیت محلی تغییر نکرد.', 'arvan-reseller'), $e->customer_message()));
                        return;
                    } catch (\Throwable $e) {
                        Audit::error('service.terminate_failed', ['service' => $service_id, 'error' => $e->getMessage()]);
                        self::back('', __('حذف منبع در آروان ناموفق بود؛ وضعیت محلی تغییر نکرد.', 'arvan-reseller'));
                        return;
                    }
                }
                // Local state moves only after the remote delete succeeded, so
                // the row never claims a resource is gone while it still bills.
                Services::terminate($service_id);
                Menu::flush_dashboard_cache();
                Audit::log(0, 'service.terminated', 'service', (string) $service_id, ['remote_deleted' => $deleted], 'audit');
                self::back(sprintf(__('سرویس #%d حذف شد و تمدید آن متوقف گردید.', 'arvan-reseller'), $service_id));
                return;
        }
        self::back('', __('عملیات ناشناخته.', 'arvan-reseller'));
        return;
    }

    /** Job recovery: the only way a stranded `running` row could move was a manual UPDATE. */
    public static function job_action(): void
    {
        self::guard('arvrs_job_action');
        $do     = sanitize_key($_POST['do'] ?? 'retry');
        $job_id = (int) ($_POST['job_id'] ?? 0);

        switch ($do) {
            case 'reap':
                $reaped = JobRunner::reap_stale();
                Audit::log(0, 'job.reaped', 'job', '', ['count' => $reaped]);
                self::back(sprintf(__('%s وظیفه رهاشده آزاد شد.', 'arvan-reseller'), number_format_i18n($reaped)));
                return;

            case 'kill':
                if (!JobRunner::kill($job_id)) {
                    self::back('', __('این وظیفه قابل توقف نیست (احتمالاً پیش‌تر انجام شده است).', 'arvan-reseller'));
                    return;
                }
                self::back(sprintf(__('وظیفه #%d متوقف شد.', 'arvan-reseller'), $job_id));
                return;

            case 'retry':
                if (!JobRunner::retry($job_id)) {
                    self::back('', __('این وظیفه قابل صف‌بندی دوباره نیست.', 'arvan-reseller'));
                    return;
                }
                Audit::log(0, 'job.retried', 'job', (string) $job_id);
                self::back(sprintf(__('وظیفه #%d دوباره در صف قرار گرفت.', 'arvan-reseller'), $job_id));
                return;
        }
        self::back('', __('عملیات ناشناخته.', 'arvan-reseller'));
        return;
    }

    public static function notification_action(): void
    {
        self::guard('arvrs_notification_action');
        $do = sanitize_key($_POST['do'] ?? 'read');
        if ($do === 'read_all') {
            $marked = 0;
            foreach (\ArvanReseller\Notifications\Notifier::for_user(0, 200) as $row) {
                if ((int) $row['is_read'] === 0) {
                    \ArvanReseller\Notifications\Notifier::mark_read(0, (int) $row['id']);
                    $marked++;
                }
            }
            self::back(sprintf(__('%s اعلان خوانده‌شده علامت خورد.', 'arvan-reseller'), number_format_i18n($marked)));
            return;
        }
        $id = (int) ($_POST['notification_id'] ?? 0);
        if (!$id) {
            self::back('', __('اعلان یافت نشد.', 'arvan-reseller'));
            return;
        }
        \ArvanReseller\Notifications\Notifier::mark_read(0, $id);
        self::back(__('اعلان خوانده‌شده علامت خورد.', 'arvan-reseller'));
        return;
    }

    /**
     * Filtered audit export. Same capability + nonce as every other action;
     * the file streams instead of redirecting, so there is no flash message.
     */
    public static function audit_export(): void
    {
        self::guard('arvrs_audit_export');
        $args = [
            'level'       => sanitize_key($_POST['level'] ?? ''),
            'action'      => sanitize_text_field(wp_unslash($_POST['audit_action'] ?? '')),
            'object_type' => sanitize_key($_POST['object_type'] ?? ''),
            'object_id'   => sanitize_text_field(wp_unslash($_POST['object_id'] ?? '')),
            'user_id'     => (int) ($_POST['user_id'] ?? 0),
            'from'        => sanitize_text_field(wp_unslash($_POST['from'] ?? '')),
            'to'          => sanitize_text_field(wp_unslash($_POST['to'] ?? '')),
        ];
        $csv = Audit::export_csv($args);
        Audit::log(get_current_user_id(), 'audit.exported', 'audit_log', '', ['bytes' => strlen($csv)], 'audit');

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="arvan-audit-' . gmdate('Ymd-His') . '.csv"');
        // Excel reads UTF-8 Persian correctly only with a BOM.
        echo "\xEF\xBB\xBF" . $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV body, not HTML
        exit;
    }

    /** Retention run. The ledger is never pruned — it is the financial record. */
    public static function prune_now(): void
    {
        self::guard('arvrs_prune_now');
        $days = min(3650, max(7, (int) wp_unslash($_POST['retention_days'] ?? 90)));
        // The one-shot button and the nightly `prune` job (Handlers::prune)
        // used to read two different stores — this button wrote
        // `arvrs_retention_days` while the job read `Options::get('data_retention_days')`
        // — so setting 30 days here only ever changed what THIS click did; the
        // nightly job kept pruning at 90 forever.
        Options::set('data_retention_days', $days);

        $counts = Schema::prune($days);
        $counts['jobs'] = JobRunner::prune($days);
        update_option('arvrs_last_prune', ['at' => gmdate('Y-m-d H:i:s'), 'days' => $days, 'counts' => $counts], false);
        Audit::log(0, 'retention.pruned', 'system', '', $counts, 'audit');

        self::back(sprintf(
            __('پاک‌سازی انجام شد — گزارش: %1$s، اعلان: %2$s، وظیفه: %3$s، بار خام مصرف: %4$s ردیف.', 'arvan-reseller'),
            number_format_i18n((int) $counts['audit']),
            number_format_i18n((int) $counts['notifications']),
            number_format_i18n((int) $counts['jobs']),
            number_format_i18n((int) $counts['usage_raw'])
        ));
        return;
    }

    public static function run_jobs(): void
    {
        self::guard('arvrs_run_jobs');
        $ran = JobRunner::run_due();
        self::back(sprintf(__('%s وظیفه اجرا شد.', 'arvan-reseller'), number_format_i18n($ran)));
        return;
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
        return;
    }

    public static function flush_catalog(): void
    {
        self::guard('arvrs_flush_catalog');
        Catalog::flush();
        Menu::flush_dashboard_cache();
        self::back(__('کش کاتالوگ خالی شد.', 'arvan-reseller'));
        return;
    }

    public static function license_reset(): void
    {
        self::guard('arvrs_license_reset');
        \ArvanReseller\Licensing\License::deactivate();
        Audit::log(0, 'license.reset', 'license', '', [], 'audit');
        self::back(__('فعال‌سازی افزونه بازنشانی شد. برای فروش دوباره، توکن دسترسی را وارد کنید.', 'arvan-reseller'));
        return;
    }
}
