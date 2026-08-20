<?php
namespace ArvanReseller;

use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\DemoProvider;
use ArvanReseller\Arvan\ProviderInterface;
use ArvanReseller\Arvan\RealProvider;
use ArvanReseller\Payments\PaymentProviderInterface;
use ArvanReseller\Payments\SandboxProvider;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Composition root. Wires WordPress hooks to modules and hands out shared
 * service instances. Deliberately a static registry, not a DI container:
 * one plugin, one process, one wiring. (ADR-0001)
 */
final class Plugin
{
    /** @var array<string,object> */
    private static $services = [];

    public static function boot(): void
    {
        Install\Schema::maybe_migrate();

        Identity\Customers::register_hooks();
        Front\Shortcodes::register_hooks();
        Front\Assets::register_hooks();
        Front\FormActions::register_hooks();
        Jobs\JobRunner::register_hooks();
        Usage\UsageSync::register_hooks();
        Rest\Routes::register_hooks();

        if (is_admin()) {
            Admin\Menu::register_hooks();
            Onboarding\Wizard::register_hooks();
        }
        add_action('admin_bar_menu', [self::class, 'demo_badge'], 100);
    }

    /** Whether selling capability is unlocked (valid Plugin Access Token entered). */
    public static function licensed(): bool
    {
        return Licensing\License::is_active();
    }

    public static function demo_mode(): bool
    {
        // Demo mode is explicit, or forced while no enabled credential has
        // ever passed a connection test (spec §11).
        if (Options::get('demo_mode', true)) {
            return true;
        }
        return !Credentials::has_verified_credential();
    }

    /** Active Arvan provider for a product (per-product credential routing). */
    public static function arvan(string $product = ''): ProviderInterface
    {
        $key = 'arvan:' . ($product ?: '*') . ':' . (self::demo_mode() ? 'demo' : 'real');
        if (!isset(self::$services[$key])) {
            self::$services[$key] = self::demo_mode()
                ? new DemoProvider()
                : new RealProvider(Credentials::select_for($product));
        }
        return self::$services[$key];
    }

    public static function payments(): PaymentProviderInterface
    {
        // Sandbox is the only shipped adapter; real gateways implement the
        // same interface (docs/extending). Filter lets a companion plugin
        // register one without touching core.
        if (!isset(self::$services['payments'])) {
            $provider = apply_filters('arvrs_payment_provider', new SandboxProvider());
            self::$services['payments'] = $provider instanceof PaymentProviderInterface
                ? $provider : new SandboxProvider();
        }
        return self::$services['payments'];
    }

    /** «حالت دمو» badge in the admin bar (spec §11). */
    public static function demo_badge($bar): void
    {
        if (!current_user_can('manage_options') || !self::demo_mode()) {
            return;
        }
        $bar->add_node([
            'id'    => 'arvrs-demo',
            'title' => '<span style="background:#b45309;color:#fff;padding:2px 8px;border-radius:4px">' .
                       esc_html__('حالت دمو', 'arvan-reseller') . '</span>',
            'href'  => admin_url('admin.php?page=arvan-reseller-settings'),
        ]);
    }
}
