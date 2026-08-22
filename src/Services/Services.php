<?php
namespace ArvanReseller\Services;

use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Local service records: the permanent mapping (customer, order, credential,
 * product, remote_id) required for usage attribution and isolation (spec §
 * usage accounting). UNIQUE KEY on order_id is the provisioning idempotency
 * backstop — a second insert for the same order is impossible.
 *
 * A service also carries its own billing clock (`renews_at`, `term_days`,
 * `renewal_price`): the reseller sells a term, not a one-off, and
 * `Billing\Renewals` charges that clock. Every status write goes through this
 * class so the whitelist and the cancellation stamp cannot be bypassed.
 */
final class Services
{
    /** The only statuses a row may hold. */
    public const STATUSES = ['active', 'at_risk', 'suspended', 'cancelled'];

    /**
     * Statuses that are still consuming an upstream resource, and therefore
     * still cost the reseller money: they keep syncing usage and keep
     * renewing. Suspension is a collection action, not a termination.
     */
    public const LIVE_STATUSES = ['active', 'at_risk', 'suspended'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_services';
    }

    /**
     * Insert the service row for a settled order, complete with its first
     * renewal date.
     *
     * @return int service id, or 0 when the row could NOT be written. 0 is an
     *             unambiguous failure signal: a duplicate (idempotent retry)
     *             returns the existing id, so the only way to see 0 is a
     *             genuine write failure. The caller MUST NOT mark the order
     *             active on 0 (EX-061).
     */
    public static function create_for_order(array $order, string $remote_id, array $connection, ?int $credential_id): int
    {
        global $wpdb;

        $term   = max(1, (int) Options::get('service_term_days', 30));
        $renews = gmdate('Y-m-d H:i:s', time() + $term * DAY_IN_SECONDS);
        $price  = max(0, (int) ($order['amount'] ?? 0));

        // A demo service has no upstream credential, and coercing NULL to 0
        // invents a phantom credential row in the reconciliation report
        // (EX-099). The literal is an int cast of an int-or-null — never
        // request data — so it is safe outside the placeholder list.
        $credential_sql = $credential_id === null ? 'NULL' : (string) (int) $credential_id;

        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (order_id, customer_id, credential_id, product, plan_id, remote_id, status, config, connection,
               renews_at, term_days, renewal_price, is_demo, created_at, updated_at)
              VALUES (%d, %d, ' . $credential_sql . ', %s, %s, %s, %s, %s, %s, %s, %d, %d, %d, %s, %s)',
            (int) $order['id'], (int) $order['customer_id'],
            $order['product'], $order['plan_id'], $remote_id, 'active',
            (string) $order['config'], wp_json_encode($connection),
            $renews, $term, $price,
            (int) $order['is_demo'], Helpers::now(), Helpers::now()
        ));
        if ((int) $wpdb->rows_affected > 0) {
            return (int) $wpdb->insert_id;
        }
        // Zero rows affected is either a benign duplicate or a swallowed write
        // (INSERT IGNORE downgrades truncation and out-of-range to warnings).
        // Look on disk: no row means the insert failed for real.
        // rows_affected, not insert_id, is the portable duplicate signal.
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE order_id = %d', (int) $order['id']
        ));
    }

    public static function get(int $service_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d', $service_id
        ), ARRAY_A);
        return $row ?: null;
    }

    public static function by_order(int $order_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE order_id = %d', $order_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Reconcile a remote resource back to its local row (provider natural key). */
    public static function by_remote(string $product, string $remote_id): ?array
    {
        global $wpdb;
        if ($remote_id === '') {
            return null; // an empty remote_id matches every un-provisioned row
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE product = %s AND remote_id = %s ORDER BY id DESC LIMIT 1',
            $product, $remote_id
        ), ARRAY_A);
        return $row ?: null;
    }

    /** Owner check in one place (HC-5): null unless the service belongs to the customer. */
    public static function get_owned(int $service_id, int $customer_id): ?array
    {
        $service = self::get($service_id);
        return ($service && (int) $service['customer_id'] === $customer_id) ? $service : null;
    }

    /**
     * $status and $search are applied in SQL, not after the fact: filtering a
     * fetched page in PHP (the old behaviour) checked only the 20 rows already
     * on the page, so a status with matches outside that window reported a
     * false "nothing found" and pagination silently broke alongside it.
     *
     * @param string $search matches `remote_id`, the numeric id, or a
     *                        customer's email (KEY remote_id already exists)
     */
    public static function list(int $customer_id = 0, int $page = 1, int $per_page = 20, string $status = '', string $search = ''): array
    {
        global $wpdb;
        [$where, $params] = self::list_filters($customer_id, $status, $search);
        if ($where === null) {
            return []; // search term that cannot match any row (unknown email)
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

    /** @return array{0:?string[],1:array} [where fragments (null = impossible), params] */
    private static function list_filters(int $customer_id, string $status, string $search): array
    {
        $where  = [];
        $params = [];
        if ($customer_id > 0) {
            $where[]  = 'customer_id = %d';
            $params[] = $customer_id;
        }
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
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
                $where[]  = '(remote_id = %s OR id = %d)';
                $params[] = $search;
                $params[] = (int) $search; // 0 for a non-numeric term — never matches an id
            }
        }
        return [$where, $params];
    }

    /**
     * One cursor page of services to meter. Cursor-paged rather than
     * "everything at once" so a sync run has a resume point (EX-030), and
     * suspended services are INCLUDED: the plugin never powers a remote
     * resource off, so a suspended service is still running and still billing
     * the reseller upstream (EX-123).
     *
     * @param int $limit    rows per page
     * @param int $after_id exclusive id cursor; 0 starts at the beginning
     */
    public static function active_for_sync(int $limit = 200, int $after_id = 0): array
    {
        global $wpdb;
        $live = "'" . implode("','", self::LIVE_STATUSES) . "'"; // class constant, not input
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, customer_id, product, plan_id, remote_id, is_demo, last_synced_at FROM ' . self::table() .
            ' WHERE status IN (' . $live . ') AND id > %d ORDER BY id ASC LIMIT %d',
            max(0, $after_id), max(1, $limit)
        ), ARRAY_A) ?: [];
    }

    /**
     * Services whose billing clock has come due. Suspended rows renew too —
     * the debt is the whole point of the hold; only `cancelled` stops.
     *
     * Cursor-paged on id (like `active_for_sync()`) so a batch that runs out
     * of budget has a resume point instead of a fixed cap on the whole
     * population. Demo services are excluded once the site has gone live —
     * seeded demo inventory must never manufacture real charges.
     *
     * @param int $limit    rows per page
     * @param int $after_id exclusive id cursor; 0 starts at the beginning
     */
    public static function due_for_renewal(int $limit = 50, int $after_id = 0): array
    {
        global $wpdb;
        $live  = "'" . implode("','", self::LIVE_STATUSES) . "'"; // class constant, not input
        $demo  = self::exclude_demo_when_live() ? ' AND is_demo = 0' : '';
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() .
            ' WHERE status IN (' . $live . ') AND renews_at IS NOT NULL AND renews_at <= %s AND id > %d' . $demo . '
              ORDER BY id ASC LIMIT %d',
            Helpers::now(), max(0, $after_id), max(1, $limit)
        ), ARRAY_A) ?: [];
    }

    /**
     * True once the site is out of demo mode. Guarded rather than a hard
     * dependency on Plugin (Ledger::demo_mode() uses the same pattern), so a
     * unit test that only loads Services never has to bootstrap Plugin.
     */
    private static function exclude_demo_when_live(): bool
    {
        return class_exists('ArvanReseller\\Plugin') && !\ArvanReseller\Plugin::demo_mode();
    }

    /** Services renewing inside the reminder window, not yet cancelled. */
    public static function renewing_between(string $from_utc, string $to_utc, int $limit = 200): array
    {
        global $wpdb;
        $live = "'" . implode("','", self::LIVE_STATUSES) . "'"; // class constant, not input
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() .
            ' WHERE status IN (' . $live . ') AND renews_at IS NOT NULL AND renews_at >= %s AND renews_at < %s
              ORDER BY renews_at ASC LIMIT %d',
            $from_utc, $to_utc, max(1, $limit)
        ), ARRAY_A) ?: [];
    }

    /** Set or reset the billing clock (admin edit, or migration back-stamp). */
    public static function set_renewal(int $service_id, string $renews_at, int $term_days, int $price): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET renews_at = %s, term_days = %d, renewal_price = %d, updated_at = %s
             WHERE id = %d',
            $renews_at, max(1, $term_days), max(0, $price), Helpers::now(), $service_id
        ));
    }

    /**
     * Advance the billing clock by one term, atomically.
     *
     * The `renews_at = <old>` guard is the whole concurrency story: two cron
     * runners that both decide the same service is due will both attempt this
     * UPDATE and exactly one will match a row. The loser sees false and must
     * NOT charge. A read-then-write here would be a double charge.
     *
     * @return bool true when THIS caller moved the clock
     */
    public static function advance_renewal(int $service_id, string $expected_renews_at, string $next_renews_at): bool
    {
        global $wpdb;
        return 1 === (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET renews_at = %s, renewal_count = renewal_count + 1, updated_at = %s
             WHERE id = %d AND renews_at = %s',
            $next_renews_at, Helpers::now(), $service_id, $expected_renews_at
        ));
    }

    /** Per-service usage watermark, so a missed cron tick is caught up later. */
    public static function mark_synced(int $service_id, string $when = ''): void
    {
        global $wpdb;
        $wpdb->update(
            self::table(),
            ['last_synced_at' => $when !== '' ? $when : Helpers::now(), 'updated_at' => Helpers::now()],
            ['id' => $service_id]
        );
    }

    /**
     * Batch form of the watermark stamp: one UPDATE per sync page instead of
     * one per service.
     *
     * @param int[] $service_ids
     */
    public static function mark_synced_many(array $service_ids, string $when = ''): void
    {
        global $wpdb;
        $ids = [];
        foreach ($service_ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if (!$ids) {
            return;
        }
        $ids   = array_values($ids);
        $place = implode(',', array_fill(0, count($ids), '%d'));
        $stamp = $when !== '' ? $when : Helpers::now();
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET last_synced_at = %s WHERE id IN (' . $place . ')',
            ...array_merge([$stamp], $ids)
        ));
    }

    /** Fill in address/credentials once the async poll finds the resource ready. */
    public static function update_connection(int $service_id, array $connection, string $status = ''): void
    {
        global $wpdb;
        $data = ['connection' => wp_json_encode($connection), 'updated_at' => Helpers::now()];
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $data['status'] = $status;
        }
        $wpdb->update(self::table(), $data, ['id' => $service_id]);
    }

    /**
     * Local termination: stop the billing clock and mark the row cancelled.
     * Deleting the remote resource is a separate, explicit admin action — the
     * plugin never destroys upstream infrastructure from local state.
     */
    public static function terminate(int $service_id): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'cancelled', cancelled_at = %s, renews_at = NULL, updated_at = %s
             WHERE id = %d AND status <> 'cancelled'",
            Helpers::now(), Helpers::now(), $service_id
        ));
    }

    /** Stop future renewal charges but leave the service running to term end. */
    public static function cancel_renewal(int $service_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET renews_at = NULL, cancelled_at = %s, updated_at = %s
             WHERE id = %d AND renews_at IS NOT NULL',
            Helpers::now(), Helpers::now(), $service_id
        ));
    }

    public static function set_status(int $service_id, string $status): void
    {
        global $wpdb;
        if (!in_array($status, self::STATUSES, true)) {
            return;
        }
        $data = ['status' => $status, 'updated_at' => Helpers::now()];
        if ($status === 'cancelled') {
            $data['cancelled_at'] = Helpers::now();
        }
        $wpdb->update(self::table(), $data, ['id' => $service_id]);
    }

    /**
     * Bulk status move for one customer, whitelisted on both ends. The policy
     * engine used to issue raw UPDATEs against this table from Usage\UsageSync
     * (EX-128), which let the two ways of changing a service status drift.
     *
     * @param string[] $from statuses eligible to move
     * @return int rows moved
     */
    public static function bulk_set_status(int $customer_id, array $from, string $to): int
    {
        global $wpdb;
        if (!in_array($to, self::STATUSES, true)) {
            return 0;
        }
        $from = array_values(array_intersect($from, self::STATUSES));
        if (!$from) {
            return 0;
        }
        $place = implode(',', array_fill(0, count($from), '%s'));
        $args  = array_merge([$to, Helpers::now()], $from, [$customer_id]);
        return (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . ' SET status = %s, updated_at = %s
             WHERE status IN (' . $place . ') AND customer_id = %d',
            ...$args
        ));
    }

    public static function count_by_status(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results(
            'SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', ARRAY_A
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }
}
