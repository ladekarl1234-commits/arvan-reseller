<?php
namespace ArvanReseller\Pricing;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Admin-maintained base-cost table (spec §6, ADR-0007). ArvanCloud publishes
 * no pricing API, so upstream cost is configuration: seeded from the public
 * pricing page with a source stamp, editable by the admin, and swappable for
 * a future pricing API behind this same class.
 */
final class BaseCosts
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_base_costs';
    }

    public static function get(string $product, string $plan_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT base_cost FROM ' . self::table() . ' WHERE product = %s AND plan_id = %s',
            $product, $plan_id
        ));
    }

    public static function set(string $product, string $plan_id, int $base_cost, string $source): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . self::table() . ' (product, plan_id, base_cost, currency, source, updated_at)
             VALUES (%s, %s, %d, %s, %s, %s)
             ON DUPLICATE KEY UPDATE base_cost = VALUES(base_cost), source = VALUES(source), updated_at = VALUES(updated_at)',
            $product, $plan_id, max(0, $base_cost), 'IRT', substr($source, 0, 191), Helpers::now()
        ));
    }

    public static function all(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT product, plan_id, base_cost, source, updated_at FROM ' . self::table() . ' ORDER BY product, plan_id',
            ARRAY_A
        ) ?: [];
    }

    /**
     * Idempotent seed of representative monthly base costs. Values are
     * transcriptions of the public pricing page (arvancloud.ir/fa/pricing) at
     * plugin-release time — the admin refreshes them in Pricing settings.
     */
    public static function seed_defaults(): void
    {
        $source = 'seed: arvancloud.ir/fa/pricing @ release 1.0.0';
        $rows = [
            // Cloud Server (ECC) — monthly, IRT
            ['cloud_server', 'g1-1-1-25',  1050000],
            ['cloud_server', 'g1-2-2-25',  2100000],
            ['cloud_server', 'g1-4-4-50',  4400000],
            ['cloud_server', 'g1-8-8-100', 8800000],
            // CDN — monthly plan tiers (cdn-basic carries a small reseller
            // management fee; a zero base cost would render as buyable but be
            // rejected at checkout, so no seeded plan is ever priced at 0)
            ['cdn', 'cdn-basic',    200000],
            ['cdn', 'cdn-growth',   1500000],
            ['cdn', 'cdn-pro',      6500000],
            // Object Storage — monthly packages
            ['object_storage', 'os-100gb', 450000],
            ['object_storage', 'os-500gb', 2000000],
            ['object_storage', 'os-1tb',   3800000],
        ];
        global $wpdb;
        foreach ($rows as [$product, $plan, $cost]) {
            // Seed only if the row is absent; never clobber an admin-edited price.
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE product = %s AND plan_id = %s', $product, $plan
            ));
            if (!$exists) {
                self::set($product, $plan, $cost, $source);
            }
        }
    }
}
