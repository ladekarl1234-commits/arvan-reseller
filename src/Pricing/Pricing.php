<?php
namespace ArvanReseller\Pricing;

use ArvanReseller\Customers\Rules;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Settings-aware facade over the pure PricingEngine: loads global/product
 * markup and the per-customer rule, returns the authoritative server-side
 * quote (HC-6). Templates and REST call ONLY this.
 */
final class Pricing
{
    /**
     * Per-request memo of the customer's pricing rule. quote() is called once
     * per plan and a storefront renders ~20 plans, so without this the same
     * single customer_rules row is fetched ~20 times per page render.
     * @var array<int,array|null>
     */
    private static $rules = [];

    /** @return array pricing snapshot (see PricingEngine::quote) */
    public static function quote(string $product, string $plan_id, int $base_cost, int $customer_id = 0): array
    {
        $product_markups = (array) Options::get('product_markup', []);
        $product_markup  = isset($product_markups[$product]) && $product_markups[$product] !== ''
            ? (float) $product_markups[$product] : null;

        $customer_rule = $customer_id ? self::rule($customer_id) : null;

        $quote = PricingEngine::quote(
            $base_cost,
            (float) Options::get('global_markup', 20.0),
            $product_markup,
            (int) Options::get('fixed_adjustment', 0),
            $customer_rule
        );
        $quote['product'] = $product;
        $quote['plan_id'] = $plan_id;
        return $quote;
    }

    /** @return array|null */
    private static function rule(int $customer_id)
    {
        if (!array_key_exists($customer_id, self::$rules)) {
            self::$rules[$customer_id] = Rules::pricing_rule($customer_id);
        }
        return self::$rules[$customer_id];
    }

    /**
     * Drop the memo after an admin edits a customer's rules, so a save and a
     * re-render inside the same request cannot quote the old price.
     */
    public static function flush_rules(int $customer_id = 0): void
    {
        if ($customer_id > 0) {
            unset(self::$rules[$customer_id]);
            return;
        }
        self::$rules = [];
    }
}
