<?php
namespace ArvanReseller\Front;

use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Front-end assets, enqueued ONLY on pages that render our shortcodes
 * (spec §12: performance). Vanilla CSS/JS, no build step (ADR-0002).
 */
final class Assets
{
    public static function register_hooks(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue']);
    }

    public static function maybe_enqueue(): void
    {
        if (!is_singular()) {
            return;
        }
        $post = get_post();
        if (!$post || !has_shortcode((string) $post->post_content, 'arvrs_storefront')
            && !has_shortcode((string) $post->post_content, 'arvrs_product')
            && !has_shortcode((string) $post->post_content, 'arvrs_checkout')
            && !has_shortcode((string) $post->post_content, 'arvrs_dashboard')
            && !has_shortcode((string) $post->post_content, 'arvrs_auth')
            && !has_shortcode((string) $post->post_content, 'arvrs_payment')) {
            return;
        }
        wp_enqueue_style('arvrs-front', ARVRS_URL . 'assets/css/front.css', [], ARVRS_VERSION);
        wp_enqueue_script('arvrs-front', ARVRS_URL . 'assets/js/front.js', [], ARVRS_VERSION, true);
        wp_localize_script('arvrs-front', 'ARVRS', [
            'rest'  => esc_url_raw(rest_url('arvan-reseller/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'pages' => [
                'dashboard' => \ArvanReseller\Install\PageFactory::url('dashboard'),
                'auth'      => \ArvanReseller\Install\PageFactory::url('auth'),
                'checkout'  => \ArvanReseller\Install\PageFactory::url('checkout'),
            ],
            'i18n' => [
                'processing' => __('در حال پردازش…', 'arvan-reseller'),
                'error'      => __('خطایی رخ داد. دوباره تلاش کنید.', 'arvan-reseller'),
            ],
            'brand_color' => sanitize_hex_color((string) Options::get('brand_color', '#0c6960')) ?: '#0c6960',
        ]);
        // Brand accent as a CSS custom property override.
        $color = sanitize_hex_color((string) Options::get('brand_color', '#0c6960')) ?: '#0c6960';
        wp_add_inline_style('arvrs-front', ':root{--arvrs-brand:' . $color . ';}');
    }
}
