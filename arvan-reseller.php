<?php
/**
 * Plugin Name:       Arvan Reseller Platform
 * Plugin URI:        https://github.com/successtrade/arvan-reseller
 * Description:       White-label ArvanCloud reseller storefront: sell Cloud Server, CDN and Object Storage from your own WordPress site with automatic provisioning, wallet ledger, usage accounting and credit policies.
 * Version:           1.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Arvan Reseller Team
 * License:           GPL-2.0-or-later
 * Text Domain:       arvan-reseller
 * Domain Path:       /languages
 */

defined('ABSPATH') || exit;

define('ARVRS_VERSION', '1.0.0');
define('ARVRS_SCHEMA_VERSION', 3);
define('ARVRS_FILE', __FILE__);
define('ARVRS_DIR', plugin_dir_path(__FILE__));
define('ARVRS_URL', plugin_dir_url(__FILE__));

// PSR-4-ish autoloader for the ArvanReseller\ namespace. No Composer at runtime.
spl_autoload_register(static function ($class) {
    if (strpos($class, 'ArvanReseller\\') !== 0) {
        return;
    }
    $rel  = substr($class, strlen('ArvanReseller\\'));
    $file = ARVRS_DIR . 'src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// Multi-class DTO file cannot be autoloaded per-class; load it eagerly.
require_once ARVRS_DIR . 'src/Arvan/DTO.php';

// The custom cron interval must be known in the ACTIVATION request too, where
// plugins_loaded fired before this file was included and Plugin::boot never
// runs — otherwise wp_schedule_event('arvrs_minutely') fails validation and
// the job runner is never scheduled. Registering at file scope covers both.
add_filter('cron_schedules', static function (array $schedules) { // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
    $schedules['arvrs_minutely'] = ['interval' => 60, 'display' => 'Every minute (Arvan Reseller jobs)'];
    return $schedules;
});

register_activation_hook(__FILE__, ['ArvanReseller\\Install\\Activator', 'activate']);
register_deactivation_hook(__FILE__, ['ArvanReseller\\Install\\Activator', 'deactivate']);

add_action('plugins_loaded', static function () {
    load_plugin_textdomain('arvan-reseller', false, dirname(plugin_basename(__FILE__)) . '/languages');
    ArvanReseller\Plugin::boot();
});
