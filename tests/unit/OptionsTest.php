<?php
/**
 * `Support\Options` whitelist.
 *
 * Six keys — `sync_batch`, `usage_markup_percent`, `service_term_days`,
 * `renewal_reminder_days`, `data_retention_days`, `customer_registration` —
 * were readable with a fallback everywhere they were used, which reads like a
 * configurable setting, but none was in `DEFAULTS`, so `Options::set()`
 * silently dropped every write with no return value to catch it. Retention
 * was additionally split across two different stores entirely.
 */

defined('ABSPATH') || exit;

use ArvanReseller\Support\Options;

final class OptionsTest extends Arvrs_DbTestCase
{
    private const PREVIOUSLY_UNSETTABLE = [
        'sync_batch'            => 300,
        'usage_markup_percent'  => 15.5,
        'service_term_days'     => 90,
        'renewal_reminder_days' => 10,
        'data_retention_days'   => 45,
        'customer_registration' => false,
    ];

    public function test_set_returns_true_for_a_whitelisted_key(): void
    {
        $this->assertTrue(Options::set('brand_name', 'x'));
    }

    public function test_set_returns_false_and_drops_an_unknown_key(): void
    {
        $this->assertFalse(Options::set('not_a_real_setting', 'x'));
        $this->assertNull(Options::get('not_a_real_setting'));
    }

    /**
     * Each of these used to round-trip a value that never actually stuck —
     * `Options::get()` kept answering with its hardcoded fallback forever
     * because `Options::set()` silently ignored the write.
     */
    public function test_the_six_previously_unsettable_keys_now_round_trip(): void
    {
        foreach (self::PREVIOUSLY_UNSETTABLE as $key => $value) {
            $this->assertTrue(Options::set($key, $value), $key . ' must be a real, settable option');
            $this->assertSame($value, Options::get($key), $key . ' must read back what was just written');
        }
    }

    /**
     * The one-shot «پاک‌سازی» button used to write `arvrs_retention_days`
     * while the nightly `prune` job read `Options::get('data_retention_days')`
     * — a different key in a different store — so setting 30 days on the
     * button changed only what that click did; the nightly job kept pruning
     * at 90 forever. Both now read the one key.
     */
    public function test_the_admin_retention_button_writes_the_key_the_nightly_job_reads(): void
    {
        $admin = $this->customer(910);
        arvrs_test_grant($admin, ['manage_options']);
        wp_set_current_user($admin);
        $_POST = ['retention_days' => '45', 'arvrs_nonce' => wp_create_nonce('arvrs_prune_now')];

        try {
            \ArvanReseller\Admin\Actions::prune_now();
        } catch (Arvrs_Test_Redirect $redirect) {
            $this->assertNotEmpty($redirect->url);
        }

        $this->assertSame(45, (int) Options::get('data_retention_days'), 'the button must write the key the job reads');
        $this->assertFalse(get_option('arvrs_retention_days', false), 'the old duplicate key must not be written any more');
    }
}
