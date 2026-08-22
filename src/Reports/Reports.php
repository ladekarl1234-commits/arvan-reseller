<?php
namespace ArvanReseller\Reports;

defined('ABSPATH') || exit;

/**
 * Period reporting (spec §7). The dashboard used to show lifetime-cumulative
 * sums only — numbers that can only go up and therefore cannot show a revenue
 * cliff, a margin squeeze or churn. Everything here is bounded by a date
 * window and served by an index.
 *
 * Revenue has two sources and both are counted: one-time `orders` (the sale)
 * and `usage_records` (recurring term charges written by Billing\Renewals,
 * plus metered usage). `usage_records` stores cost and price separately, so
 * margin is measured on both streams instead of assumed on one.
 *
 * All amounts are integer IRT.
 */
final class Reports
{
    /**
     * @param string $from_utc 'Y-m-d H:i:s' inclusive
     * @param string $to_utc   'Y-m-d H:i:s' exclusive
     * @return array{revenue:int,cost:int,margin:int,orders:int,services:int}
     */
    public static function period(string $from_utc, string $to_utc, bool $include_demo = false): array
    {
        global $wpdb;
        $p     = $wpdb->prefix . 'arvrs_';
        $demo  = $include_demo ? '' : ' AND is_demo = 0';

        // Served by orders KEY created_at.
        $sale = $wpdb->get_row($wpdb->prepare(
            'SELECT COUNT(*) AS orders,
                    COALESCE(SUM(amount),0) AS revenue,
                    COALESCE(SUM(base_cost),0) AS cost
             FROM ' . $p . "orders
             WHERE status IN ('paid','provisioning','active') AND created_at >= %s AND created_at < %s" . $demo,
            $from_utc, $to_utc
        ), ARRAY_A) ?: [];

        // Served by usage_records KEY customer_period; period_start is the
        // business date of the charge, not the row's insert time.
        $recurring = $wpdb->get_row($wpdb->prepare(
            'SELECT COALESCE(SUM(price),0) AS revenue, COALESCE(SUM(cost),0) AS cost
             FROM ' . $p . 'usage_records
             WHERE period_start >= %s AND period_start < %s' . $demo,
            $from_utc, $to_utc
        ), ARRAY_A) ?: [];

        $services = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . $p . 'services WHERE created_at >= %s AND created_at < %s' . $demo,
            $from_utc, $to_utc
        ));

        $revenue = (int) ($sale['revenue'] ?? 0) + (int) ($recurring['revenue'] ?? 0);
        $cost    = (int) ($sale['cost'] ?? 0) + (int) ($recurring['cost'] ?? 0);

        return [
            'revenue'  => $revenue,
            'cost'     => $cost,
            'margin'   => $revenue - $cost,
            'orders'   => (int) ($sale['orders'] ?? 0),
            'services' => $services,
        ];
    }

    /**
     * Monthly buckets for the last N months, oldest first, gaps filled with
     * zeros so a chart cannot silently skip a dead month.
     *
     * @return array<string,array{revenue:int,cost:int,margin:int,orders:int,services:int}> keyed 'YYYY-MM'
     */
    public static function monthly(int $months = 12, bool $include_demo = false): array
    {
        $months = min(60, max(1, $months));
        $out    = [];
        // Anchor on the first of the current UTC month and step backwards.
        $cursor = strtotime(gmdate('Y-m-01 00:00:00'));
        $starts = [];
        for ($i = 0; $i < $months; $i++) {
            $starts[] = $cursor;
            $cursor   = strtotime('-1 month', $cursor);
        }
        foreach (array_reverse($starts) as $start) {
            $key = gmdate('Y-m', $start);
            $out[$key] = self::period(
                gmdate('Y-m-d H:i:s', $start),
                gmdate('Y-m-d H:i:s', strtotime('+1 month', $start)),
                $include_demo
            );
        }
        return $out;
    }

    /**
     * The same window, split by product line — the reseller's pricing lever:
     * which product actually carries margin and which is sold at cost.
     *
     * @return array<int,array{product:string,orders:int,revenue:int,cost:int,margin:int}>
     */
    public static function by_product(string $from_utc, string $to_utc, bool $include_demo = false): array
    {
        global $wpdb;
        $demo = $include_demo ? '' : ' AND is_demo = 0';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT product,
                    COUNT(*) AS orders,
                    COALESCE(SUM(amount),0) AS revenue,
                    COALESCE(SUM(base_cost),0) AS cost
             FROM ' . $wpdb->prefix . "arvrs_orders
             WHERE status IN ('paid','provisioning','active') AND created_at >= %s AND created_at < %s" . $demo . '
             GROUP BY product ORDER BY revenue DESC',
            $from_utc, $to_utc
        ), ARRAY_A) ?: [];

        $out = [];
        foreach ($rows as $row) {
            $revenue = (int) $row['revenue'];
            $cost    = (int) $row['cost'];
            $out[]   = [
                'product' => (string) $row['product'],
                'orders'  => (int) $row['orders'],
                'revenue' => $revenue,
                'cost'    => $cost,
                'margin'  => $revenue - $cost,
            ];
        }
        return $out;
    }

    /**
     * Recurring revenue: every active service's renewal price normalised to a
     * 30-day month. Rounded per row so a 7-day term does not lose rials to a
     * single division at the end.
     */
    public static function mrr(bool $include_demo = false): int
    {
        global $wpdb;
        $demo = $include_demo ? '' : ' AND is_demo = 0';
        return (int) $wpdb->get_var(
            'SELECT COALESCE(SUM(ROUND(renewal_price * 30 / term_days)),0)
             FROM ' . $wpdb->prefix . "arvrs_services
             WHERE status IN ('active','at_risk','suspended')
               AND renews_at IS NOT NULL
               AND term_days > 0 AND renewal_price > 0" . $demo
        );
    }

    /**
     * Services that stopped renewing in the window, over the services that
     * were live at the window's start. 0.0 when nothing was live to churn.
     */
    public static function churn(string $from_utc, string $to_utc): float
    {
        global $wpdb;
        $table = $wpdb->prefix . 'arvrs_services';

        // cancelled_at is authoritative; rows cancelled before that column
        // existed only carry the status plus updated_at, so fall back to it.
        // 'terminated' is not, and has never been, a value Services::STATUSES
        // allows, so that arm of the old IN() list could never match anything.
        $lost = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE is_demo = 0 AND (
                   (cancelled_at IS NOT NULL AND cancelled_at >= %s AND cancelled_at < %s)
                OR (cancelled_at IS NULL AND status = 'cancelled' AND updated_at >= %s AND updated_at < %s)
             )",
            $from_utc, $to_utc, $from_utc, $to_utc
        ));

        // A legacy-cancelled row (no cancelled_at, status = 'cancelled', the
        // same fallback the numerator uses) whose cancellation predates the
        // window must NOT count as "still live at start" — it counted in both
        // places before this, inflating the denominator with services that
        // had already churned.
        $at_start = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table
             WHERE is_demo = 0 AND created_at < %s AND (
                   cancelled_at >= %s
                OR (cancelled_at IS NULL AND NOT (status = 'cancelled' AND updated_at < %s))
             )",
            $from_utc, $from_utc, $from_utc
        ));

        return $at_start > 0 ? round($lost / $at_start, 4) : 0.0;
    }
}
