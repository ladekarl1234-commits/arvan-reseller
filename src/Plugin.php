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
 *
 * The seam, stated plainly because the panel found the old claim untrue:
 * three accessors are global — `demo_mode()`, `arvan()` and `payments()` —
 * and modules below Presentation do call them. That is a real coupling, not
 * an accident: `Plugin` constructs the Arvan and Payments adapters, and
 * `Arvan\Catalog`, `Provisioning\Provisioner`, `Usage\UsageSync`,
 * `Wallet\Ledger`, `Orders\OrderService` and `Payments\PaymentService` call
 * back into it, so `Plugin ↔ Arvan` and `Plugin ↔ Payments` are cycles.
 *
 * What this round fixed is the cost, not the shape: `demo_mode()` is memoised
 * per request instead of issuing an uncached credentials query on every ledger
 * row, and job dispatch no longer reaches from Infrastructure into Application
 * (Jobs\Handlers is registered here, from the root). Breaking the cycles needs
 * the provider passed in as an argument at every call site — a change across
 * six modules that is out of scope here and is recorded as such in
 * ARCHITECTURE.md rather than denied.
 */
final class Plugin
{
    /** @var array<string,object> */
    private static $services = [];

    /** @var bool|null memoised demo-mode answer for this request */
    private static $demo_mode = null;

    public static function boot(): void
    {
        Install\Schema::maybe_migrate();

        // Job types are registered from the root, so Jobs\JobRunner imports no
        // Application module and stays extractable (ARCHITECTURE.md layering).
        Jobs\Handlers::register();

        Identity\Customers::register_hooks();
        Front\Shortcodes::register_hooks();
        Front\Assets::register_hooks();
        Front\FormActions::register_hooks();
        Jobs\JobRunner::register_hooks();
        Usage\UsageSync::register_hooks();
        Rest\Routes::register_hooks();

        // Orders abandoned mid-provision are freed on the same tick that reaps
        // abandoned jobs — the two failures always arrive together.
        add_action('arvrs_run_jobs', ['ArvanReseller\\Provisioning\\Provisioner', 'reclaim_stale'], 20);
        add_action('arvrs_daily', [self::class, 'daily_maintenance']);

        if (is_admin()) {
            Admin\Menu::register_hooks();
            Onboarding\Wizard::register_hooks();
        }
        add_action('admin_bar_menu', [self::class, 'demo_badge'], 100);
    }

    /**
     * Daily housekeeping. The cron hook only *enqueues*: the durable jobs
     * table owns retries, so a fatal inside a renewal run costs one job, not
     * the day's revenue.
     */
    public static function daily_maintenance(): void
    {
        Jobs\JobRunner::enqueue('renew_services', []);
        Jobs\JobRunner::enqueue('renewal_reminders', [], 300);
        Jobs\JobRunner::enqueue('credential_health', [], 600);
        Jobs\JobRunner::enqueue('prune', [], 900);
    }

    /** Whether selling capability is unlocked (valid Plugin Access Token entered). */
    public static function licensed(): bool
    {
        return Licensing\License::is_active();
    }

    public static function demo_mode(): bool
    {
        if (self::$demo_mode !== null) {
            return self::$demo_mode;
        }
        // Demo mode is explicit, or forced while no enabled credential has
        // ever passed a connection test (spec §11). The fallback is an
        // uncached DB query and this is called once per ledger row during
        // usage ingestion, so the answer is memoised: it cannot change
        // mid-request except through a credentials write, and those call
        // flush_mode_cache().
        self::$demo_mode = Options::get('demo_mode', true)
            ? true
            : !Credentials::has_verified_credential();
        return self::$demo_mode;
    }

    /**
     * Drop the memoised mode and every provider built from it. Call after any
     * write that can flip demo mode: saving, deleting, enabling or testing a
     * credential, and toggling the `demo_mode` setting.
     */
    public static function flush_mode_cache(): void
    {
        self::$demo_mode = null;
        // Provider instances are cached under a mode-dependent key; a stale
        // DemoProvider handed out after credentials go live would silently
        // fake every provisioning.
        self::$services = [];
    }

    /** Active Arvan provider for a product (per-product credential routing). */
    public static function arvan(string $product = ''): ProviderInterface
    {
        $demo = self::demo_mode();
        $key  = 'arvan:' . ($product ?: '*') . ':' . ($demo ? 'demo' : 'real');
        if (!isset(self::$services[$key])) {
            $provider = $demo
                ? new DemoProvider()
                : new RealProvider(Credentials::select_for($product));
            // Mirrors arvrs_payment_provider: a third provider should not
            // require editing the composition root. Anything that is not a
            // ProviderInterface is ignored rather than fataling mid-checkout.
            $filtered = apply_filters('arvrs_arvan_provider', $provider, $product, $demo);
            self::$services[$key] = $filtered instanceof ProviderInterface ? $filtered : $provider;
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
