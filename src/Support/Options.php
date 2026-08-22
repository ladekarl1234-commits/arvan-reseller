<?php
namespace ArvanReseller\Support;

defined('ABSPATH') || exit;

/**
 * Single wp_options entry (`arvrs_settings`) with a whitelist of known keys —
 * mass-assignment from request bodies is structurally impossible (SEC-3).
 */
final class Options
{
    private const OPTION = 'arvrs_settings';

    /**
     * The one brand colour. It used to be declared in six places with two
     * different teals — and the one used as the actual runtime default,
     * `#14bfb4`, measured 2.30:1 against white, well under the 4.5:1 AA floor
     * `Brand::accessible()` exists to enforce on every value an admin is
     * allowed to save. `#0c6960` (6.55:1) is the single value now; every
     * other declaration (`Brand::FALLBACK`, `Front\Assets`, the wizard) reads
     * this constant instead of repeating the hex. Everything that needs a
     * fallback — settings save, wizard, enqueued CSS variables, admin accents
     * — reads this constant (or `Options::get('brand_color')`, which falls
     * back to it).
     */
    public const BRAND_COLOR = '#0c6960';

    /** Known settings and their defaults. Unknown keys are dropped on write. */
    public const DEFAULTS = [
        'onboarded'            => false,
        'activation_redirect'  => false,
        'demo_mode'            => true,
        'wizard_step'          => 0,
        'enabled_products'     => ['cloud_server', 'cdn', 'object_storage'],
        // Branding
        'brand_name'           => '',
        'brand_logo_id'        => 0,
        'brand_description'    => '',
        'brand_about'          => '',
        'support_email'        => '',
        'support_phone'        => '',
        'brand_color'          => self::BRAND_COLOR,
        // Pricing
        'global_markup'        => 20.0,
        'product_markup'       => [],       // product => percent
        'fixed_adjustment'     => 0,
        // Policy thresholds (IRT)
        'policy_warning'       => 500000,
        'policy_critical'      => 100000,
        'policy_grace_days'    => 3,
        'policy_actions'       => ['notify_customer', 'notify_admin', 'block_purchases'],
        // Pages created by the wizard: slug-key => post ID
        'pages'                => [],
        // Notification cooldown (hours) per event type
        'notify_cooldown'      => 24,
        'data_retention_on_uninstall' => true,
        // These six were readable-with-a-fallback everywhere they were used,
        // which reads like a configurable knob, but none was in this
        // whitelist — so Options::set() silently dropped every write and the
        // "setting" was actually fixed at its fallback forever.
        'sync_batch'            => 500,
        'usage_markup_percent'  => null, // null = fall back to global_markup
        'service_term_days'     => 30,
        'renewal_reminder_days' => 5,
        'data_retention_days'   => 90,
        'customer_registration' => true,
    ];

    public static function get(string $key, $default = null)
    {
        $all = get_option(self::OPTION, []);
        if (is_array($all) && array_key_exists($key, $all)) {
            return $all[$key];
        }
        return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;
    }

    /** @return bool false when $key is not in the whitelist — the write was dropped, not applied */
    public static function set(string $key, $value): bool
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return false; // whitelist — silently ignore unknown keys
        }
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$key] = $value;
        update_option(self::OPTION, $all, false);
        return true;
    }

    /** @param array<string,mixed> $pairs */
    public static function set_many(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
    }
}
