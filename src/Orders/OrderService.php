<?php
namespace ArvanReseller\Orders;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\Pricing;
use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * The only writer of order rows. Every status change goes through
 * transition(), which enforces the StateMachine and appends an event row —
 * optimistic `UPDATE … WHERE status = current` makes racing writers lose
 * cleanly (spec §5.2, transaction consistency).
 */
final class OrderService
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_orders';
    }

    public static function events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_order_events';
    }

    /** Whitelist of per-product config fields accepted from the client (SEC-3). */
    private static function sanitize_config(string $product, array $raw): array
    {
        $out = [];
        if ($product === 'cloud_server') {
            $out['region'] = sanitize_key($raw['region'] ?? '');
            $out['image']  = preg_replace('/[^a-z0-9\.\-]/', '', strtolower((string) ($raw['image'] ?? '')));
            $out['name']   = substr(sanitize_text_field($raw['name'] ?? ''), 0, 50);
        } elseif ($product === 'cdn') {
            $domain = strtolower(trim((string) ($raw['domain'] ?? '')));
            if (!preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain)) {
                return ['__error' => __('نام دامنه معتبر نیست.', 'arvan-reseller')];
            }
            $out['domain'] = $domain;
        } elseif ($product === 'object_storage') {
            $bucket = strtolower(trim((string) ($raw['bucket'] ?? '')));
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{2,62}$/', $bucket)) {
                return ['__error' => __('نام باکت معتبر نیست (۳ تا ۶۳ نویسه، حروف کوچک/عدد/خط تیره).', 'arvan-reseller')];
            }
            $out['bucket'] = $bucket;
        }
        return $out;
    }

    /**
     * Create a priced order in pending_payment (spec §5.3 step 1).
     * @return array|\WP_Error order row
     */
    public static function create(int $customer_id, string $product, string $plan_id, array $raw_config)
    {
        if (!in_array($product, Catalog::enabled_products(), true)) {
            return new \WP_Error('bad_product', __('این محصول در حال حاضر ارائه نمی‌شود.', 'arvan-reseller'));
        }
        if (!Rules::can_purchase($customer_id, $product)) {
            return new \WP_Error('blocked', __('امکان خرید این محصول برای حساب شما فعال نیست. با پشتیبانی تماس بگیرید.', 'arvan-reseller'));
        }
        $plan = Catalog::find_plan($product, $plan_id);
        if (!$plan) {
            return new \WP_Error('bad_plan', __('پلن انتخابی یافت نشد. صفحه را نوسازی کنید.', 'arvan-reseller'));
        }
        if ((int) $plan['base_cost'] <= 0) {
            return new \WP_Error('unpriced', __('این پلن هنوز قیمت‌گذاری نشده است.', 'arvan-reseller'));
        }
        $config = self::sanitize_config($product, $raw_config);
        if (isset($config['__error'])) {
            return new \WP_Error('bad_config', $config['__error']);
        }

        $quote = Pricing::quote($product, $plan_id, (int) $plan['base_cost'], $customer_id);
        $price = (int) $quote['customer_price'];

        // Enforce the per-customer spending cap at checkout (spec §customer
        // rules). credit_limit is NOT a checkout gate: orders are settled via
        // the gateway (net-zero on the wallet), so it governs how far
        // usage-driven balance may go negative — that lives in the policy
        // engine (grace → restricted), not here.
        $rule = \ArvanReseller\Customers\Rules::get($customer_id);
        if ($rule && $rule['spending_limit'] !== null) {
            $consumed = \ArvanReseller\Wallet\Ledger::balance($customer_id)['consumed'];
            if (($consumed + $price) > (int) $rule['spending_limit']) {
                return new \WP_Error('spending_limit', __('این خرید از سقف مجاز حساب شما عبور می‌کند. با پشتیبانی تماس بگیرید.', 'arvan-reseller'));
            }
        }

        global $wpdb;
        $ref = 'ARV-' . strtoupper(bin2hex(random_bytes(6)));
        $ok  = $wpdb->insert(self::table(), [
            'customer_id' => $customer_id,
            'product'     => $product,
            'plan_id'     => $plan_id,
            'config'      => wp_json_encode($config),
            'status'      => StateMachine::PENDING_PAYMENT,
            'pricing'     => wp_json_encode($quote),
            'amount'      => (int) $quote['customer_price'],
            'base_cost'   => (int) $quote['base_cost'],
            'margin'      => (int) $quote['margin'],
            'currency'    => 'IRT',
            'payment_ref' => $ref,
            'is_demo'     => Plugin::demo_mode() ? 1 : 0,
            'created_at'  => Helpers::now(),
            'updated_at'  => Helpers::now(),
        ]);
        if (!$ok) {
            return new \WP_Error('db', __('ثبت سفارش ناموفق بود. دوباره تلاش کنید.', 'arvan-reseller'));
        }
        $order_id = (int) $wpdb->insert_id;
        self::record_event($order_id, StateMachine::DRAFT, StateMachine::PENDING_PAYMENT, 'customer:' . $customer_id, 'order created');
        return self::get($order_id);
    }

    public static function get(int $order_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d', $order_id
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function by_ref(string $payment_ref): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE payment_ref = %s', $payment_ref
        ), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Atomic, state-machine-checked transition. Returns false when the row
     * was not in $from anymore (another writer won) or the move is illegal.
     */
    public static function transition(int $order_id, string $from, string $to, string $actor = 'system', string $note = ''): bool
    {
        if (!StateMachine::can($from, $to)) {
            return false;
        }
        global $wpdb;
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
            $to, Helpers::now(), $order_id, $from
        ));
        if ($updated !== 1) {
            return false;
        }
        self::record_event($order_id, $from, $to, $actor, $note);
        return true;
    }

    /**
     * Payment claim (spec §5.3 step 4): move to `paid` iff still payable AND
     * the verified amount matches — one UPDATE, zero race window.
     */
    public static function claim_paid(string $payment_ref, int $verified_amount, string $transaction_id): ?array
    {
        global $wpdb;
        $payable = StateMachine::payable();
        // Capture the true prior status for an accurate event record (the
        // claim UPDATE below still enforces the payable-state guard atomically).
        $prior = (string) $wpdb->get_var($wpdb->prepare(
            'SELECT status FROM ' . self::table() . ' WHERE payment_ref = %s', $payment_ref
        ));
        $in      = implode(',', array_fill(0, count($payable), '%s'));
        $params  = array_merge(
            [StateMachine::PAID, Helpers::now(), $payment_ref, $verified_amount],
            $payable
        );
        $updated = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = %s, updated_at = %s
             WHERE payment_ref = %s AND amount = %d AND status IN ($in)",
            ...$params
        ));
        $order = self::by_ref($payment_ref);
        if ($updated === 1 && $order) {
            $from = in_array($prior, $payable, true) ? $prior : StateMachine::PENDING_PAYMENT;
            self::record_event((int) $order['id'], $from, StateMachine::PAID, 'payment', 'tx:' . $transaction_id);
            return $order;
        }
        return null; // replay or mismatch — caller answers idempotently
    }

    private static function record_event(int $order_id, string $from, string $to, string $actor, string $note): void
    {
        global $wpdb;
        $wpdb->insert(self::events_table(), [
            'order_id'    => $order_id,
            'from_status' => $from,
            'to_status'   => $to,
            'actor'       => substr($actor, 0, 64),
            'note'        => substr($note, 0, 500),
            'created_at'  => Helpers::now(),
        ]);
    }

    public static function events(int $order_id): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT from_status, to_status, actor, note, created_at FROM ' . self::events_table() .
            ' WHERE order_id = %d ORDER BY id ASC', $order_id
        ), ARRAY_A) ?: [];
    }

    /** Owner-scoped list for customers; unscoped for admin ($customer_id = 0). */
    public static function list(int $customer_id = 0, string $status = '', int $page = 1, int $per_page = 20): array
    {
        global $wpdb;
        $where  = [];
        $params = [];
        if ($customer_id > 0) {
            $where[]  = 'customer_id = %d';
            $params[] = $customer_id;
        }
        if ($status !== '') {
            $where[]  = 'status = %s';
            $params[] = $status;
        }
        $sql = 'SELECT * FROM ' . self::table()
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $per_page;
        $params[] = max(0, ($page - 1) * $per_page);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    }
}
