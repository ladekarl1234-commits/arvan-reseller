<?php
namespace ArvanReseller\Services;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Local service records: the permanent mapping (customer, order, credential,
 * product, remote_id) required for usage attribution and isolation (spec §
 * usage accounting). UNIQUE KEY on order_id is the provisioning idempotency
 * backstop — a second insert for the same order is impossible.
 */
final class Services
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_services';
    }

    public static function create_for_order(array $order, string $remote_id, array $connection, ?int $credential_id): int
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (order_id, customer_id, credential_id, product, plan_id, remote_id, status, config, connection, is_demo, created_at, updated_at)
              VALUES (%d, %d, %d, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
            (int) $order['id'], (int) $order['customer_id'], (int) $credential_id,
            $order['product'], $order['plan_id'], $remote_id, 'active',
            (string) $order['config'], wp_json_encode($connection),
            (int) $order['is_demo'], Helpers::now(), Helpers::now()
        ));
        if ($wpdb->insert_id) {
            return (int) $wpdb->insert_id;
        }
        // Row already existed (idempotent retry) — return the existing ID.
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

    /** Owner check in one place (HC-5): null unless the service belongs to the customer. */
    public static function get_owned(int $service_id, int $customer_id): ?array
    {
        $service = self::get($service_id);
        return ($service && (int) $service['customer_id'] === $customer_id) ? $service : null;
    }

    public static function list(int $customer_id = 0, int $page = 1, int $per_page = 20): array
    {
        global $wpdb;
        if ($customer_id > 0) {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT %d OFFSET %d',
                $customer_id, $per_page, max(0, ($page - 1) * $per_page)
            ), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
            $per_page, max(0, ($page - 1) * $per_page)
        ), ARRAY_A) ?: [];
    }

    public static function active_for_sync(): array
    {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT id, customer_id, product, plan_id, remote_id, is_demo FROM ' . self::table() .
            " WHERE status IN ('active','at_risk') ORDER BY id ASC",
            ARRAY_A
        ) ?: [];
    }

    public static function set_status(int $service_id, string $status): void
    {
        global $wpdb;
        $allowed = ['active', 'at_risk', 'suspended', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return;
        }
        $wpdb->update(self::table(), ['status' => $status, 'updated_at' => Helpers::now()], ['id' => $service_id]);
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
