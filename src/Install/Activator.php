<?php
namespace ArvanReseller\Install;

use ArvanReseller\Identity\Customers;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

final class Activator
{
    public static function activate(): void
    {
        Schema::migrate();
        Customers::ensure_role();

        if (!wp_next_scheduled('arvrs_run_jobs')) {
            wp_schedule_event(time() + 60, 'arvrs_minutely', 'arvrs_run_jobs');
        }
        if (!wp_next_scheduled('arvrs_usage_sync')) {
            wp_schedule_event(time() + 300, 'hourly', 'arvrs_usage_sync');
        }

        // First activation → onboarding wizard (spec §5.1).
        if (!Options::get('onboarded', false)) {
            Options::set('activation_redirect', true);
        }
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook('arvrs_run_jobs');
        wp_clear_scheduled_hook('arvrs_usage_sync');
    }
}
