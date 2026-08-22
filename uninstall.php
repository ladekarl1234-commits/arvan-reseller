<?php
/**
 * Uninstall handler. Financial/customer data is destroyed ONLY when the
 * administrator explicitly disabled data retention in settings (HC-11).
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

$arvrs_settings = get_option('arvrs_settings', []);
$arvrs_retain   = !is_array($arvrs_settings) || !array_key_exists('data_retention_on_uninstall', $arvrs_settings)
    ? true
    : (bool) $arvrs_settings['data_retention_on_uninstall'];

if ($arvrs_retain) {
    return; // keep everything — reinstalling restores the storefront intact
}

global $wpdb;
foreach (['credentials', 'orders', 'order_events', 'services', 'ledger', 'usage_records', 'jobs', 'audit_log', 'notifications', 'customer_rules', 'base_costs', 'topups'] as $arvrs_table) {
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'arvrs_' . $arvrs_table); // phpcs:ignore WordPress.DB
}
foreach (['arvrs_settings', 'arvrs_license', 'arvrs_schema_version', 'arvrs_demo_resources', 'arvrs_demo_failed_once', 'arvrs_last_usage_sync', 'arvrs_auth_prefix'] as $arvrs_option) {
    delete_option($arvrs_option);
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'arvrs\\_topup\\_%' OR option_name LIKE '\\_transient\\_arvrs\\_%' OR option_name LIKE '\\_transient\\_timeout\\_arvrs\\_%'"); // phpcs:ignore WordPress.DB
delete_metadata('user', 0, 'arvrs_policy_stage', '', true); // all users
if (get_role('arvrs_customer')) {
    remove_role('arvrs_customer');
}
