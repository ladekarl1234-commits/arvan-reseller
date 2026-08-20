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
    /** @return array pricing snapshot (see PricingEngine::quote) */
    public static function quote(string $product, string $plan_id, int $base_cost, int $customer_id = 0): array
    {
        $product_markups = (array) Options::get('product_markup', []);
        $product_markup  = isset($product_markups[$product]) && $product_markups[$product] !== ''
            ? (float) $product_markups[$product] : null;

        $customer_rule = $customer_id ? Rules::pricing_rule($customer_id) : null;

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
}
