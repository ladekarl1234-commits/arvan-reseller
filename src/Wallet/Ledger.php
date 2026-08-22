<?php
namespace ArvanReseller\Wallet;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Append-only financial ledger (spec §7, ADR-0007). Balances are DERIVED —
 * there is no mutable balance column anywhere. Idempotency comes from the
 * UNIQUE KEY (ref_type, ref_id, type): re-inserting the same business event
 * is a silent no-op, which is exactly the replay-safety we want (HC-7).
 *
 * Derivation happens in SQL (one indexed aggregate per customer, or one
 * GROUP BY for a whole admin page) — never by pulling a customer's history
 * into PHP. `derive()` survives as the pure reference implementation the unit
 * tests pin the SQL against.
 */
final class Ledger
{
    public const CREDIT_TYPES = ['topup', 'payment', 'refund', 'promo_credit', 'release'];
    public const DEBIT_TYPES  = ['purchase', 'usage_debit', 'service_charge', 'adjustment', 'reservation'];

    /** Types that represent money actually spent (vs. money merely held). */
    public const CONSUMING_TYPES = ['usage_debit', 'service_charge', 'purchase'];

    private const CACHE_GROUP = 'arvrs_wallet';
    private const CACHE_TTL   = 300;

    /** Bounds on the negative-period walk: 20 pages × 200 rows = 4,000 entries. */
    private const WALK_PAGE  = 200;
    private const WALK_PAGES = 20;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_ledger';
    }

    /**
     * Append one entry. Returns the row ID, or 0 when the (ref_type, ref_id,
     * type) tuple already exists — i.e. a replay.
     *
     * `$is_demo` defaults to null, which stamps the entry from the AMBIENT
     * plugin mode — correct for wallet actions with no originating row
     * (top-up, admin adjustment, refund). A caller that is debiting FOR a
     * specific service or order (renewal charge, usage debit) must pass that
     * row's own `is_demo` explicitly: the entry is a permanent fact about the
     * thing it billed, not about whichever mode the site happened to be in
     * the moment cron ran — a demo service must never manufacture real
     * revenue just because the reseller has since gone live.
     */
    public static function append(
        int $customer_id,
        string $type,
        int $amount,
        string $ref_type,
        string $ref_id,
        string $description = '',
        string $actor = 'system',
        ?bool $is_demo = null
    ): int {
        global $wpdb;

        $direction = self::direction_of($type);
        if ($direction === null || $amount < 0) {
            throw new \InvalidArgumentException('Invalid ledger entry: ' . $type);
        }

        // Stamp demo rows so admin money views can exclude them in real
        // operation (spec §11).
        $is_demo = ($is_demo === null ? self::demo_mode() : $is_demo) ? 1 : 0;

        // INSERT IGNORE + unique key = atomic idempotency without SELECT-then-INSERT races.
        $sql = $wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (customer_id, type, direction, amount, currency, ref_type, ref_id, description, actor, is_demo, created_at)
              VALUES (%d, %s, %s, %d, %s, %s, %s, %s, %s, %d, %s)',
            $customer_id, $type, $direction, $amount, 'IRT',
            $ref_type, $ref_id, $description, $actor, $is_demo, Helpers::now()
        );
        $wpdb->last_error = '';
        $wpdb->query($sql);
        // rows_affected (not insert_id) is the portable duplicate signal:
        // MySQL leaves insert_id stale-or-zero after an ignored insert and the
        // SQLite integration layer leaves it stale — affected rows is 0 on
        // both when the unique key already existed.
        if ((int) $wpdb->rows_affected === 0) {
            // Distinguish an ignored duplicate (safe replay → 0) from a real
            // DB failure (deadlock, disk full) that must NOT be reported as a
            // benign replay — a dropped credit is silent money loss.
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Ledger append failed: ' . $wpdb->last_error);
            }
            // MySQL's INSERT IGNORE also downgrades *data* errors — ref_id
            // truncation past varchar(64), an out-of-range amount, a malformed
            // datetime — to warnings: zero rows affected AND an empty
            // last_error, indistinguishable from a replay unless we look. So
            // look: if the tuple is not on disk, the write was swallowed.
            if (!self::exists($ref_type, $ref_id, $type)) {
                throw new \RuntimeException(sprintf(
                    'Ledger append silently dropped (%s/%s/%s) — no row and no error',
                    $type, $ref_type, $ref_id
                ));
            }
            return 0; // replay — the business event was already ledgered
        }
        self::flush_cache($customer_id);
        return (int) $wpdb->insert_id;
    }

    /** Does this exact business event already exist? */
    private static function exists(string $ref_type, string $ref_id, string $type): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . ' WHERE ref_type = %s AND ref_id = %s AND type = %s',
            $ref_type, $ref_id, $type
        )) > 0;
    }

    /**
     * Re-write the two order entries after a swallowed ledger write left a
     * paid order un-ledgered. Idempotent: the unique key absorbs whichever
     * half already landed.
     *
     * @return int number of entries newly written (0 = already whole)
     */
    public static function repair_order_entries(int $customer_id, string $payment_ref, int $amount, int $order_id): int
    {
        $written = 0;
        if (self::append($customer_id, 'payment', $amount, 'order', $payment_ref,
            sprintf(__('پرداخت سفارش #%d', 'arvan-reseller'), $order_id), 'repair') > 0) {
            $written++;
        }
        if (self::append($customer_id, 'purchase', $amount, 'order', $payment_ref,
            sprintf(__('خرید سرویس — سفارش #%d', 'arvan-reseller'), $order_id), 'repair') > 0) {
            $written++;
        }
        return $written;
    }

    public static function direction_of(string $type): ?string
    {
        if (in_array($type, self::CREDIT_TYPES, true)) {
            return 'credit';
        }
        if (in_array($type, self::DEBIT_TYPES, true)) {
            return 'debit';
        }
        return null;
    }

    /**
     * Pure balance derivation from entry rows — unit-tested without a DB, and
     * the reference the SQL aggregate below must agree with.
     *
     * `reserved` is computed from the reservation/release pair. No production
     * path appends those yet (checkout debits directly); the field is kept
     * because the two-phase checkout in ADR-0007 needs it and removing it
     * would silently change every wallet view's shape.
     *
     * @param array<array{direction:string,amount:int,type:string}> $entries
     * @return array{available:int,reserved:int,consumed:int,topup_total:int}
     */
    public static function derive(array $entries): array
    {
        $credit = $debit = $reserved = $released = $consumed = $topups = 0;
        foreach ($entries as $e) {
            $amount = (int) $e['amount'];
            if ($e['direction'] === 'credit') {
                $credit += $amount;
                if ($e['type'] === 'release') {
                    $released += $amount;
                }
                if ($e['type'] === 'topup') {
                    $topups += $amount;
                }
            } else {
                $debit += $amount;
                if ($e['type'] === 'reservation') {
                    $reserved += $amount;
                }
                if (in_array($e['type'], self::CONSUMING_TYPES, true)) {
                    $consumed += $amount;
                }
            }
        }
        return [
            'available'   => $credit - $debit,
            'reserved'    => max(0, $reserved - $released),
            'consumed'    => $consumed,
            'topup_total' => $topups,
        ];
    }

    /**
     * One indexed SQL aggregate, object-cached. `$include_demo = null` means
     * "count demo rows only while the site is still in demo mode" — so demo
     * top-ups stop being spendable the moment the reseller goes live.
     *
     * @return array{available:int,reserved:int,consumed:int,topup_total:int}
     */
    public static function balance(int $customer_id, ?bool $include_demo = null): array
    {
        $include_demo = self::resolve_demo($include_demo);
        $key = self::cache_key($customer_id, $include_demo);
        $hit = self::cache_get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $rows = self::aggregate([$customer_id], $include_demo);
        $out  = isset($rows[$customer_id]) ? $rows[$customer_id] : self::zero();
        self::cache_set($key, $out);
        return $out;
    }

    /**
     * Batch form: ONE GROUP BY query for many customers, so an admin list of
     * twenty does not run twenty aggregates. Ids with no ledger rows come
     * back as a zero row, never missing.
     *
     * @param int[] $customer_ids
     * @return array<int,array{available:int,reserved:int,consumed:int,topup_total:int}>
     */
    public static function balances(array $customer_ids, ?bool $include_demo = null): array
    {
        $include_demo = self::resolve_demo($include_demo);
        $ids = self::clean_ids($customer_ids);
        if (!$ids) {
            return [];
        }
        $rows = self::aggregate($ids, $include_demo);
        $out  = [];
        foreach ($ids as $id) {
            $out[$id] = isset($rows[$id]) ? $rows[$id] : self::zero();
            self::cache_set(self::cache_key($id, $include_demo), $out[$id]);
        }
        return $out;
    }

    /**
     * True start of the current non-positive period, in whole days; null when
     * the balance is positive.
     *
     * The old implementation returned the age of the newest debit, which with
     * hourly usage debits is always ~0 — so PolicyEngine::RESTRICTED (which
     * needs days > grace_days) was mathematically unreachable. Instead walk
     * the ledger backwards un-applying entries until the balance *before* an
     * entry is positive: that entry is the crossing point. Bounded to
     * WALK_PAGES × WALK_PAGE rows; past that we report the age of the oldest
     * row examined, which under-reports rather than resetting the clock.
     */
    public static function negative_since_days(int $customer_id): ?int
    {
        global $wpdb;

        $include_demo = self::resolve_demo(null);
        $balance = self::balance($customer_id, $include_demo);
        if ($balance['available'] > 0) {
            return null;
        }

        $demo_clause = $include_demo ? '' : ' AND is_demo = 0';
        $running     = (int) $balance['available'];
        $cursor      = PHP_INT_MAX;
        $oldest      = null;

        for ($page = 0; $page < self::WALK_PAGES; $page++) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT id, direction, amount, created_at FROM ' . self::table() .
                ' WHERE customer_id = %d AND id < %d' . $demo_clause . ' ORDER BY id DESC LIMIT %d',
                $customer_id, $cursor, self::WALK_PAGE
            ), ARRAY_A);
            if (!$rows) {
                break;
            }
            $step = self::walk_back($rows, $running);
            if ($step['crossed_at'] !== null) {
                return self::days_since($step['crossed_at']);
            }
            $running = $step['running'];
            $oldest  = $step['oldest_at'];
            $cursor  = $step['oldest_id'];
            if (count($rows) < self::WALK_PAGE) {
                break; // reached the start of history without ever being positive
            }
        }

        return $oldest === null ? 0 : self::days_since($oldest);
    }

    /**
     * Pure one-page step of the backwards walk — public so it can be unit
     * tested without a database.
     *
     * @param array<array{id:mixed,direction:string,amount:mixed,created_at:string}> $rows newest-first
     * @param int $running balance as of the newest row in $rows
     * @return array{crossed_at:?string,running:int,oldest_at:?string,oldest_id:int}
     */
    public static function walk_back(array $rows, int $running): array
    {
        $oldest_at = null;
        $oldest_id = PHP_INT_MAX;
        foreach ($rows as $row) {
            $amount = (int) $row['amount'];
            // The balance as it stood *before* this entry existed — undo it:
            // a credit had been added, so subtract it; a debit had been
            // subtracted, so add it back. (Getting this pair the wrong way
            // round makes the newest top-up look like the crossing point and
            // restricts every customer who has ever been paid up.)
            $before = $row['direction'] === 'credit' ? $running - $amount : $running + $amount;
            $oldest_at = (string) $row['created_at'];
            $oldest_id = (int) $row['id'];
            if ($before > 0) {
                // This entry is what pushed the balance non-positive.
                return ['crossed_at' => $oldest_at, 'running' => $before, 'oldest_at' => $oldest_at, 'oldest_id' => $oldest_id];
            }
            $running = $before;
        }
        return ['crossed_at' => null, 'running' => $running, 'oldest_at' => $oldest_at, 'oldest_id' => $oldest_id];
    }

    /** Paginated entries for one customer (owner-scoped by caller). */
    public static function entries(int $customer_id, int $page = 1, int $per_page = 20, ?bool $include_demo = null): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $per_page);
        $where  = self::resolve_demo($include_demo) ? '' : ' AND is_demo = 0';
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, direction, amount, currency, ref_type, ref_id, description, created_at
             FROM ' . self::table() . ' WHERE customer_id = %d' . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $customer_id, $per_page, $offset
        ), ARRAY_A) ?: [];
    }

    /**
     * Admin reconciliation per credential (spec §7): how much provisioned
     * spend each upstream Arvan account backs, and what was billed on top.
     * Joins services→usage since the ledger itself is customer-dimensioned,
     * not credential-dimensioned. Demo rows are excluded once the site is
     * live — otherwise a demo service invents a phantom credential row in the
     * one report a reseller checks against their real Arvan invoice.
     */
    public static function reconciliation_by_credential(?bool $include_demo = null): array
    {
        global $wpdb;
        $services = $wpdb->prefix . 'arvrs_services';
        $usage    = $wpdb->prefix . 'arvrs_usage_records';
        $where    = self::resolve_demo($include_demo) ? '' : ' WHERE s.is_demo = 0';
        return $wpdb->get_results(
            "SELECT s.credential_id,
                    COUNT(DISTINCT s.id) AS services,
                    COALESCE(SUM(u.cost),0) AS usage_cost,
                    COALESCE(SUM(u.price),0) AS usage_revenue,
                    COALESCE(SUM(u.price - u.cost),0) AS usage_margin
             FROM $services s
             LEFT JOIN $usage u ON u.service_id = s.id AND u.is_demo = s.is_demo" . $where . '
             GROUP BY s.credential_id ORDER BY usage_cost DESC',
            ARRAY_A
        ) ?: [];
    }

    /**
     * Admin reconciliation: totals per customer. $include_demo=false (real
     * operation) excludes demo-stamped rows so demo money never pollutes live
     * figures (spec §11).
     */
    public static function reconciliation(int $limit = 100, bool $include_demo = true): array
    {
        global $wpdb;
        $where = $include_demo ? '' : ' WHERE is_demo = 0';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT customer_id,
                    SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END) AS credits,
                    SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END) AS debits,
                    SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) AS available
             FROM " . self::table() . $where . '
             GROUP BY customer_id ORDER BY available ASC LIMIT %d',
            $limit
        ), ARRAY_A) ?: [];
    }

    /** Total outstanding customer credit; excludes demo rows in real mode. */
    public static function total_credit(bool $include_demo = true): int
    {
        global $wpdb;
        $where = $include_demo ? '' : ' WHERE is_demo = 0';
        return (int) $wpdb->get_var(
            "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM " . self::table() . $where
        );
    }

    /**
     * Drop cached balances. `$customer_id = 0` invalidates everyone by
     * bumping a generation counter that is part of every cache key.
     * ponytail: the superseded entries are left to expire on their TTL rather
     * than enumerated — object caches have no key iteration.
     */
    public static function flush_cache(int $customer_id = 0): void
    {
        if ($customer_id > 0) {
            self::cache_delete(self::cache_key($customer_id, true));
            self::cache_delete(self::cache_key($customer_id, false));
            return;
        }
        self::cache_set('gen', self::generation() + 1);
    }

    // ---------------------------------------------------------------- internals

    /**
     * The one aggregate. Served by KEY customer_id_id on (customer_id, id).
     *
     * @param int[] $ids already sanitised to positive ints
     * @return array<int,array{available:int,reserved:int,consumed:int,topup_total:int}>
     */
    private static function aggregate(array $ids, bool $include_demo): array
    {
        global $wpdb;
        if (!$ids) {
            return [];
        }
        $place    = implode(',', array_fill(0, count($ids), '%d'));
        $consumes = "'" . implode("','", self::CONSUMING_TYPES) . "'"; // class constant, not input
        $sql = "SELECT customer_id,
                       COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) AS available,
                       COALESCE(SUM(CASE WHEN type='reservation' THEN amount WHEN type='release' THEN -amount ELSE 0 END),0) AS reserved,
                       COALESCE(SUM(CASE WHEN type IN ($consumes) THEN amount ELSE 0 END),0) AS consumed,
                       COALESCE(SUM(CASE WHEN type='topup' THEN amount ELSE 0 END),0) AS topup_total
                FROM " . self::table() . '
                WHERE customer_id IN (' . $place . ')' . ($include_demo ? '' : ' AND is_demo = 0') . '
                GROUP BY customer_id';

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$ids), ARRAY_A) ?: [];
        $out  = [];
        foreach ($rows as $row) {
            $out[(int) $row['customer_id']] = [
                'available'   => (int) $row['available'],
                'reserved'    => max(0, (int) $row['reserved']),
                'consumed'    => (int) $row['consumed'],
                'topup_total' => (int) $row['topup_total'],
            ];
        }
        return $out;
    }

    /** @return array{available:int,reserved:int,consumed:int,topup_total:int} */
    private static function zero(): array
    {
        return ['available' => 0, 'reserved' => 0, 'consumed' => 0, 'topup_total' => 0];
    }

    /** @param int[] $ids @return int[] */
    private static function clean_ids(array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }
        return array_values($clean);
    }

    /** null => "include demo rows only while the site is still in demo mode". */
    private static function resolve_demo(?bool $include_demo): bool
    {
        return $include_demo === null ? self::demo_mode() : $include_demo;
    }

    private static function demo_mode(): bool
    {
        return class_exists('ArvanReseller\\Plugin') && \ArvanReseller\Plugin::demo_mode();
    }

    private static function days_since(string $utc_datetime): int
    {
        $ts = strtotime($utc_datetime . ' UTC');
        if (!$ts) {
            return 0;
        }
        return max(0, (int) floor((time() - $ts) / DAY_IN_SECONDS));
    }

    private static function cache_key(int $customer_id, bool $include_demo): string
    {
        return 'bal:' . self::generation() . ':' . $customer_id . ':' . ($include_demo ? '1' : '0');
    }

    private static function generation(): int
    {
        $gen = self::cache_get('gen');
        return $gen === false || $gen === null ? 1 : (int) $gen;
    }

    /** @return mixed false on miss (or when no object cache is loaded) */
    private static function cache_get(string $key)
    {
        return function_exists('wp_cache_get') ? wp_cache_get($key, self::CACHE_GROUP) : false;
    }

    /** @param mixed $value */
    private static function cache_set(string $key, $value): void
    {
        if (function_exists('wp_cache_set')) {
            wp_cache_set($key, $value, self::CACHE_GROUP, self::CACHE_TTL);
        }
    }

    private static function cache_delete(string $key): void
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, self::CACHE_GROUP);
        }
    }
}
