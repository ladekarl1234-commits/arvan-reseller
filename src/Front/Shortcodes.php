<?php
namespace ArvanReseller\Front;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\Pricing;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Customer-facing pages as shortcodes (HC-1: theme-independent, plugin-
 * controlled layout). All templates are server-rendered; JS handles only
 * checkout submission, the sandbox gateway and small actions.
 */
final class Shortcodes
{
    public static function register_hooks(): void
    {
        add_shortcode('arvrs_storefront', [self::class, 'storefront']);
        add_shortcode('arvrs_product', [self::class, 'product']);
        add_shortcode('arvrs_checkout', [self::class, 'checkout']);
        add_shortcode('arvrs_dashboard', [self::class, 'dashboard']);
        add_shortcode('arvrs_auth', [self::class, 'auth']);
        add_shortcode('arvrs_payment', [self::class, 'payment']);
    }

    /** Shared branding/header context for every template. */
    private static function ctx(array $extra = []): array
    {
        $uid = Customers::is_customer() ? get_current_user_id() : 0;
        return array_merge([
            'brand_name' => (string) Options::get('brand_name', get_bloginfo('name')),
            'brand_logo' => ($id = (int) Options::get('brand_logo_id', 0)) ? wp_get_attachment_image_url($id, 'medium') : '',
            'brand_desc' => (string) Options::get('brand_description', ''),
            'brand_about' => (string) Options::get('brand_about', ''),
            'support_email' => (string) Options::get('support_email', ''),
            'support_phone' => (string) Options::get('support_phone', ''),
            'customer_id' => $uid,
            'balance'    => $uid ? Ledger::balance($uid) : null,
            'unread'     => $uid ? Notifier::unread_count($uid) : 0,
            'urls'       => [
                'storefront' => PageFactory::url('storefront'),
                'dashboard'  => PageFactory::url('dashboard'),
                'auth'       => PageFactory::url('auth'),
                'checkout'   => PageFactory::url('checkout'),
                'cloud_server' => PageFactory::url('cloud_server'),
                'cdn'          => PageFactory::url('cdn'),
                'object_storage' => PageFactory::url('object_storage'),
            ],
        ], $extra);
    }

    public static function storefront(): string
    {
        $products = [];
        foreach (Catalog::enabled_products() as $product) {
            $plans = Catalog::plans($product);
            $min   = null;
            foreach ($plans as $plan) {
                if ((int) $plan['base_cost'] <= 0) {
                    continue;
                }
                $quote = Pricing::quote($product, $plan['id'], (int) $plan['base_cost'], get_current_user_id());
                $min   = $min === null ? $quote['customer_price'] : min($min, $quote['customer_price']);
            }
            $products[$product] = [
                'label' => Catalog::product_label($product),
                'count' => count($plans),
                'from'  => $min,
            ];
        }
        return Helpers::view('front/storefront', self::ctx(['products' => $products]));
    }

    public static function product($atts): string
    {
        $atts    = shortcode_atts(['product' => ''], $atts);
        $product = sanitize_key($atts['product']);
        if (!in_array($product, Catalog::PRODUCTS, true)) {
            return '';
        }
        $uid   = Customers::is_customer() ? get_current_user_id() : 0;
        $plans = [];
        foreach (Catalog::plans($product) as $plan) {
            if ((int) $plan['base_cost'] <= 0) {
                continue; // unpriced plans are not sellable — never advertise them
            }
            $quote = Pricing::quote($product, $plan['id'], (int) $plan['base_cost'], $uid);
            $plan['price']       = $quote['customer_price'];
            $plan['price_label'] = Helpers::money($quote['customer_price']);
            unset($plan['base_cost'], $plan['meta']);
            $plans[] = $plan;
        }
        return Helpers::view('front/product', self::ctx([
            'product'       => $product,
            'product_label' => Catalog::product_label($product),
            'plans'         => $plans,
            'options'       => Catalog::options($product),
            'logged_in'     => (bool) $uid,
        ]));
    }

