<?php
namespace ArvanReseller\Front;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Install\PageFactory;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Payments\SandboxProvider;
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
    /** Order statuses that still have money owed on them. */
    public const UNPAID = ['pending_payment', 'payment_processing'];

    public static function register_hooks(): void
    {
        add_shortcode('arvrs_storefront', [self::class, 'storefront']);
        add_shortcode('arvrs_product', [self::class, 'product']);
        add_shortcode('arvrs_checkout', [self::class, 'checkout']);
        add_shortcode('arvrs_dashboard', [self::class, 'dashboard']);
        add_shortcode('arvrs_auth', [self::class, 'auth']);
        add_shortcode('arvrs_payment', [self::class, 'payment']);
    }

    /**
     * Human text for a flash CODE carried in `arvrs_notice` / `arvrs_error`.
     *
     * The query string used to carry the sentence itself, which let anyone hand
     * a customer a link that renders arbitrary text inside a first-party banner
     * (EX-115). Only these keys resolve; anything else renders nothing at all.
     */
    public static function flash(string $code): string
    {
        $map = [
            'login_failed'      => __('ایمیل یا گذرواژه نادرست است.', 'arvan-reseller'),
            'login_throttled'   => __('تلاش‌های ورود زیاد است. ده دقیقه بعد دوباره امتحان کنید.', 'arvan-reseller'),
            'register_throttled' => __('تعداد ثبت‌نام‌ها از این نشانی زیاد است. بعداً تلاش کنید.', 'arvan-reseller'),
            'register_closed'   => __('ثبت‌نام مشتری جدید در این فروشگاه غیرفعال است. برای دریافت حساب با پشتیبانی تماس بگیرید.', 'arvan-reseller'),
            'register_invalid_email' => __('نشانی ایمیل معتبر نیست.', 'arvan-reseller'),
            'register_weak_password' => __('گذرواژه باید دست‌کم ۸ نویسه باشد.', 'arvan-reseller'),
            'register_failed'   => __('ساخت حساب انجام نشد. اطلاعات را بررسی کنید و دوباره تلاش کنید.', 'arvan-reseller'),
            // Deliberately identical for "e-mail already taken" and for a
            // successful sign-up whose auto-login did not take: the page must
            // not confirm whether an account exists (EX-114).
            'register_check_inbox' => __('اگر این نشانی تازه باشد، حساب شما ساخته شد. برای ادامه وارد شوید.', 'arvan-reseller'),
            'renewal_cancelled' => __('تمدید خودکار این سرویس لغو شد.', 'arvan-reseller'),
            'renewal_failed'    => __('لغو تمدید انجام نشد. اگر ادامه داشت با پشتیبانی تماس بگیرید.', 'arvan-reseller'),
        ];
        return isset($map[$code]) ? $map[$code] : '';
    }

    /** Flash code from the query string, resolved through the fixed map. */
    private static function flash_from_query(string $key): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash, resolved through a fixed map
        $code = isset($_GET[$key]) ? sanitize_key(wp_unslash($_GET[$key])) : '';
        return $code === '' ? '' : self::flash($code);
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
            // A logged-in WordPress user who is NOT a customer (the reseller
            // previewing their own storefront) needs a way out — see EX-065.
            'foreign_login' => $uid === 0 && is_user_logged_in(),
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
                'admin'        => admin_url(),
                'lost_password' => wp_lostpassword_url(PageFactory::url('auth')),
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
            return in_array($order['status'], self::UNPAID, true);
        });
        foreach ($orders as &$order) {
            $order['pay_url'] = Plugin::payments()->start((string) $order['payment_ref'], (int) $order['amount'], 'order');
        }
        unset($order);
        return Helpers::view('front/checkout', self::ctx([
            'current' => 'checkout',
            'orders'  => array_values($orders),
        ]));
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
        unset($service);

        $orders = OrderService::list($uid, '', 1, 10);
        // The pending-payment page exists and was reachable from nothing
        // (EX-018); the dashboard is where an abandoned checkout is found again.
        $unpaid = array_values(array_filter($orders, static function ($order) {
            return in_array($order['status'], self::UNPAID, true);
        }));

        return Helpers::view('front/dashboard', self::ctx([
            'user'          => wp_get_current_user(),
            'stage'         => (string) get_user_meta($uid, 'arvrs_policy_stage', true) ?: 'healthy',
            'services'      => $services,
            'orders'        => $orders,
            'unpaid'        => $unpaid,
            'ledger'        => Ledger::entries($uid, 1, 15),
            'usage'         => UsageSync::customer_usage($uid, 20),
            'notifications' => Notifier::for_user($uid, 10),
            'notice'        => self::flash_from_query('arvrs_notice'),
            'error'         => self::flash_from_query('arvrs_error'),
            'tab'           => isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'overview', // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch
        ]));
    }

    public static function auth(): string
    {
        if (Customers::is_customer() || is_user_logged_in()) {
            // Both the customer ("you are already signed in") and the
            // administrator previewing their own store ("this account is not a
            // customer account") get an explanatory panel instead of a login
            // form that redirects straight back here (EX-065).
            return Helpers::view('front/require-login', self::ctx());
        }
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redisplay of a failed submit
        $tab    = (isset($_GET['tab']) && sanitize_key(wp_unslash($_GET['tab'])) === 'register') ? 'register' : 'login';
        $error  = self::flash_from_query('arvrs_error');
        $notice = self::flash_from_query('arvrs_notice');
        // Re-render a failed registration with what the customer typed rather
        // than emptying the form (EX-066) — but only in the state our own
        // redirect produces, so a bare link cannot plant text on the page.
        $prefill = ['display_name' => '', 'email' => ''];
        if ($error !== '' || $notice !== '') {
            $prefill['display_name'] = isset($_GET['arvrs_name']) ? substr(sanitize_text_field(wp_unslash($_GET['arvrs_name'])), 0, 60) : '';
            $prefill['email']        = isset($_GET['arvrs_email']) ? sanitize_email(wp_unslash($_GET['arvrs_email'])) : '';
        }
        // phpcs:enable
        return Helpers::view('front/auth', self::ctx([
            'error'   => $error,
            'notice'  => $notice,
            'tab'     => $tab,
            'prefill' => $prefill,
            'registration_open' => Customers::registration_open(),
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

        $amount    = 0;
        $title     = '';
        $owner     = 0;
        $payable   = false;
        $order_id  = 0;
        $provision = null;
        if ($type === 'order') {
            $order = OrderService::by_ref($ref);
            if ($order) {
                $amount    = (int) $order['amount'];
                $title     = sprintf(__('سفارش #%1$d — %2$s', 'arvan-reseller'), (int) $order['id'], Catalog::product_label((string) $order['product']));
                $owner     = (int) $order['customer_id'];
                $payable   = in_array($order['status'], self::UNPAID, true);
                $order_id  = (int) $order['id'];
                // The one truthful source for what happened to this order. A
                // customer who comes back to the payment link tomorrow sees the
                // real outcome, not a stale "ready" (EX-002).
                $provision = PaymentService::provision_state($order);
            }
        } else {
            $intent = PaymentService::topup_intent($ref);
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
        if (PaymentService::sandbox_blocked()) {
            return Helpers::view('front/payment', self::ctx(['error' => __('درگاه پرداخت واقعی هنوز پیکربندی نشده است. با پشتیبانی تماس بگیرید.', 'arvan-reseller')]));
        }
        return Helpers::view('front/payment', self::ctx([
            'ref'       => $ref,
            'type'      => $type,
            'amount'    => $amount,
            'title'     => $title,
            'payable'   => $payable,
            'order_id'  => $order_id,
            'provision' => $provision,
            'proof'     => SandboxProvider::proof($ref, $amount, $type),
            'gateway'   => Plugin::payments()->label(),
        ]));
    }
}
