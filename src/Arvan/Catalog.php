<?php
namespace ArvanReseller\Arvan;

use ArvanReseller\Plugin;

defined('ABSPATH') || exit;

/**
 * Cached, customer-priced catalog access. Upstream metadata is relatively
 * static → 6h transient cache with manual refresh (spec §12).
 *
 * The miss path is what matters here: a cold or expired cache used to let
 * every concurrent storefront request make its own 20s upstream call, and an
 * upstream failure was never cached at all, so a provider outage turned into
 * a storefront outage by PHP-worker exhaustion. Three cheap guards fix that:
 * one refresher at a time, a short negative cache, and a long-lived stale copy
 * everyone else is served from meanwhile.
 */
final class Catalog
{
    private const TTL = 6 * HOUR_IN_SECONDS;

    /** How long an upstream failure is remembered — one call per minute, not per view. */
    private const NEGATIVE_TTL = 60;

    /** Stale copy kept well past TTL purely to serve readers during a refresh. */
    private const STALE_TTL = DAY_IN_SECONDS;

    private const LOCK_TTL = 30;

    public const PRODUCTS = ['cloud_server', 'cdn', 'object_storage'];

    /** Products the reseller has switched on (wizard/product selection). */
    public static function enabled_products(): array
    {
        $enabled = (array) \ArvanReseller\Support\Options::get('enabled_products', self::PRODUCTS);
        return array_values(array_intersect(self::PRODUCTS, $enabled)) ?: self::PRODUCTS;
    }

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
        return self::remember(self::key('arvrs_catalog_', $product), static function () use ($product) {
            return array_map(static function (Plan $p) {
                return $p->to_array();
            }, Plugin::arvan($product)->plans($product));
        });
    }

    public static function options(string $product): array
    {
        if (!in_array($product, self::PRODUCTS, true)) {
            return [];
        }
        return self::remember(self::key('arvrs_catopt_', $product), static function () use ($product) {
            return Plugin::arvan($product)->options($product);
        });
    }

    private static function key(string $prefix, string $product): string
    {
        return $prefix . $product . (Plugin::demo_mode() ? '_demo' : '_real');
    }

    /**
     * Cache-with-guards. `$fetch` is the only thing that may talk upstream and
     * at most one request per site runs it at a time.
     *
     * The lock is `wp_cache_add`, which is only cross-request when a persistent
     * object cache is installed; without one it degrades to today's behaviour
     * rather than getting worse, and the negative cache still bounds an outage
     * to one upstream call per minute.
     */
    private static function remember(string $key, callable $fetch): array
    {
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }

        // Negative caching: an outage costs one upstream call a minute, not one
        // per page view. The marker lives in its OWN key so a failure can never
        // overwrite the good value with an empty catalog.
        if (get_transient($key . '_neg')) {
            return self::stale($key);
        }

        if (!wp_cache_add($key . '_lock', 1, 'arvrs_catalog', self::LOCK_TTL)) {
            return self::stale($key); // someone else is already refreshing
        }

        try {
            $fresh = $fetch();
        } catch (ProviderError $e) {
            set_transient($key . '_neg', 1, self::NEGATIVE_TTL);
            wp_cache_delete($key . '_lock', 'arvrs_catalog');
            return self::stale($key);
        }

        delete_transient($key . '_neg');
        set_transient($key, $fresh, self::TTL);
        set_transient($key . '_stale', $fresh, self::STALE_TTL);
        wp_cache_delete($key . '_lock', 'arvrs_catalog');
        return $fresh;
    }

    private static function stale(string $key): array
    {
        $stale = get_transient($key . '_stale');
        return is_array($stale) ? $stale : [];
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
                foreach (['arvrs_catalog_' . $p . $suffix, 'arvrs_catopt_' . $p . $suffix] as $key) {
                    delete_transient($key);
                    delete_transient($key . '_stale');
                    delete_transient($key . '_neg');
                    wp_cache_delete($key . '_lock', 'arvrs_catalog');
                }
            }
        }
    }
}
