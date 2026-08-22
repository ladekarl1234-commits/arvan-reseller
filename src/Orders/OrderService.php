<?php
namespace ArvanReseller\Orders;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Customers\Rules;
use ArvanReseller\Plugin;
use ArvanReseller\Pricing\Pricing;
use ArvanReseller\Services\Services;
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

    /**
     * Is $value one of the ids the catalog currently advertises under $key?
     *
     * The catalog is the *offer*: showing a region picker and then accepting a
     * region that is not in it is how a customer pays and only then discovers
     * that their flavor id belongs to a different region. When the catalog is
     * unavailable (upstream outage → empty options array) this passes the value
     * through: the provider validates against the live catalog before creating
     * anything, so an outage degrades to today's behaviour instead of blocking
     * every sale.
     */
    private static function in_catalog(string $product, string $key, string $value): bool
    {
        $options = Catalog::options($product);
        $list    = isset($options[$key]) && is_array($options[$key]) ? $options[$key] : [];
        if (!$list) {
            return true;
        }
        foreach ($list as $entry) {
            if ((string) (is_array($entry) ? ($entry['id'] ?? '') : $entry) === $value) {
                return true;
            }
        }
        return false;
    }

    /** Whitelist of per-product config fields accepted from the client (SEC-3). */
    private static function sanitize_config(string $product, array $raw): array
    {
        $out = [];
        if ($product === 'cloud_server') {
            $out['region'] = sanitize_key($raw['region'] ?? '');
            $out['image']  = preg_replace('/[^a-z0-9\.\-]/', '', strtolower((string) ($raw['image'] ?? '')));
            $out['name']   = substr(sanitize_text_field($raw['name'] ?? ''), 0, 50);
            if ($out['region'] === '' || !self::in_catalog($product, 'regions', $out['region'])) {
                return ['__error' => __('منطقه انتخابی در دسترس نیست. یکی از مناطق فهرست را انتخاب کنید.', 'arvan-reseller')];
            }
            if ($out['image'] === '' || !self::in_catalog($product, 'images', $out['image'])) {
                return ['__error' => __('سیستم‌عامل انتخابی در دسترس نیست. یکی از گزینه‌های فهرست را انتخاب کنید.', 'arvan-reseller')];
            }
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
            // The catalog advertises a region for object storage and the
            // provider honours `$config['region']` — dropping the key here is
            // what silently forced every bucket into the default region.
            $region = sanitize_key($raw['region'] ?? '');
            if ($region !== '') {
                if (!self::in_catalog($product, 'regions', $region)) {
                    return ['__error' => __('منطقه انتخابی در دسترس نیست. یکی از مناطق فهرست را انتخاب کنید.', 'arvan-reseller')];
                }
                $out['region'] = $region;
            }
        }
        return $out;
    }

    /**
     * CDN/object storage only: is the requested domain/bucket already a live
     * service belonging to a DIFFERENT customer? `Services::by_remote()`
     * indexes on (product, remote_id), and the remote_id for these two
     * products IS the domain/bucket the customer typed in.
     *
     * @return string|null the customer-facing refusal message, or null if clear
     */
    private static function name_conflict(string $product, int $customer_id, array $config): ?string
    {
        if ($product === 'cdn') {
            $key = (string) ($config['domain'] ?? '');
        } elseif ($product === 'object_storage') {
            $key = (string) ($config['bucket'] ?? '');
        } else {
            return null;
        }
        if ($key === '') {
            return null;
        }
        $existing = Services::by_remote($product, $key);
        if ($existing && (int) $existing['customer_id'] !== $customer_id
            && in_array((string) $existing['status'], Services::LIVE_STATUSES, true)) {
            return $product === 'cdn'
                ? __('این دامنه قبلاً برای مشتری دیگری ثبت شده است.', 'arvan-reseller')
                : __('این نام باکت قبلاً برای مشتری دیگری ثبت شده است.', 'arvan-reseller');
        }
        return null;
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
        // CDN/object storage use the customer-supplied domain/bucket as the
        // provider's reconciliation key (RealProvider::create_cdn/create_bucket
        // adopt whatever already exists under that name). Without this check a
        // second customer ordering a name already live under a different
        // account would have their order silently routed onto — and later
        // able to delete — the first customer's resource.
        $conflict = self::name_conflict($product, $customer_id, $config);
        if ($conflict !== null) {
            return new \WP_Error('name_taken', $conflict);
        }

        $quote = Pricing::quote($product, $plan_id, (int) $plan['base_cost'], $customer_id);
        $price = (int) $quote['customer_price'];

        // Two distinct per-customer caps, both enforced here (spec §customer
        // rules). `spending_limit` is lifetime spend; `credit_limit` is how far
        // usage is allowed to drive the wallet negative before the account
        // stops being extended more service. Orders settle via the gateway
        // (net-zero on the wallet), so credit_limit does not gate on the order
        // amount — it gates on the debt already standing.
        $rule    = Rules::get($customer_id);
        $balance = ($rule && ($rule['spending_limit'] !== null || $rule['credit_limit'] !== null))
            ? \ArvanReseller\Wallet\Ledger::balance($customer_id)
            : null;
        if ($balance !== null && $rule['spending_limit'] !== null) {
            if (($balance['consumed'] + $price) > (int) $rule['spending_limit']) {
                return new \WP_Error('spending_limit', __('این خرید از سقف مجاز حساب شما عبور می‌کند. با پشتیبانی تماس بگیرید.', 'arvan-reseller'));
            }
        }
        if ($balance !== null && Rules::credit_exhausted($customer_id, (int) $balance['available'])) {
            return new \WP_Error('credit_limit', __('بدهی حساب شما از سقف اعتبار مجاز عبور کرده است. برای خرید جدید ابتدا کیف پول را شارژ کنید.', 'arvan-reseller'));
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
     *
     * The three ways this can fail are NOT interchangeable, and collapsing them
     * into one null was a real defect: a gateway confirming the wrong amount
     * looked exactly like a duplicate callback, so the caller cheerfully
     * answered "already processed" while the order sat unpaid and unaudited.
     *
     * @return array{kind:string,order:?array} kind ∈ claimed|replay|amount_mismatch|not_found
     */
    public static function claim_paid(string $payment_ref, int $verified_amount, string $transaction_id): array
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
            return ['kind' => 'claimed', 'order' => $order];
        }
        if (!$order) {
            return ['kind' => 'not_found', 'order' => null];
        }
        // Still payable but the UPDATE missed ⇒ the `amount = %d` predicate is
        // what rejected it. That is money confirmed against the wrong figure,
        // not a replay.
        if (in_array((string) $order['status'], $payable, true) && (int) $order['amount'] !== $verified_amount) {
            return ['kind' => 'amount_mismatch', 'order' => $order];
        }
        return ['kind' => 'replay', 'order' => $order];
    }

    /**
     * Append a timeline note without changing status — how a correlation id
     * from an upstream call lands on the order the operator is actually
     * looking at, instead of only inside an audit blob they have to hunt for.
     */
    public static function note(int $order_id, string $actor, string $note): void
    {
        $order = self::get($order_id);
        if (!$order) {
            return;
        }
        $status = (string) $order['status'];
        self::record_event($order_id, $status, $status, $actor, $note);
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

    /**
     * Owner-scoped list for customers; unscoped for admin ($customer_id = 0).
     *
     * `$search` is the support desk's entry point: a customer disputing a
     * charge quotes a payment reference, an order number or their email
     * address, and all three are point lookups here (payment_ref is UNIQUE,
     * id is the primary key, email resolves to one user id).
     */
    public static function list(int $customer_id = 0, string $status = '', int $page = 1, int $per_page = 20, string $search = ''): array
    {
        global $wpdb;
        [$where, $params] = self::list_filters($customer_id, $status, $search);
        if ($where === null) {
            return []; // search term that cannot match any row
        }
        $sql = 'SELECT * FROM ' . self::table()
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT %d OFFSET %d';
        $params[] = $per_page;
        $params[] = max(0, ($page - 1) * $per_page);
        return $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A) ?: [];
    }

    /** Row count for the same filter set, so the admin can page properly. */
    public static function count(int $customer_id = 0, string $status = '', string $search = ''): int
    {
        global $wpdb;
        [$where, $params] = self::list_filters($customer_id, $status, $search);
        if ($where === null) {
            return 0;
        }
        $sql = 'SELECT COUNT(*) FROM ' . self::table()
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    /**
     * @return array{0:?string[],1:array} [where fragments (null = impossible), params]
     */
    private static function list_filters(int $customer_id, string $status, string $search): array
    {
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
        $search = trim($search);
        if ($search !== '') {
            if (strpos($search, '@') !== false) {
                $user = get_user_by('email', $search);
                if (!$user) {
                    return [null, []];
                }
                $where[]  = 'customer_id = %d';
                $params[] = (int) $user->ID;
            } else {
                $where[]  = '(payment_ref = %s OR id = %d)';
                $params[] = $search;
                $params[] = (int) $search; // 0 for a non-numeric ref — never matches an id
            }
        }
        return [$where, $params];
    }
}
