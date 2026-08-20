<?php
namespace ArvanReseller\Pricing;

/**
 * Pure pricing arithmetic (spec §6). No WordPress dependency — the calling
 * layer supplies the settings and the per-customer rule; unit tests exercise
 * this class directly. All amounts are integer toman (IRT).
 *
 * Rule resolution (first non-null wins): customer markup → product markup →
 * global markup. Customer discount and fixed adjustment stack on top.
 */
final class PricingEngine
{
    public const VERSION = 1;

    /**
     * @param int        $base_cost        upstream cost in IRT
     * @param float      $global_markup    percent
     * @param float|null $product_markup   percent, overrides global
     * @param int        $fixed_adjustment signed IRT added after markup
     * @param array|null $customer_rule    ['markup_percent'=>?float,'discount_percent'=>?float,'fixed_adjustment'=>?int]
     * @return array pricing snapshot (persisted verbatim on the order)
     */
    public static function quote(
        int $base_cost,
        float $global_markup,
        ?float $product_markup = null,
        int $fixed_adjustment = 0,
        ?array $customer_rule = null
    ): array {
        if ($base_cost < 0) {
            throw new \InvalidArgumentException('base_cost must be >= 0');
        }

        $markup_source = 'global';
        $markup        = $global_markup;
        if ($product_markup !== null) {
            $markup_source = 'product';
            $markup        = $product_markup;
        }
        if ($customer_rule !== null && isset($customer_rule['markup_percent']) && $customer_rule['markup_percent'] !== null) {
            $markup_source = 'customer';
            $markup        = (float) $customer_rule['markup_percent'];
        }
        $markup = max(-100.0, $markup);

        $price = (int) round($base_cost * (1 + $markup / 100));

        $discount = 0.0;
        if ($customer_rule !== null && !empty($customer_rule['discount_percent'])) {
            $discount = min(100.0, max(0.0, (float) $customer_rule['discount_percent']));
            $price    = (int) round($price * (1 - $discount / 100));
        }

        $adjustment = $fixed_adjustment;
        if ($customer_rule !== null && isset($customer_rule['fixed_adjustment']) && $customer_rule['fixed_adjustment'] !== null) {
            $adjustment = (int) $customer_rule['fixed_adjustment'];
        }
        $price = max(0, $price + $adjustment);

        return [
            'base_cost'        => $base_cost,
            'markup_percent'   => $markup,
            'markup_source'    => $markup_source,
            'discount_percent' => $discount,
            'fixed_adjustment' => $adjustment,
            'customer_price'   => $price,
            'margin'           => $price - $base_cost,
            'currency'         => 'IRT',
            'pricing_version'  => self::VERSION,
            'quoted_at'        => gmdate('c'),
        ];
    }
}
