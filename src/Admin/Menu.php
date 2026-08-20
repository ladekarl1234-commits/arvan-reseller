<?php
namespace ArvanReseller\Admin;

use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Licensing\License;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Admin experience: one top-level menu, focused sections (spec: reseller
 * admin dashboard). All pages require manage_options; templates are
 * server-rendered; actions go through Actions.php with nonces.
 */
final class Menu
{
    public static function register_hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        Actions::register_hooks();
    }

    public static function menu(): void
    {
        $cap  = 'manage_options';
        $slug = 'arvan-reseller';
        add_menu_page(__('سرویس ابری', 'arvan-reseller'), __('سرویس ابری', 'arvan-reseller'), $cap, $slug, [self::class, 'dashboard'], 'dashicons-cloud', 56);

        $pages = [
            [$slug,               __('پیشخوان', 'arvan-reseller'),        $slug,                    'dashboard'],
            ['orders',            __('سفارش‌ها', 'arvan-reseller'),       $slug . '-orders',        'orders'],
            ['customers',         __('مشتریان', 'arvan-reseller'),        $slug . '-customers',     'customers'],
            ['services',          __('سرویس‌ها', 'arvan-reseller'),       $slug . '-services',      'services'],
            ['credentials',       __('اتصال ArvanCloud', 'arvan-reseller'), $slug . '-credentials', 'credentials'],
            ['pricing',           __('قیمت‌گذاری', 'arvan-reseller'),     $slug . '-pricing',       'pricing'],
            ['policies',          __('سیاست اعتبار', 'arvan-reseller'),   $slug . '-policies',      'policies'],
            ['branding',          __('برند و تنظیمات', 'arvan-reseller'), $slug . '-branding',      'branding'],
            ['health',            __('سلامت سیستم', 'arvan-reseller'),    $slug . '-health',        'health'],
            ['audit',             __('گزارش امنیتی', 'arvan-reseller'),   $slug . '-audit',         'audit'],
        ];
        foreach ($pages as [$key, $title, $page_slug, $method]) {
            if ($key === $slug) {
                continue;
            }
            add_submenu_page($slug, $title, $title, $cap, $page_slug, [self::class, $method]);
        }
        // Hidden wizard page (linked from activation redirect).
        add_submenu_page('', __('راه‌اندازی', 'arvan-reseller'), '', $cap, $slug . '-setup', ['ArvanReseller\\Onboarding\\Wizard', 'render']);
    }

    public static function assets(string $hook): void
    {
        if (strpos($hook, 'arvan-reseller') === false) {
            return; // plugin admin pages only (spec §12)
        }
        wp_enqueue_style('arvrs-admin', ARVRS_URL . 'assets/css/admin.css', [], ARVRS_VERSION);
    }

    private static function render(string $template, array $vars = []): void
    {
        echo Helpers::view('admin/' . $template, $vars); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape at sink
    }

    // ----- page callbacks -------------------------------------------------

    public static function dashboard(): void
    {
        global $wpdb;
        $orders_table = OrderService::table();
        // In real operation, demo orders must never inflate revenue/margin
        // (spec §11). In demo mode we intentionally show them so judges see
        // the numbers.
        $demo_filter = Plugin::demo_mode() ? '' : ' AND is_demo = 0';
        $revenue = (int) $wpdb->get_var("SELECT COALESCE(SUM(amount),0) FROM $orders_table WHERE status IN ('paid','provisioning','active')$demo_filter");
        $cost    = (int) $wpdb->get_var("SELECT COALESCE(SUM(base_cost),0) FROM $orders_table WHERE status IN ('paid','provisioning','active')$demo_filter");
        $margin  = (int) $wpdb->get_var("SELECT COALESCE(SUM(margin),0) FROM $orders_table WHERE status IN ('paid','provisioning','active')$demo_filter");
        $failed  = (int) $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'provision_failed'$demo_filter");
        $ledger  = Ledger::table();
        $credit  = (int) $wpdb->get_var("SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM $ledger");
        $negatives = array_filter(Ledger::reconciliation(200), static function ($row) {
            return (int) $row['available'] < 0;
        });

        self::render('dashboard', [
            'licensed'   => License::is_active(),
            'demo'       => Plugin::demo_mode(),
            'customers'  => (int) (count_users()['avail_roles'][Customers::ROLE] ?? 0),
            'services'   => Services::count_by_status(),
            'orders'     => [
                'total'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $orders_table"),
                'active' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $orders_table WHERE status = 'active'"),
                'failed' => $failed,
            ],
            'revenue'    => $revenue,
            'cost'       => $cost,
            'margin'     => $margin,
            'customer_credit' => $credit,
            'negatives'  => $negatives,
            'jobs'       => JobRunner::stats(),
            'recent'     => Audit::recent(8),
            'notices'    => \ArvanReseller\Notifications\Notifier::for_user(0, 6),
        ]);
    }

    public static function orders(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $status = sanitize_key($_GET['status'] ?? '');
        $view   = (int) ($_GET['order'] ?? 0);
        // phpcs:enable
        if ($view) {
            $order = OrderService::get($view);
            self::render('order-detail', [
                'order'   => $order,
                'events'  => $order ? OrderService::events($view) : [],
                'service' => $order ? Services::by_order($view) : null,
                'customer' => $order ? get_userdata((int) $order['customer_id']) : null,
            ]);
            return;
        }
        self::render('orders', [
            'orders' => OrderService::list(0, $status, $page, 20),
            'status' => $status,
            'page'   => $page,
        ]);
    }

    public static function customers(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $view = (int) ($_GET['customer'] ?? 0);
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $page  = max(1, (int) ($_GET['paged'] ?? 1));
        // phpcs:enable
        if ($view) {
            $user = get_userdata($view);
            self::render('customer-detail', [
                'customer' => $user,
                'balance'  => Ledger::balance($view),
                'stage'    => (string) get_user_meta($view, 'arvrs_policy_stage', true) ?: 'healthy',
                'rules'    => Rules::get($view),
                'orders'   => OrderService::list($view, '', 1, 10),
                'services' => Services::list($view, 1, 20),
                'ledger'   => Ledger::entries($view, 1, 20),
            ]);
            return;
        }
        $query = new \WP_User_Query([
            'role'    => Customers::ROLE,
            'number'  => 20,
            'paged'   => $page,
            'search'  => $search ? '*' . $search . '*' : '',
            'orderby' => 'ID',
            'order'   => 'DESC',
        ]);
        $rows = [];
        foreach ($query->get_results() as $user) {
            $balance = Ledger::balance($user->ID);
            $rows[]  = [
                'id'      => $user->ID,
                'name'    => $user->display_name,
                'email'   => $user->user_email,
                'balance' => $balance['available'],
                'stage'   => (string) get_user_meta($user->ID, 'arvrs_policy_stage', true) ?: 'healthy',
                'registered' => $user->user_registered,
            ];
        }
        self::render('customers', ['customers' => $rows, 'search' => $search, 'page' => $page, 'total' => (int) $query->get_total()]);
    }

    public static function services(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $page = max(1, (int) ($_GET['paged'] ?? 1));
        $services = Services::list(0, $page, 20);
        foreach ($services as &$service) {
            $user = get_userdata((int) $service['customer_id']);
            $service['customer'] = $user ? $user->display_name : '#' . $service['customer_id'];
        }
        self::render('services', ['services' => $services, 'page' => $page]);
    }

    public static function credentials(): void
    {
        self::render('credentials', [
            'credentials'    => Credentials::all(),
            'crypto_ok'      => \ArvanReseller\Support\Crypto::available(),
            'reconciliation' => Ledger::reconciliation_by_credential(),
        ]);
    }

    public static function pricing(): void
    {
        self::render('pricing', [
            'global_markup'    => (float) Options::get('global_markup', 20.0),
            'product_markup'   => (array) Options::get('product_markup', []),
            'fixed_adjustment' => (int) Options::get('fixed_adjustment', 0),
            'base_costs'       => BaseCosts::all(),
        ]);
    }

    public static function policies(): void
    {
        self::render('policies', [
            'warning'  => (int) Options::get('policy_warning', 500000),
            'critical' => (int) Options::get('policy_critical', 100000),
            'grace'    => (int) Options::get('policy_grace_days', 3),
            'actions'  => (array) Options::get('policy_actions', []),
        ]);
    }

    public static function branding(): void
    {
        self::render('branding', [
            'brand_name'  => (string) Options::get('brand_name', ''),
            'brand_logo_id' => (int) Options::get('brand_logo_id', 0),
            'brand_description' => (string) Options::get('brand_description', ''),
            'brand_about' => (string) Options::get('brand_about', ''),
            'support_email' => (string) Options::get('support_email', ''),
            'support_phone' => (string) Options::get('support_phone', ''),
            'brand_color' => (string) Options::get('brand_color', '#0c6960'),
            'demo_mode'   => (bool) Options::get('demo_mode', true),
            'has_verified' => Credentials::has_verified_credential(),
            'retention'   => (bool) Options::get('data_retention_on_uninstall', true),
            'license'     => License::status(),
        ]);
    }

    public static function health(): void
    {
        global $wpdb;
        self::render('health', [
            'plugin_version' => ARVRS_VERSION,
            'schema_version' => (int) get_option('arvrs_schema_version', 0),
            'schema_target'  => ARVRS_SCHEMA_VERSION,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
            'mysql_version'  => $wpdb->db_version(),
            'sodium'         => \ArvanReseller\Support\Crypto::available(),
            'cron_disabled'  => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_jobs_run'  => wp_next_scheduled('arvrs_run_jobs'),
            'next_usage_run' => wp_next_scheduled('arvrs_usage_sync'),
            'last_usage_sync' => (string) get_option('arvrs_last_usage_sync', ''),
            'jobs'           => JobRunner::stats(),
            'failed_jobs'    => JobRunner::failed(10),
            'pages'          => PageFactory::status(),
            'credentials'    => Credentials::all(),
            'demo'           => Plugin::demo_mode(),
            'payment_provider' => Plugin::payments()->label(),
            'errors'         => Audit::recent(10, 'error'),
        ]);
    }

    public static function audit(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $level = sanitize_key($_GET['level'] ?? '');
        self::render('audit', ['rows' => Audit::recent(100, $level), 'level' => $level]);
    }
}
