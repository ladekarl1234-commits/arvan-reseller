<?php
namespace ArvanReseller\Customers;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Per-customer commercial rules (spec: customer-specific commercial rules).
 * A single row per customer; every column nullable = "inherit default".
 */
final class Rules
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_customer_rules';
    }

    public static function get(int $customer_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d', $customer_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Pricing-relevant subset in PricingEngine's expected shape (or null). */
    public static function pricing_rule(int $customer_id): ?array
    {
        $row = self::get($customer_id);
        if (!$row) {
            return null;
        }
        $rule = [
            'markup_percent'   => $row['markup_percent'] !== null ? (float) $row['markup_percent'] : null,
            'discount_percent' => $row['discount_percent'] !== null ? (float) $row['discount_percent'] : null,
            'fixed_adjustment' => $row['fixed_adjustment'] !== null ? (int) $row['fixed_adjustment'] : null,
        ];
        return ($rule['markup_percent'] === null && $rule['discount_percent'] === null && $rule['fixed_adjustment'] === null)
            ? null : $rule;
    }

    /** Whitelisted upsert (SEC-3: no blind saves). */
    public static function save(int $customer_id, array $data): void
    {
        global $wpdb;
        $nullable_num = static function ($v, $cast) {
            return ($v === '' || $v === null) ? null : $cast($v);
        };
        $row = [
            'customer_id'      => $customer_id,
            'markup_percent'   => $nullable_num($data['markup_percent'] ?? null, 'floatval'),
            'discount_percent' => $nullable_num($data['discount_percent'] ?? null, 'floatval'),
            'fixed_adjustment' => $nullable_num($data['fixed_adjustment'] ?? null, 'intval'),
            'credit_limit'     => $nullable_num($data['credit_limit'] ?? null, 'intval'),
            'spending_limit'   => $nullable_num($data['spending_limit'] ?? null, 'intval'),
            'allowed_products' => implode(',', array_intersect(
                array_map('sanitize_key', (array) ($data['allowed_products'] ?? [])),
                ['cloud_server', 'cdn', 'object_storage']
            )),
            'status'           => in_array($data['status'] ?? 'active', ['active', 'blocked'], true)
                ? $data['status'] : 'active',
            'grace_days'       => $nullable_num($data['grace_days'] ?? null, 'intval'),
            'notes'            => sanitize_textarea_field($data['notes'] ?? ''),
            'updated_at'       => Helpers::now(),
        ];
        $wpdb->replace(self::table(), $row);
        Audit::log(0, 'customer_rules.saved', 'user', (string) $customer_id, ['status' => $row['status']]);
    }

    /**
     * How far this customer's wallet may go negative before the account stops
     * being extended more service, in IRT. Null = no per-customer cap.
     */
    public static function credit_limit(int $customer_id): ?int
    {
        $row = self::get($customer_id);
        return ($row && $row['credit_limit'] !== null) ? (int) $row['credit_limit'] : null;
    }

    /**
     * Has usage driven the balance past the customer's negative-credit cap?
     *
     * This is the decision the admin field «سقف اعتبار منفی» was always
     * documented to make and previously made nowhere: it is read at checkout
     * (OrderService::create) and by the policy ladder, which treats an
     * exhausted credit line as RESTRICTED regardless of the grace clock.
     *
     * @param int $available current wallet balance (may be negative)
     */
    public static function credit_exhausted(int $customer_id, int $available): bool
    {
        $limit = self::credit_limit($customer_id);
        if ($limit === null) {
            return false;
        }
        // A cap of 0 means "no negative balance at all"; a cap of 200,000 means
        // the balance may sit as low as -200,000 and no lower.
        return $available < -abs($limit);
    }

    /** Purchase gate: may this customer buy this product right now? */
    public static function can_purchase(int $customer_id, string $product): bool
    {
        $row = self::get($customer_id);
        if (!$row) {
            return true;
        }
        if (($row['status'] ?? 'active') === 'blocked') {
            return false;
        }
        $allowed = array_filter(explode(',', (string) ($row['allowed_products'] ?? '')));
        if ($allowed && !in_array($product, $allowed, true)) {
            return false;
        }
        return true;
    }
}
