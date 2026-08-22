<?php
namespace ArvanReseller\Admin;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Install\Schema;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Licensing\License;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Reports\Reports;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Admin experience: one top-level menu, focused sections (spec: reseller
 * admin dashboard). All pages require manage_options; templates are
 * server-rendered; actions go through Actions.php with nonces.
 *
 * Nothing here talks to the database directly any more: the presentation
 * layer asks the module that owns the table (Reports, OrderService, Ledger,
 * Audit, Credentials, JobRunner) instead of writing its own SQL, so a change
 * to storage or encryption never has to be chased into wp-admin.
 */
final class Menu
{
    public const SLUG = 'arvan-reseller';

    /** Dashboard aggregates are the most-refreshed page in the plugin. */
    private const DASH_TTL = 300;

    public static function register_hooks(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        Actions::register_hooks();
    }

    /** Unread admin alerts, for the menu bubble. */
    public static function unread(): int
    {
        return Notifier::unread_count(0);
    }

    public static function menu(): void
    {
        $cap    = 'manage_options';
        $slug   = self::SLUG;
        $unread = self::unread();

        // The alert feed had no entry point at all: every provisioning
        // failure, dead job and at-risk customer was written to the database
        // and rendered nowhere. The bubble is the same affordance WordPress
        // uses for plugin updates, so it needs no explaining.
        $bubble = $unread
            ? ' <span class="update-plugins count-' . (int) $unread . '"><span class="plugin-count">'
                . esc_html(number_format_i18n($unread)) . '</span></span>'
            : '';

        add_menu_page(
            __('سرویس ابری', 'arvan-reseller'),
            __('سرویس ابری', 'arvan-reseller') . $bubble,
            $cap,
            $slug,
            [self::class, 'dashboard'],
            'dashicons-cloud',
            56
        );

        $pages = [
            ['orders',        __('سفارش‌ها', 'arvan-reseller'),        $slug . '-orders',        'orders'],
            ['customers',     __('مشتریان', 'arvan-reseller'),         $slug . '-customers',     'customers'],
            ['services',      __('سرویس‌ها', 'arvan-reseller'),        $slug . '-services',      'services'],
            ['reports',       __('گزارش مالی', 'arvan-reseller'),      $slug . '-reports',       'reports',       ''],
            ['notifications', __('اعلان‌ها', 'arvan-reseller'),        $slug . '-notifications', 'notifications', $bubble],
            ['credentials',   __('اتصال ArvanCloud', 'arvan-reseller'), $slug . '-credentials',  'credentials'],
            ['pricing',       __('قیمت‌گذاری', 'arvan-reseller'),      $slug . '-pricing',       'pricing'],
            ['policies',      __('سیاست اعتبار', 'arvan-reseller'),    $slug . '-policies',      'policies'],
            ['branding',      __('برند و تنظیمات', 'arvan-reseller'),  $slug . '-branding',      'branding'],
            ['health',        __('سلامت سیستم', 'arvan-reseller'),     $slug . '-health',        'health'],
            ['audit',         __('گزارش امنیتی', 'arvan-reseller'),    $slug . '-audit',         'audit'],
        ];
        // First submenu entry mirrors the parent so «پیشخوان» is nameable.
        add_submenu_page($slug, __('پیشخوان', 'arvan-reseller'), __('پیشخوان', 'arvan-reseller'), $cap, $slug, [self::class, 'dashboard']);
        foreach ($pages as $page) {
            // menu_title may carry the unread bubble markup; page_title (the
            // browser <title>) must stay plain text.
            $menu_title = $page[1] . (isset($page[4]) ? $page[4] : '');
            add_submenu_page($slug, $page[1], $menu_title, $cap, $page[2], [self::class, $page[3]]);
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

        // admin.css asks for Vazirmatn but the @font-face rules live in
        // front.css, which admin pages never load — so the whole reseller
        // admin, including the onboarding wizard's 900-weight headings, fell
        // back to Segoe UI/Tahoma while the storefront rendered Vazirmatn.
        // The faces are bundled (assets/fonts, SIL OFL); this is the enqueue
        // that was missing, not a new asset.
        $fonts = ARVRS_URL . 'assets/fonts/';
        wp_add_inline_style('arvrs-admin', sprintf(
            '@font-face{font-family:Vazirmatn;src:url("%1$sVazirmatn-Regular.woff2") format("woff2");font-weight:400;font-display:swap;font-style:normal}'
            . '@font-face{font-family:Vazirmatn;src:url("%1$sVazirmatn-Medium.woff2") format("woff2");font-weight:500 600;font-display:swap;font-style:normal}'
            . '@font-face{font-family:Vazirmatn;src:url("%1$sVazirmatn-Bold.woff2") format("woff2");font-weight:700 900;font-display:swap;font-style:normal}',
            esc_url_raw($fonts)
        ));

        // admin.css derives its whole ramp from `--arvrs-brand`, but nothing
        // ever emitted that variable on admin pages — so a reseller who set
        // their brand colour got a recoloured storefront and a default-teal
        // admin. Same source and same accessibility clamp the front end uses
        // (Front\Assets), so the two halves cannot drift apart again.
        wp_add_inline_style(
            'arvrs-admin',
            ':root{--arvrs-brand:' . Brand::accessible((string) Options::get('brand_color', Options::BRAND_COLOR))['color'] . ';}'
        );
    }

    private static function render(string $template, array $vars = []): void
    {
        $vars += Flash::take();
        echo Helpers::view('admin/' . $template, $vars); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- templates escape at sink
    }

    /** Drop the cached dashboard aggregates after anything that changes them. */
    public static function flush_dashboard_cache(): void
    {
        delete_transient('arvrs_dash_real');
        delete_transient('arvrs_dash_demo');
    }

    // ----- page callbacks -------------------------------------------------

    public static function dashboard(): void
    {
        $demo         = Plugin::demo_mode();
        $include_demo = $demo;

        // Six full-table aggregates (two of them whole-ledger scans) were run
        // on every render of the page an operator refreshes most. They change
        // slowly and none of them is used to make a decision inside the
        // request, so they are cached; Actions busts the key on every write
        // that can move them.
        $key   = $demo ? 'arvrs_dash_demo' : 'arvrs_dash_real';
        $stats = get_transient($key);
        // Shape check, not just is_array: a payload cached by an older build
        // would be missing keys the template now reads.
        if (!is_array($stats) || !isset($stats['period'], $stats['negatives'], $stats['services'])) {
            $month_start = gmdate('Y-m-01 00:00:00');
            $now         = gmdate('Y-m-d H:i:s');
            $period      = Reports::period($month_start, $now, $include_demo);
            // GROUP BY customer_id ORDER BY <computed> is a full scan plus a
            // filesort over the whole ledger; it belongs inside the cache, not
            // beside it.
            $negatives = array_values(array_filter(Ledger::reconciliation(200, $include_demo), static function ($row) {
                return (int) $row['available'] < 0;
            }));
            $stats = [
                'period'          => $period,
                'mrr'             => Reports::mrr($include_demo),
                'customer_credit' => Ledger::total_credit($include_demo),
                'customers'       => (int) (count_users()['avail_roles'][Customers::ROLE] ?? 0),
                'services'        => Services::count_by_status(),
                'negatives'       => $negatives,
                'from'            => $month_start,
                'to'              => $now,
            ];
            set_transient($key, $stats, self::DASH_TTL);
        }

        // The failed/stuck panel is computed from the same population as the
        // money cards (demo rows excluded in real mode) — the old KPI counted
        // demo orders next to a revenue figure that excluded them.
        // PAID is included alongside the two provisioning states: a paid
        // order whose provisioning job never ran (killed between the claim
        // and the enqueue, or a lost job) sits in `paid` indefinitely, and the
        // panel's own help text already promises "پرداخت شده اما تحویل
        // نشده" — paid-with-no-service is exactly that.
        $attention = [];
        foreach ([StateMachine::PROVISION_FAILED, StateMachine::PROVISIONING, StateMachine::PAID] as $state) {
            foreach (OrderService::list(0, $state, 1, 10) as $order) {
                if (!$include_demo && (int) $order['is_demo'] === 1) {
                    continue;
                }
                $attention[] = $order;
            }
        }

        self::render('dashboard', [
            'licensed'        => License::is_active(),
            'demo'            => $demo,
            'customers'       => (int) $stats['customers'],
            'services'        => (array) $stats['services'],
            'period'          => (array) $stats['period'],
            'mrr'             => (int) $stats['mrr'],
            'period_from'     => (string) $stats['from'],
            'customer_credit' => (int) $stats['customer_credit'],
            'negatives'       => (array) $stats['negatives'],
            'attention'       => $attention,
            'jobs'            => JobRunner::stats(),
            'recent'          => Audit::recent(8),
            'notices'         => Notifier::for_user(0, 6),
            'unread'          => self::unread(),
        ]);
    }

    public static function orders(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $status = sanitize_key($_GET['status'] ?? '');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $view   = (int) ($_GET['order'] ?? 0);
        // phpcs:enable
        if ($view) {
            $order = OrderService::get($view);
            self::render('order-detail', [
                'order'    => $order,
                'events'   => $order ? OrderService::events($view) : [],
                'service'  => $order ? Services::by_order($view) : null,
                'customer' => $order ? get_userdata((int) $order['customer_id']) : null,
            ]);
            return;
        }
        $total = OrderService::count(0, $status, $search);
        self::render('orders', [
            'orders' => OrderService::list(0, $status, $page, 20, $search),
            'status' => $status,
            'search' => $search,
            'page'   => $page,
            'total'  => $total,
        ]);
    }

    public static function customers(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $view   = (int) ($_GET['customer'] ?? 0);
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
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
        $users = $query->get_results();
        $ids   = [];
        foreach ($users as $user) {
            $ids[] = (int) $user->ID;
        }
        // ONE grouped query for the whole page. This loop used to call
        // Ledger::balance() per row — twenty unbounded ledger scans per render.
        $balances = Ledger::balances($ids);
        $rows     = [];
        foreach ($users as $user) {
            $id     = (int) $user->ID;
            $rows[] = [
                'id'         => $id,
                'name'       => $user->display_name,
                'email'      => $user->user_email,
                'balance'    => (int) ($balances[$id]['available'] ?? 0),
                'stage'      => (string) get_user_meta($id, 'arvrs_policy_stage', true) ?: 'healthy',
                'registered' => $user->user_registered,
            ];
        }
        self::render('customers', ['customers' => $rows, 'search' => $search, 'page' => $page, 'total' => (int) $query->get_total()]);
    }

    public static function services(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $status = sanitize_key($_GET['status'] ?? '');
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        // phpcs:enable
        // Filtered in SQL, not after the fact: filtering a fetched page in PHP
        // only ever checked the 20 rows already on that page, so a status with
        // matches outside that window reported a false "nothing found".
        $services = Services::list(0, $page, 20, $status, $search);
        $total    = Services::count(0, $status, $search);
        foreach ($services as &$service) {
            $user = get_userdata((int) $service['customer_id']);
            $service['customer'] = $user ? $user->display_name : '#' . $service['customer_id'];
        }
        unset($service);
        self::render('services', [
            'services' => $services,
            'page'     => $page,
            'status'   => $status,
            'search'   => $search,
            'total'    => $total,
            'demo'     => Plugin::demo_mode(),
        ]);
    }

    public static function notifications(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page   = max(1, (int) ($_GET['paged'] ?? 1));
        $type   = sanitize_key($_GET['type'] ?? '');
        $unread = !empty($_GET['unread']);
        // phpcs:enable
        $per_page = 25;

        // Notifier exposes a newest-first feed, not a filtered query, and the
        // table belongs to another module — so the window is read once,
        // bounded, and narrowed here. 400 rows covers months of admin alerts
        // at any realistic volume; older ones are in the audit log.
        $feed = Notifier::for_user(0, 400);

        $types = [];
        foreach ($feed as $row) {
            $types[(string) $row['type']] = true;
        }
        $filtered = array_values(array_filter($feed, static function ($row) use ($type, $unread) {
            if ($type !== '' && (string) $row['type'] !== $type) {
                return false;
            }
            return !$unread || (int) $row['is_read'] === 0;
        }));

        self::render('notifications', [
            'rows'     => array_slice($filtered, ($page - 1) * $per_page, $per_page),
            'total'    => count($filtered),
            'page'     => $page,
            'per_page' => $per_page,
            'type'     => $type,
            'unread'   => $unread,
            'types'    => array_keys($types),
            'unread_count' => self::unread(),
        ]);
    }

    public static function reports(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only report filters
        $preset = sanitize_key($_GET['period'] ?? 'this_month');
        $from   = sanitize_text_field(wp_unslash($_GET['from'] ?? ''));
        $to     = sanitize_text_field(wp_unslash($_GET['to'] ?? ''));
        // phpcs:enable
        [$from_utc, $to_utc, $preset] = self::resolve_period($preset, $from, $to);

        $include_demo = Plugin::demo_mode();
        $month_key    = substr($from_utc, 0, 7);
        // Not an Options:: key: Options carries a fixed whitelist owned by
        // another module, so the reseller's own bookkeeping lives beside it.
        $invoices = (array) get_option('arvrs_upstream_invoice', []);

        self::render('reports', [
            'preset'      => $preset,
            'from'        => $from_utc,
            'to'          => $to_utc,
            'demo'        => $include_demo,
            'period'      => Reports::period($from_utc, $to_utc, $include_demo),
            'by_product'  => Reports::by_product($from_utc, $to_utc, $include_demo),
            'monthly'     => Reports::monthly(12, $include_demo),
            'mrr'         => Reports::mrr($include_demo),
            'churn'       => Reports::churn($from_utc, $to_utc),
            'services'    => Services::count_by_status(),
            'month_key'   => $month_key,
            'invoice'     => (int) ($invoices[$month_key] ?? 0),
        ]);
    }

    /**
     * Period selector → a UTC half-open window [from, to).
     *
     * @return array{0:string,1:string,2:string}
     */
    private static function resolve_period(string $preset, string $from, string $to): array
    {
        $now = time();
        if ($preset === 'custom' && $from !== '' && $to !== '') {
            $f = strtotime($from . ' 00:00:00 UTC');
            $t = strtotime($to . ' 00:00:00 UTC');
            if ($f && $t && $t > $f) {
                return [gmdate('Y-m-d H:i:s', $f), gmdate('Y-m-d H:i:s', $t + DAY_IN_SECONDS), 'custom'];
            }
        }
        if ($preset === 'last_month') {
            $start = strtotime('-1 month', strtotime(gmdate('Y-m-01 00:00:00')));
            return [gmdate('Y-m-d H:i:s', $start), gmdate('Y-m-01 00:00:00'), 'last_month'];
        }
        if ($preset === 'last_90') {
            return [gmdate('Y-m-d H:i:s', $now - 90 * DAY_IN_SECONDS), gmdate('Y-m-d H:i:s', $now), 'last_90'];
        }
        if ($preset === 'year') {
            return [gmdate('Y-01-01 00:00:00'), gmdate('Y-m-d H:i:s', $now), 'year'];
        }
        return [gmdate('Y-m-01 00:00:00'), gmdate('Y-m-d H:i:s', $now), 'this_month'];
    }

    public static function credentials(): void
    {
        self::render('credentials', [
            'credentials'    => Credentials::all(),
            'crypto_ok'      => \ArvanReseller\Support\Crypto::available(),
            'reconciliation' => Ledger::reconciliation_by_credential(),
            'demo'           => Plugin::demo_mode(),
        ]);
    }

    public static function pricing(): void
    {
        $base_costs = BaseCosts::all();

        // A plan with no base cost is dropped from the storefront and rejected
        // at checkout, so this list is literally "what you cannot sell today".
        // Upstream flavor ids never match the seeded demo ids, which is what
        // made real-mode cloud servers unsellable with no visible cause.
        $unsellable = [];
        foreach ($base_costs as $row) {
            if ((int) $row['base_cost'] <= 0) {
                $unsellable[] = $row;
            }
        }

        self::render('pricing', [
            'global_markup'    => (float) Options::get('global_markup', 20.0),
            'product_markup'   => (array) Options::get('product_markup', []),
            'fixed_adjustment' => (int) Options::get('fixed_adjustment', 0),
            'base_costs'       => $base_costs,
            'unsellable'       => $unsellable,
            'demo'             => Plugin::demo_mode(),
            'products'         => Catalog::PRODUCTS,
        ]);
    }

    public static function policies(): void
    {
        self::render('policies', [
            'warning'  => (int) Options::get('policy_warning', 500000),
            'critical' => (int) Options::get('policy_critical', 100000),
            'grace'    => (int) Options::get('policy_grace_days', 3),
            'actions'  => (array) Options::get('policy_actions', []),
            'cooldown' => (int) Options::get('notify_cooldown', 24),
        ]);
    }

    public static function branding(): void
    {
        $color = Brand::normalise((string) Options::get('brand_color', Brand::FALLBACK));
        self::render('branding', [
            'brand_name'        => (string) Options::get('brand_name', ''),
            'brand_logo_id'     => (int) Options::get('brand_logo_id', 0),
            'brand_description' => (string) Options::get('brand_description', ''),
            'brand_about'       => (string) Options::get('brand_about', ''),
            'support_email'     => (string) Options::get('support_email', ''),
            'support_phone'     => (string) Options::get('support_phone', ''),
            'brand_color'       => $color,
            'brand_contrast'    => Brand::contrast_with_white($color),
            'demo_mode'         => (bool) Options::get('demo_mode', true),
            'has_verified'      => Credentials::has_verified_credential(),
            'retention'         => (bool) Options::get('data_retention_on_uninstall', true),
            'license'           => License::status(),
        ]);
    }

    public static function health(): void
    {
        // The one remaining $wpdb reference in the admin layer, and it reads
        // no table: the MySQL server version belongs to no module.
        global $wpdb;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $job = (int) ($_GET['job'] ?? 0);
        if ($job) {
            $row = JobRunner::detail($job);
            self::render('job-detail', [
                'job'   => $row,
                'order' => $row && !empty($row['payload']) ? self::payload_order($row['payload']) : 0,
            ]);
            return;
        }

        $last_sync = UsageSync::last_run();
        self::render('health', [
            'plugin_version'   => ARVRS_VERSION,
            'schema_version'   => (int) get_option('arvrs_schema_version', 0),
            'schema_target'    => ARVRS_SCHEMA_VERSION,
            'schema_check'     => Schema::verify(),
            'wp_version'       => get_bloginfo('version'),
            'php_version'      => PHP_VERSION,
            'mysql_version'    => $wpdb->db_version(),
            'sodium'           => \ArvanReseller\Support\Crypto::available(),
            'cron_disabled'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'next_jobs_run'    => wp_next_scheduled('arvrs_run_jobs'),
            'next_usage_run'   => wp_next_scheduled('arvrs_usage_sync'),
            'next_daily_run'   => wp_next_scheduled('arvrs_daily'),
            'last_usage_sync'  => (string) $last_sync['at'],
            'last_usage_stats' => (array) $last_sync['stats'],
            'jobs'             => JobRunner::stats(),
            'failed_jobs'      => JobRunner::failed(25),
            'pages'            => PageFactory::status(),
            'credentials'      => Credentials::all(),
            'demo'             => Plugin::demo_mode(),
            'payment_provider' => Plugin::payments()->label(),
            'errors'           => Audit::recent(10, 'error'),
            'retention_days'   => (int) Options::get('data_retention_days', 90),
            'last_prune'       => (array) get_option('arvrs_last_prune', []),
        ]);
    }

    /** Best-effort order id out of a job payload, for the dead-job link. */
    private static function payload_order(string $payload): int
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? (int) ($decoded['order_id'] ?? 0) : 0;
    }

    public static function audit(): void
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters
        $args = [
            'level'       => sanitize_key($_GET['level'] ?? ''),
            'action'      => sanitize_text_field(wp_unslash($_GET['audit_action'] ?? '')),
            'object_type' => sanitize_key($_GET['object_type'] ?? ''),
            'object_id'   => sanitize_text_field(wp_unslash($_GET['object_id'] ?? '')),
            'user_id'     => (int) ($_GET['user_id'] ?? 0),
            'from'        => sanitize_text_field(wp_unslash($_GET['from'] ?? '')),
            'to'          => sanitize_text_field(wp_unslash($_GET['to'] ?? '')),
            'page'        => max(1, (int) ($_GET['paged'] ?? 1)),
            'per_page'    => 50,
        ];
        // phpcs:enable
        $result = Audit::query($args);
        self::render('audit', [
            'result'  => $result,
            'filters' => $args,
            'actions' => Audit::actions(200),
        ]);
    }
}
