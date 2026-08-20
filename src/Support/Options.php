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
        'brand_color'          => '#0c6960',
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
    ];

    public static function get(string $key, $default = null)
    {
        $all = get_option(self::OPTION, []);
        if (is_array($all) && array_key_exists($key, $all)) {
            return $all[$key];
        }
        return array_key_exists($key, self::DEFAULTS) ? self::DEFAULTS[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        if (!array_key_exists($key, self::DEFAULTS)) {
            return; // whitelist — silently ignore unknown keys
        }
        $all = get_option(self::OPTION, []);
        if (!is_array($all)) {
            $all = [];
        }
        $all[$key] = $value;
        update_option(self::OPTION, $all, false);
    }

    /** @param array<string,mixed> $pairs */
    public static function set_many(array $pairs): void
    {
        foreach ($pairs as $k => $v) {
            self::set($k, $v);
        }
    }
}
