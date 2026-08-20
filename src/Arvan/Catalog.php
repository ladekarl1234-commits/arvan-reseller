<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Plugin;

defined('ABSPATH') || exit;

/**
 * Cached, customer-priced catalog access. Upstream metadata is relatively
 * static → 6h transient cache with manual refresh (spec §12); no external
 * HTTP during normal page render.
 */
final class Catalog
{
    private const TTL = 6 * HOUR_IN_SECONDS;

    public const PRODUCTS = ['cloud_server', 'cdn', 'object_storage'];

    public static function product_label(string $product): string
    {
        $labels = [
            'cloud_server'   => __('سرور ابری', 'arvan-reseller'),
            'cdn'            => __('شبکه توزیع محتوا', 'arvan-reseller'),
            'object_storage' => __('فضای ابری', 'arvan-reseller'),
        ];
        return $labels[$product] ?? $product;
    }

    /** @return array plans as arrays (cache-friendly), base costs included */
    public static function plans(string $product): array
    {
        if (!in_array($product, self::PRODUCTS, true)) {
            return [];
        }
        $key    = 'arvrs_catalog_' . $product . (Plugin::demo_mode() ? '_demo' : '_real');
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $plans = array_map(static function (Plan $p) {
                return $p->to_array();
            }, Plugin::arvan($product)->plans($product));
        } catch (ProviderError $e) {
            return []; // storefront shows the retryable empty/error state
        }
        set_transient($key, $plans, self::TTL);
        return $plans;
    }

    public static function options(string $product): array
    {
        $key    = 'arvrs_catopt_' . $product . (Plugin::demo_mode() ? '_demo' : '_real');
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $options = Plugin::arvan($product)->options($product);
        } catch (ProviderError $e) {
            return [];
        }
        set_transient($key, $options, self::TTL);
        return $options;
    }

    public static function find_plan(string $product, string $plan_id): ?array
    {
        foreach (self::plans($product) as $plan) {
            if ($plan['id'] === $plan_id) {
                return $plan;
            }
        }
        return null;
    }

    public static function flush(): void
    {
        foreach (self::PRODUCTS as $p) {
            foreach (['_demo', '_real'] as $suffix) {
                delete_transient('arvrs_catalog_' . $p . $suffix);
                delete_transient('arvrs_catopt_' . $p . $suffix);
            }
        }
    }
}
