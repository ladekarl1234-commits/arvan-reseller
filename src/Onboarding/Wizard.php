<?php
namespace ArvanReseller\Onboarding;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Licensing\License;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Onboarding wizard (spec: installation & activation experience).
 * Welcome → Access Token → Identity → Arvan API → Pricing & Products →
 * Page Creation → Validation → Ready. Every step validates server-side,
 * supports Back, and is idempotent — re-running never duplicates pages.
 */
final class Wizard
{
    public const STEPS = ['welcome', 'license', 'identity', 'arvan', 'pricing', 'pages', 'ready'];

    public static function register_hooks(): void
    {
        add_action('admin_init', [self::class, 'maybe_redirect']);
        add_action('admin_post_arvrs_wizard', [self::class, 'handle']);
        add_action('admin_notices', [self::class, 'setup_notice']);
    }

    /** One-time redirect right after first activation. */
    public static function maybe_redirect(): void
    {
        if (!Options::get('activation_redirect') || wp_doing_ajax() || !current_user_can('manage_options')) {
            return;
        }
        Options::set('activation_redirect', false);
        wp_safe_redirect(admin_url('admin.php?page=arvan-reseller-setup'));
        exit;
    }

    /** Persistent nudge while setup is incomplete. */
    public static function setup_notice(): void
    {
        if (Options::get('onboarded') || !current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, 'arvan-reseller-setup') !== false) {
            return;
        }
        printf(
            '<div class="notice notice-warning"><p><strong>%s</strong> <a href="%s">%s</a></p></div>',
            esc_html__('راه‌اندازی فروشگاه ابری هنوز کامل نشده است.', 'arvan-reseller'),
            esc_url(admin_url('admin.php?page=arvan-reseller-setup')),
            esc_html__('ادامه راه‌اندازی', 'arvan-reseller')
        );
    }

    private static function step(): int
    {
        return min(max(0, (int) Options::get('wizard_step', 0)), count(self::STEPS) - 1);
    }

    public static function render(): void
    {
        $step = self::step();
        $key  = self::STEPS[$step];

        $vars = [
            'step'       => $step,
            'step_key'   => $key,
            'steps'      => self::STEPS,
            'licensed'   => License::is_active(),
            'error'      => isset($_GET['arvrs_error']) ? sanitize_text_field(wp_unslash($_GET['arvrs_error'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'notice'     => isset($_GET['arvrs_notice']) ? sanitize_text_field(wp_unslash($_GET['arvrs_notice'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ];
        if ($key === 'identity') {
            foreach (['brand_name', 'brand_description', 'support_email', 'support_phone', 'brand_color'] as $field) {
                $vars[$field] = Options::get($field, '');
            }
            $vars['brand_logo_id'] = (int) Options::get('brand_logo_id', 0);
        }
        if ($key === 'arvan') {
            $vars['credentials'] = Credentials::all();
            $vars['crypto_ok']   = \ArvanReseller\Support\Crypto::available();
        }
        if ($key === 'pricing') {
            $vars['global_markup'] = (float) Options::get('global_markup', 20.0);
            $vars['enabled_products'] = Catalog::enabled_products();
            $vars['base_costs'] = BaseCosts::all();
        }
        if ($key === 'pages' || $key === 'ready') {
            $vars['pages'] = PageFactory::status();
        }
        if ($key === 'ready') {
            $vars['checks'] = self::validation_checks();
        }
        echo Helpers::view('admin/wizard', $vars); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes at sink
    }

    /** @return array<string,array{ok:bool,label:string,detail:string}> */
    public static function validation_checks(): array
    {
        $pages_ok = true;
        foreach (PageFactory::status() as $page) {
            $pages_ok = $pages_ok && $page['status'] === 'publish';
        }
        $has_credential = Credentials::has_verified_credential();
        $demo           = (bool) Options::get('demo_mode', true);
        return [
            'license' => [
                'ok'     => License::is_active(),
                'label'  => __('فعال‌سازی افزونه', 'arvan-reseller'),
                'detail' => License::is_active() ? __('توکن دسترسی معتبر ثبت شده است.', 'arvan-reseller') : __('توکن دسترسی هنوز تأیید نشده است.', 'arvan-reseller'),
            ],
            'arvan' => [
                'ok'     => $has_credential || $demo,
                'label'  => __('اتصال ArvanCloud', 'arvan-reseller'),
                'detail' => $has_credential
                    ? __('دست‌کم یک اتصال آزمایش‌شده دارید.', 'arvan-reseller')
                    : ($demo ? __('حالت دمو فعال است؛ بدون توکن واقعی هم می‌توانید ادامه دهید.', 'arvan-reseller') : __('هیچ اتصال آزمایش‌شده‌ای ندارید.', 'arvan-reseller')),
            ],
            'pricing' => [
                'ok'     => count(BaseCosts::all()) > 0,
                'label'  => __('قیمت‌گذاری', 'arvan-reseller'),
                'detail' => __('هزینه پایه پلن‌ها و درصد سود تنظیم شده است.', 'arvan-reseller'),
            ],
            'pages' => [
                'ok'     => $pages_ok,
                'label'  => __('صفحات فروشگاه', 'arvan-reseller'),
                'detail' => $pages_ok ? __('همه صفحات ساخته و منتشر شده‌اند.', 'arvan-reseller') : __('برخی صفحات ساخته نشده‌اند.', 'arvan-reseller'),
            ],
        ];
    }

    public static function handle(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('دسترسی غیرمجاز.', 'arvan-reseller'), 403);
        }
        check_admin_referer('arvrs_wizard', 'arvrs_nonce');

        $direction = sanitize_key($_POST['direction'] ?? 'next');
        $step      = self::step();
        $key       = self::STEPS[$step];

        if ($direction === 'back') {
            Options::set('wizard_step', max(0, $step - 1));
            self::redirect();
        }

        switch ($key) {
            case 'welcome':
                break;

            case 'license':
                if (!License::is_active()) {
                    $token = (string) wp_unslash($_POST['access_token'] ?? ''); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- secret, verified not stored
                    if (!Helpers::rate_limit('license:' . get_current_user_id(), 10, 600)) {
                        self::redirect(__('تلاش‌های زیاد؛ چند دقیقه بعد دوباره امتحان کنید.', 'arvan-reseller'));
                    }
                    if (!License::activate($token)) {
                        Audit::log(0, 'license.failed', 'license', '', [], 'audit');
                        self::redirect(__('توکن دسترسی معتبر نیست. توکنی که از تیم فروش دریافت کرده‌اید را بدون فاصله وارد کنید.', 'arvan-reseller'));
                    }
                    Audit::log(0, 'license.activated', 'license');
                }
                break;

            case 'identity':
                $brand_name = sanitize_text_field(wp_unslash($_POST['brand_name'] ?? ''));
                if ($brand_name === '') {
                    self::redirect(__('نام فروشگاه را وارد کنید.', 'arvan-reseller'));
                }
                Options::set_many([
                    'brand_name'        => $brand_name,
                    'brand_description' => sanitize_text_field(wp_unslash($_POST['brand_description'] ?? '')),
                    'support_email'     => sanitize_email(wp_unslash($_POST['support_email'] ?? '')),
                    'support_phone'     => preg_replace('/[^0-9+\-\s]/', '', (string) wp_unslash($_POST['support_phone'] ?? '')),
                    'brand_color'       => sanitize_hex_color(wp_unslash($_POST['brand_color'] ?? '')) ?: '#0c6960',
                ]);
                break;

            case 'arvan':
                $token = trim((string) wp_unslash($_POST['api_token'] ?? '')); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
                if ($token !== '') {
                    $id = Credentials::save([
                        'name'       => sanitize_text_field(wp_unslash($_POST['credential_name'] ?? '')) ?: __('اتصال اصلی', 'arvan-reseller'),
                        'enabled'    => true,
                        'is_default' => true,
                        'products'   => [],
                        'priority'   => 10,
                    ], $token);
                    $provider = new \ArvanReseller\Arvan\RealProvider(['id' => $id, 'token' => $token]);
                    $result   = $provider->test_connection();
                    Credentials::record_test($id, $result['ok'], $result['ok'] ? '' : $result['message']);
                    if ($result['ok']) {
                        Options::set('demo_mode', false);
                    } else {
                        self::redirect(sprintf(__('اتصال ذخیره شد اما آزمایش ناموفق بود: %s — می‌توانید فعلاً با حالت دمو ادامه دهید.', 'arvan-reseller'), $result['message']));
                    }
                } else {
                    Options::set('demo_mode', true); // explicit demo path
                }
                break;

            case 'pricing':
                Options::set('global_markup', max(-100, (float) wp_unslash($_POST['global_markup'] ?? 20)));
                $products = array_values(array_intersect(Catalog::PRODUCTS, (array) ($_POST['enabled_products'] ?? [])));
                Options::set('enabled_products', $products ?: Catalog::PRODUCTS);
                BaseCosts::seed_defaults();
                Catalog::flush();
                break;

            case 'pages':
                PageFactory::ensure_pages();
                break;

            case 'ready':
                Options::set('onboarded', true);
                Audit::log(0, 'onboarding.completed', 'settings');
                wp_safe_redirect(admin_url('admin.php?page=arvan-reseller&arvrs_notice=' . rawurlencode(__('راه‌اندازی کامل شد. فروشگاه شما آماده است!', 'arvan-reseller'))));
                exit;
        }

        Options::set('wizard_step', min(count(self::STEPS) - 1, $step + 1));
        self::redirect();
    }

    private static function redirect(string $error = ''): void
    {
        $url = admin_url('admin.php?page=arvan-reseller-setup');
        if ($error) {
            $url = add_query_arg('arvrs_error', rawurlencode($error), $url);
        }
        wp_safe_redirect($url);
        exit;
    }
}
