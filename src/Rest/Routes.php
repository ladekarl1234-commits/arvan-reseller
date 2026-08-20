<?php
namespace ArvanReseller\Rest;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Identity\Customers;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Payments\PaymentService;
use ArvanReseller\Plugin;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * REST API (spec §9): customer-facing routes + the payment callback. Every
 * route has a permission_callback and an args schema; every /me/* handler is
 * owner-scoped by construction — the customer ID always comes from the
 * session, never from the request (SEC-2, HC-5).
 * Admin operations intentionally use admin-post + nonces, not REST (ADR-0005).
 */
final class Routes
{
    private const NS = 'arvan-reseller/v1';

    public static function register_hooks(): void
    {
        add_action('rest_api_init', [self::class, 'register']);
    }

    public static function register(): void
    {
        $customer_auth = static function (): bool {
            return is_user_logged_in() && Customers::is_customer();
        };
        $public = '__return_true';

        register_rest_route(self::NS, '/catalog/(?P<product>[a-z_]+)', [
            'methods'  => 'GET',
            'permission_callback' => $public,
            'args' => ['product' => ['type' => 'string', 'enum' => Catalog::PRODUCTS]],
            'callback' => static function (\WP_REST_Request $r) {
                return rest_ensure_response([
                    'plans'   => array_map([self::class, 'priced_plan'], Catalog::plans($r['product'])),
                    'options' => Catalog::options($r['product']),
                ]);
            },
        ]);

        register_rest_route(self::NS, '/checkout', [
            'methods'  => 'POST',
            'permission_callback' => $customer_auth,
            'args' => [
                'product' => ['type' => 'string', 'enum' => Catalog::PRODUCTS, 'required' => true],
                'plan_id' => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'config'  => ['type' => 'object', 'default' => []],
            ],
            'callback' => [self::class, 'checkout'],
        ]);

        register_rest_route(self::NS, '/payment/callback', [
            'methods'  => 'POST',
            'permission_callback' => $public, // authenticity = provider verify()
            'args' => [
                'ref'  => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'type' => ['type' => 'string', 'enum' => ['order', 'topup'], 'default' => 'order'],
                'sandbox_proof' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field'],
            ],
            'callback' => [self::class, 'payment_callback'],
        ]);

        register_rest_route(self::NS, '/me/summary', [
            'methods' => 'GET', 'permission_callback' => $customer_auth,
            'callback' => [self::class, 'me_summary'],
        ]);

        foreach (['orders', 'services', 'ledger', 'usage', 'notifications'] as $list) {
            register_rest_route(self::NS, '/me/' . $list, [
                'methods' => 'GET', 'permission_callback' => $customer_auth,
                'args' => ['page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1]],
                'callback' => static function (\WP_REST_Request $r) use ($list) {
                    return self::me_list($list, (int) $r['page']);
                },
            ]);
        }

        register_rest_route(self::NS, '/me/topup', [
            'methods' => 'POST', 'permission_callback' => $customer_auth,
            'args' => ['amount' => ['type' => 'integer', 'required' => true, 'minimum' => 100000, 'maximum' => 500000000]],
            'callback' => static function (\WP_REST_Request $r) {
                if (!Plugin::demo_mode() && Plugin::payments()->id() === 'sandbox') {
                    return new \WP_Error('no_gateway', __('درگاه پرداخت واقعی هنوز پیکربندی نشده است.', 'arvan-reseller'), ['status' => 503]);
                }
                $url = PaymentService::start_topup(get_current_user_id(), (int) $r['amount']);
                return rest_ensure_response(['redirect' => $url]);
            },
        ]);

        register_rest_route(self::NS, '/me/notifications/(?P<id>\d+)/read', [
            'methods' => 'POST', 'permission_callback' => $customer_auth,
            'callback' => static function (\WP_REST_Request $r) {
                Notifier::mark_read(get_current_user_id(), (int) $r['id']); // owner-scoped in SQL
                return rest_ensure_response(['ok' => true]);
            },
        ]);
    }

    /** Attach the customer-specific price to a catalog plan. */
    private static function priced_plan(array $plan): array
    {
        $quote = \ArvanReseller\Pricing\Pricing::quote(
            $plan['product'], $plan['id'], (int) $plan['base_cost'],
            Customers::is_customer() ? get_current_user_id() : 0
        );
        unset($plan['base_cost'], $plan['meta']); // reseller cost stays private
        $plan['price']       = $quote['customer_price'];
        $plan['price_label'] = Helpers::money($quote['customer_price']) . ' / ' . __('ماهانه', 'arvan-reseller');
        return $plan;
    }

    public static function checkout(\WP_REST_Request $r)
    {
        $customer_id = get_current_user_id();
        if (!Helpers::rate_limit('checkout:' . $customer_id, 20, 300)) {
            return new \WP_Error('rate_limited', __('درخواست‌های شما زیاد است. کمی صبر کنید.', 'arvan-reseller'), ['status' => 429]);
        }
        if (!Plugin::licensed()) {
            return new \WP_Error('unlicensed', __('فروشگاه هنوز فعال‌سازی نشده است.', 'arvan-reseller'), ['status' => 503]);
        }
        // The sandbox gateway hands a self-verifiable proof to the buyer, so it
        // must NEVER be the live payment path in real operation — refuse to
        // sell until a real gateway adapter is registered (ADR-0006).
        if (!Plugin::demo_mode() && Plugin::payments()->id() === 'sandbox') {
            return new \WP_Error('no_gateway', __('درگاه پرداخت واقعی هنوز پیکربندی نشده است. با پشتیبانی تماس بگیرید.', 'arvan-reseller'), ['status' => 503]);
        }
        if (UsageSync::purchases_blocked($customer_id)) {
            return new \WP_Error('blocked', __('به دلیل وضعیت اعتبار، خرید جدید موقتاً غیرفعال است. کیف پول را شارژ کنید.', 'arvan-reseller'), ['status' => 403]);
        }
        $order = OrderService::create($customer_id, (string) $r['product'], (string) $r['plan_id'], (array) $r['config']);
        if (is_wp_error($order)) {
            $order->add_data(['status' => 400]);
            return $order;
        }
        return rest_ensure_response([
            'order_id' => (int) $order['id'],
            'amount'   => (int) $order['amount'],
            'amount_label' => Helpers::money((int) $order['amount']),
            'redirect' => Plugin::payments()->start((string) $order['payment_ref'], (int) $order['amount'], 'order'),
        ]);
    }

    public static function payment_callback(\WP_REST_Request $r)
    {
        if (!Helpers::rate_limit('callback:' . Helpers::client_ip(), 30, 300)) {
            return new \WP_Error('rate_limited', 'Too many callbacks', ['status' => 429]);
        }
        $ref     = (string) $r['ref'];
        $payload = ['sandbox_proof' => (string) $r['sandbox_proof'], 'type' => (string) $r['type']];

        if ($r['type'] === 'topup') {
            $result = PaymentService::handle_topup_callback($ref, $payload);
            return rest_ensure_response($result);
        }
        $result = PaymentService::handle_order_callback($ref, $payload);
        $order  = $result['order'];
        return rest_ensure_response([
            'ok'      => $result['ok'],
            'replay'  => $result['replay'],
            'message' => $result['message'],
            'status'  => $order ? $order['status'] : null,
        ]);
    }

    public static function me_summary()
    {
        $uid     = get_current_user_id();
        $balance = Ledger::balance($uid);
        $user    = wp_get_current_user();
        return rest_ensure_response([
            'name'     => $user->display_name,
            'balance'  => $balance,
            'balance_label' => Helpers::money($balance['available']),
            'stage'    => (string) get_user_meta($uid, 'arvrs_policy_stage', true) ?: 'healthy',
            'services' => count(Services::list($uid, 1, 100)),
            'unread'   => Notifier::unread_count($uid),
        ]);
    }

    private static function me_list(string $kind, int $page)
    {
        $uid = get_current_user_id();
        switch ($kind) {
            case 'orders':
                $rows = OrderService::list($uid, '', $page);
                foreach ($rows as &$row) {
                    unset($row['pricing']); // margin/base cost is reseller-private
                    $row['amount_label'] = Helpers::money((int) $row['amount']);
                }
                return rest_ensure_response($rows);
            case 'services':
                $rows = Services::list($uid, $page);
                foreach ($rows as &$row) {
                    $row['connection'] = json_decode((string) $row['connection'], true) ?: [];
                    unset($row['credential_id']); // upstream routing is private
                }
                return rest_ensure_response($rows);
            case 'ledger':
                return rest_ensure_response(Ledger::entries($uid, $page));
            case 'usage':
                return rest_ensure_response(UsageSync::customer_usage($uid));
            case 'notifications':
                return rest_ensure_response(Notifier::for_user($uid, 20));
        }
        return rest_ensure_response([]);
    }
}
