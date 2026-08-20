<?php
namespace ArvanReseller\Wallet;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Append-only financial ledger (spec §7, ADR-0007). Balances are DERIVED —
 * there is no mutable balance column anywhere. Idempotency comes from the
 * UNIQUE KEY (ref_type, ref_id, type): re-inserting the same business event
 * is a silent no-op, which is exactly the replay-safety we want (HC-7).
 */
final class Ledger
{
    public const CREDIT_TYPES = ['topup', 'payment', 'refund', 'promo_credit', 'release'];
    public const DEBIT_TYPES  = ['purchase', 'usage_debit', 'service_charge', 'adjustment', 'reservation'];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_ledger';
    }

    /**
     * Append one entry. Returns the row ID, or 0 when the (ref_type, ref_id,
     * type) tuple already exists — i.e. a replay.
     */
    public static function append(
        int $customer_id,
        string $type,
        int $amount,
        string $ref_type,
        string $ref_id,
        string $description = '',
        string $actor = 'system'
    ): int {
        global $wpdb;

        $direction = self::direction_of($type);
        if ($direction === null || $amount < 0) {
            throw new \InvalidArgumentException('Invalid ledger entry: ' . $type);
        }

        // INSERT IGNORE + unique key = atomic idempotency without SELECT-then-INSERT races.
        $sql = $wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (customer_id, type, direction, amount, currency, ref_type, ref_id, description, actor, created_at)
              VALUES (%d, %s, %s, %d, %s, %s, %s, %s, %s, %s)',
            $customer_id, $type, $direction, $amount, 'IRT',
            $ref_type, $ref_id, $description, $actor, Helpers::now()
        );
        $wpdb->query($sql);
        // rows_affected (not insert_id) is the portable duplicate signal:
        // MySQL leaves insert_id stale-or-zero after an ignored insert and the
        // SQLite integration layer leaves it stale — affected rows is 0 on
        // both when the unique key already existed.
        if ((int) $wpdb->rows_affected === 0) {
            return 0; // replay — the business event was already ledgered
        }
        return (int) $wpdb->insert_id;
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
     * Pure balance derivation from entry rows — unit-tested without a DB.
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
                if (in_array($e['type'], ['usage_debit', 'service_charge', 'purchase'], true)) {
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

    /** @return array{available:int,reserved:int,consumed:int,topup_total:int} */
    public static function balance(int $customer_id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT direction, amount, type FROM ' . self::table() . ' WHERE customer_id = %d',
            $customer_id
        ), ARRAY_A);
        return self::derive(array_map(static function ($r) {
            return ['direction' => $r['direction'], 'amount' => (int) $r['amount'], 'type' => $r['type']];
        }, $rows ?: []));
    }

    /** Oldest datetime the balance has been continuously <= 0, in days; null when positive. */
    public static function negative_since_days(int $customer_id): ?int
    {
        $bal = self::balance($customer_id);
        if ($bal['available'] > 0) {
            return null;
        }
        global $wpdb;
        // Approximation good enough for policy staging: days since the last
        // entry that brought the balance to/below zero — the newest debit.
        $last = $wpdb->get_var($wpdb->prepare(
            'SELECT MAX(created_at) FROM ' . self::table() . " WHERE customer_id = %d AND direction = 'debit'",
            $customer_id
        ));
        if (!$last) {
            return 0;
        }
        return (int) floor((time() - strtotime($last . ' UTC')) / DAY_IN_SECONDS);
    }

    /** Paginated entries for one customer (owner-scoped by caller). */
    public static function entries(int $customer_id, int $page = 1, int $per_page = 20): array
    {
        global $wpdb;
        $offset = max(0, ($page - 1) * $per_page);
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, direction, amount, currency, ref_type, ref_id, description, created_at
             FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
            $customer_id, $per_page, $offset
        ), ARRAY_A) ?: [];
    }

    /** Admin reconciliation: totals per customer. */
    public static function reconciliation(int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT customer_id,
                    SUM(CASE WHEN direction='credit' THEN amount ELSE 0 END) AS credits,
                    SUM(CASE WHEN direction='debit'  THEN amount ELSE 0 END) AS debits,
                    SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END) AS available
             FROM " . self::table() . '
             GROUP BY customer_id ORDER BY available ASC LIMIT %d',
            $limit
        ), ARRAY_A) ?: [];
    }
}
