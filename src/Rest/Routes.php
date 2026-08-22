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
 * route declares a permission_callback and — including the path parameters
 * carried in the route regex — an `args` schema with types, ranges and
 * sanitisers, which is what SECURITY.md has always claimed. Every /me/*
 * handler is owner-scoped by construction: the customer ID always comes from
 * the session, never from the request (SEC-2, HC-5), and the two routes that
 * take a row id resolve it through an owner-scoped read.
 * Admin operations intentionally use admin-post + nonces, not REST (ADR-0005).
 */
final class Routes
{
    private const NS = 'arvan-reseller/v1';

    /** Reusable arg schema for a positive row id carried in the route regex. */
    private const ID_ARG = [
        'type'              => 'integer',
        'required'          => true,
        'minimum'           => 1,
        'sanitize_callback' => 'absint',
    ];

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
            'args' => [
                'product' => [
                    'type'     => 'string',
                    'required' => true,
                    'enum'     => Catalog::PRODUCTS,
                    'sanitize_callback' => 'sanitize_key',
                ],
            ],
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
                'product' => ['type' => 'string', 'enum' => Catalog::PRODUCTS, 'required' => true, 'sanitize_callback' => 'sanitize_key'],
                'plan_id' => ['type' => 'string', 'required' => true, 'maxLength' => 64, 'sanitize_callback' => 'sanitize_text_field'],
                'config'  => [
                    'type'    => 'object',
                    'default' => [],
                    // OrderService::sanitize_config is the real whitelist; this
                    // only stops a non-object body reaching it.
                    'validate_callback' => static function ($value): bool {
                        return is_array($value) || is_object($value);
                    },
                ],
            ],
            'callback' => [self::class, 'checkout'],
        ]);

        register_rest_route(self::NS, '/payment/callback', [
            'methods'  => 'POST',
            'permission_callback' => $public, // authenticity = provider verify()
            'args' => [
                'ref'  => ['type' => 'string', 'required' => true, 'maxLength' => 64, 'sanitize_callback' => 'sanitize_text_field'],
                'type' => ['type' => 'string', 'enum' => ['order', 'topup'], 'default' => 'order', 'sanitize_callback' => 'sanitize_key'],
                'sandbox_proof' => ['type' => 'string', 'default' => '', 'maxLength' => 128, 'sanitize_callback' => 'sanitize_text_field'],
            ],
            'callback' => [self::class, 'payment_callback'],
        ]);

        register_rest_route(self::NS, '/me/summary', [
            'methods' => 'GET', 'permission_callback' => $customer_auth,
            'args'    => [], // takes no parameters
            'callback' => [self::class, 'me_summary'],
        ]);

        foreach (['orders', 'services', 'ledger', 'usage', 'notifications'] as $list) {
            register_rest_route(self::NS, '/me/' . $list, [
                'methods' => 'GET', 'permission_callback' => $customer_auth,
                'args' => [
                    'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'maximum' => 1000, 'sanitize_callback' => 'absint'],
                ],
                'callback' => static function (\WP_REST_Request $r) use ($list) {
                    return self::me_list($list, (int) $r['page']);
                },
            ]);
        }

        // Single-service read. This is the production caller that makes
        // Services::get_owned() the object-level authorization choke point the
        // control inventory describes — the id comes from the request, the
        // customer id never does.
        register_rest_route(self::NS, '/me/services/(?P<id>\d+)', [
            'methods' => 'GET', 'permission_callback' => $customer_auth,
            'args' => ['id' => self::ID_ARG],
            'callback' => [self::class, 'me_service'],
        ]);

        register_rest_route(self::NS, '/me/topup', [
            'methods' => 'POST', 'permission_callback' => $customer_auth,
            'args' => [
                'amount' => [
                    'type' => 'integer', 'required' => true,
                    'minimum' => 100000, 'maximum' => 500000000,
                    'sanitize_callback' => 'absint',
                ],
            ],
            'callback' => [self::class, 'topup'],
        ]);

        register_rest_route(self::NS, '/me/notifications/(?P<id>\d+)/read', [
            'methods' => 'POST', 'permission_callback' => $customer_auth,
            'args' => ['id' => self::ID_ARG],
            'callback' => static function (\WP_REST_Request $r) {
                Notifier::mark_read(get_current_user_id(), (int) $r['id']); // owner-scoped in SQL
                return rest_ensure_response(['ok' => true]);
            },
        ]);

        // Provisioning state for the payment page's poll. Owner-scoped: an
        // order id that is not this customer's answers 404, never 403, so the
        // route cannot be used to probe which order ids exist.
        register_rest_route(self::NS, '/orders/(?P<id>\d+)/state', [
            'methods' => 'GET', 'permission_callback' => $customer_auth,
            'args' => ['id' => self::ID_ARG],
            'callback' => [self::class, 'order_state'],
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
        if (PaymentService::sandbox_blocked()) {
            PaymentService::alert_gateway_blocked();
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

    public static function topup(\WP_REST_Request $r)
    {
        $customer_id = get_current_user_id();
        // Every other money endpoint was throttled and this one was not, while
        // it is the one that writes a durable row per call.
        if (!Helpers::rate_limit('topup:' . $customer_id, 10, 300)) {
            return new \WP_Error('rate_limited', __('درخواست‌های شما زیاد است. کمی صبر کنید.', 'arvan-reseller'), ['status' => 429]);
        }
        if (PaymentService::sandbox_blocked()) {
            PaymentService::alert_gateway_blocked();
            return new \WP_Error('no_gateway', __('درگاه پرداخت واقعی هنوز پیکربندی نشده است.', 'arvan-reseller'), ['status' => 503]);
        }
        $url = PaymentService::start_topup($customer_id, (int) $r['amount']);
        if ($url === '') {
            return new \WP_Error('topup_failed', __('شروع افزایش اعتبار ناموفق بود. دوباره تلاش کنید.', 'arvan-reseller'), ['status' => 500]);
        }
        return rest_ensure_response(['redirect' => $url]);
    }

    public static function payment_callback(\WP_REST_Request $r)
    {
        $ref = (string) $r['ref'];
        // Throttle the REFERENCE, not the caller. A real gateway confirms every
        // customer's payment from the same handful of server IPs, so an IP
        // budget of 30/5min throttles the gateway itself out and payments stop
        // confirming site-wide. A handful of attempts per reference is the
        // actual abuse signal; the IP cap stays only as a crude flood guard.
        if (!Helpers::rate_limit('callback_ref:' . $ref, 10, 300)) {
            return new \WP_Error('rate_limited', 'Too many attempts for this reference', ['status' => 429]);
        }
        if (!Helpers::rate_limit('callback_ip:' . Helpers::client_ip(), 600, 300)) {
            return new \WP_Error('rate_limited', 'Too many callbacks', ['status' => 429]);
        }
        $payload = ['sandbox_proof' => (string) $r['sandbox_proof'], 'type' => (string) $r['type']];

        if ($r['type'] === 'topup') {
            $result = PaymentService::handle_topup_callback($ref, $payload);
            return rest_ensure_response($result);
        }
        $result = PaymentService::handle_order_callback($ref, $payload);
        $order  = $result['order'];
        return rest_ensure_response([
            'ok'        => $result['ok'],
            'replay'    => $result['replay'],
            'message'   => $result['message'],
            'status'    => $order ? $order['status'] : null,
            'order_id'  => $order ? (int) $order['id'] : null,
            'provision' => $result['provision'],
        ]);
    }

    /**
     * Truthful provisioning state for one of the caller's own orders. The
     * payment page polls this instead of announcing success it cannot see.
     */
    public static function order_state(\WP_REST_Request $r)
    {
        $customer_id = get_current_user_id();
        if (!Helpers::rate_limit('order_state:' . $customer_id, 120, 300)) {
            return new \WP_Error('rate_limited', __('درخواست‌های شما زیاد است. کمی صبر کنید.', 'arvan-reseller'), ['status' => 429]);
        }
        $order = OrderService::get((int) $r['id']);
        if (!$order || (int) $order['customer_id'] !== $customer_id) {
            return new \WP_Error('not_found', __('سفارش یافت نشد.', 'arvan-reseller'), ['status' => 404]);
        }
        $provision = PaymentService::provision_state($order);
        return rest_ensure_response([
            'order_id'        => (int) $order['id'],
            'status'          => (string) $order['status'],
            'status_label'    => wp_strip_all_tags(Helpers::status_tag((string) $order['status'])),
            'provision_state' => $provision['state'],
            'message'         => $provision['message'],
            'reference'       => (string) $order['payment_ref'],
        ]);
    }

    /** Single service, resolved through the owner-scoped read path. */
    public static function me_service(\WP_REST_Request $r)
    {
        $row = Services::get_owned((int) $r['id'], get_current_user_id());
        if (!$row) {
            return new \WP_Error('not_found', __('سرویس یافت نشد.', 'arvan-reseller'), ['status' => 404]);
        }
        $connection = json_decode((string) $row['connection'], true) ?: [];
        $labelled   = [];
        foreach ($connection as $key => $value) {
            $labelled[] = ['key' => (string) $key, 'label' => Helpers::connection_label((string) $key), 'value' => $value];
        }
        unset($row['credential_id']); // upstream routing is private
        $row['connection']        = $connection;
        $row['connection_fields'] = $labelled;
        return rest_ensure_response($row);
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
                    $row['created_label'] = Helpers::jdate((string) $row['created_at'], 'j F Y — H:i');
                }
                unset($row);
                return rest_ensure_response($rows);
            case 'services':
                $rows = Services::list($uid, $page);
                foreach ($rows as &$row) {
                    $row['connection'] = json_decode((string) $row['connection'], true) ?: [];
                    $row['created_label'] = Helpers::jdate((string) $row['created_at'], 'j F Y');
                    unset($row['credential_id']); // upstream routing is private
                }
                unset($row);
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
