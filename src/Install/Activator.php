<?php
namespace ArvanReseller\Install;

use ArvanReseller\Identity\Customers;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

final class Activator
{
    /**
     * Cron hook => interval. Kept as one list so activation and deactivation
     * cannot drift: adding a hook to the plugin without clearing it on
     * deactivate leaves an orphan event firing against a dead callback.
     */
    private const SCHEDULE = [
        'arvrs_run_jobs'   => 'arvrs_minutely',
        'arvrs_usage_sync' => 'hourly',
        'arvrs_daily'      => 'daily',
    ];

    /** Stagger the first run so activation does not fire three jobs at once. */
    private const FIRST_RUN_DELAY = [
        'arvrs_run_jobs'   => 60,
        'arvrs_usage_sync' => 300,
        'arvrs_daily'      => 900,
    ];

    public static function activate(): void
    {
        Schema::migrate();
        Customers::ensure_role();

        foreach (self::SCHEDULE as $hook => $interval) {
            if (!wp_next_scheduled($hook)) {
                wp_schedule_event(time() + self::FIRST_RUN_DELAY[$hook], $interval, $hook);
            }
        }

        // First activation → onboarding wizard (spec §5.1).
        if (!Options::get('onboarded', false)) {
            Options::set('activation_redirect', true);
        }
    }

    public static function deactivate(): void
    {
        foreach (array_keys(self::SCHEDULE) as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Hooks this plugin schedules — so the health page can show what should be
     * running, and so a test can assert deactivation left nothing behind.
     *
     * @return string[]
     */
    public static function cron_hooks(): array
    {
        return array_keys(self::SCHEDULE);
    }
}