    public static function checkout(): string
    {
        if (!Customers::is_customer()) {
            return Helpers::view('front/require-login', self::ctx());
        }
        $uid    = get_current_user_id();
        $orders = array_filter(OrderService::list($uid, '', 1, 10), static function ($order) {
            return in_array($order['status'], ['pending_payment', 'payment_processing'], true);
        });
        foreach ($orders as &$order) {
            $order['pay_url'] = Plugin::payments()->start((string) $order['payment_ref'], (int) $order['amount'], 'order');
        }
        return Helpers::view('front/checkout', self::ctx(['orders' => array_values($orders)]));
    }

    public static function dashboard(): string
    {
        if (!Customers::is_customer()) {
            return Helpers::view('front/require-login', self::ctx());
        }
        $uid      = get_current_user_id();
        $services = Services::list($uid, 1, 50);
        foreach ($services as &$service) {
            $service['connection'] = json_decode((string) $service['connection'], true) ?: [];
            $service['label']      = Catalog::product_label((string) $service['product']);
        }
        return Helpers::view('front/dashboard', self::ctx([
            'user'          => wp_get_current_user(),
            'stage'         => (string) get_user_meta($uid, 'arvrs_policy_stage', true) ?: 'healthy',
            'services'      => $services,
            'orders'        => OrderService::list($uid, '', 1, 10),
            'ledger'        => Ledger::entries($uid, 1, 15),
            'usage'         => UsageSync::customer_usage($uid, 20),
            'notifications' => Notifier::for_user($uid, 10),
            'tab'           => isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch
        ]));
    }

    public static function auth(): string
    {
        if (Customers::is_customer()) {
            return Helpers::view('front/require-login', self::ctx()); // template shows "go to dashboard" for logged-in users
        }
        return Helpers::view('front/auth', self::ctx([
            'error'   => isset($_GET['arvrs_error']) ? sanitize_text_field(wp_unslash($_GET['arvrs_error'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            'notice'  => isset($_GET['arvrs_notice']) ? sanitize_text_field(wp_unslash($_GET['arvrs_notice'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        ]));
    }

    public static function payment(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- gateway landing, ref is an unguessable token
        $ref  = isset($_GET['arvrs_ref']) ? sanitize_text_field(wp_unslash($_GET['arvrs_ref'])) : '';
        $type = (isset($_GET['arvrs_type']) && $_GET['arvrs_type'] === 'topup') ? 'topup' : 'order';
        // phpcs:enable
        if ($ref === '') {
            return Helpers::view('front/payment', self::ctx(['error' => __('شناسه پرداخت یافت نشد.', 'arvan-reseller')]));
        }

        $amount = 0;
        $title  = '';
        $owner  = 0;
        if ($type === 'order') {
            $order = OrderService::by_ref($ref);
            if ($order) {
                $amount = (int) $order['amount'];
                $title  = sprintf(__('سفارش #%1$d — %2$s', 'arvan-reseller'), (int) $order['id'], Catalog::product_label((string) $order['product']));
                $owner  = (int) $order['customer_id'];
                $payable = in_array($order['status'], ['pending_payment', 'payment_processing'], true);
            }
        } else {
            $intent = get_option('arvrs_topup_' . $ref);
            if (is_array($intent)) {
                $amount  = (int) $intent['amount'];
                $title   = __('افزایش اعتبار کیف پول', 'arvan-reseller');
                $owner   = (int) $intent['customer_id'];
                $payable = true;
            }
        }
        if (!$amount || $owner !== get_current_user_id()) {
            return Helpers::view('front/payment', self::ctx(['error' => __('تراکنش معتبر یافت نشد.', 'arvan-reseller')]));
        }
        // Never mint a sandbox proof when the sandbox must not settle live.
        if (\ArvanReseller\Payments\PaymentService::sandbox_blocked()) {
            return Helpers::view('front/payment', self::ctx(['error' => __('درگاه پرداخت واقعی هنوز پیکربندی نشده است. با پشتیبانی تماس بگیرید.', 'arvan-reseller')]));
        }
        return Helpers::view('front/payment', self::ctx([
            'ref'     => $ref,
            'type'    => $type,
            'amount'  => $amount,
            'title'   => $title,
            'payable' => !empty($payable),
            'proof'   => \ArvanReseller\Payments\SandboxProvider::proof($ref, $amount, $type),
            'gateway' => Plugin::payments()->label(),
        ]));
    }
}
