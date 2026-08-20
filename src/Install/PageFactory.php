<?php
namespace ArvanReseller\Install;

use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * Idempotent creation of the customer-facing pages (spec §5.1, HC-1).
 * Stored page IDs are reused; an existing foreign page with the same slug is
 * never overwritten — WordPress assigns a suffixed slug to our new page.
 */
final class PageFactory
{
    /** @return array<string,array{title:string,shortcode:string}> */
    public static function definitions(): array
    {
        return [
            'storefront'    => ['title' => __('فروشگاه سرویس ابری', 'arvan-reseller'), 'shortcode' => '[arvrs_storefront]'],
            'cloud_server'  => ['title' => __('سرور ابری', 'arvan-reseller'),          'shortcode' => '[arvrs_product product="cloud_server"]'],
            'cdn'           => ['title' => __('شبکه توزیع محتوا (CDN)', 'arvan-reseller'), 'shortcode' => '[arvrs_product product="cdn"]'],
            'object_storage'=> ['title' => __('فضای ابری (Object Storage)', 'arvan-reseller'), 'shortcode' => '[arvrs_product product="object_storage"]'],
            'checkout'      => ['title' => __('تکمیل سفارش', 'arvan-reseller'),        'shortcode' => '[arvrs_checkout]'],
            'dashboard'     => ['title' => __('پیشخوان من', 'arvan-reseller'),          'shortcode' => '[arvrs_dashboard]'],
            'auth'          => ['title' => __('ورود / ثبت‌نام', 'arvan-reseller'),      'shortcode' => '[arvrs_auth]'],
            'payment'       => ['title' => __('پرداخت', 'arvan-reseller'),              'shortcode' => '[arvrs_payment]'],
        ];
    }

    /** Create any missing pages; returns key => post ID. Safe to run repeatedly. */
    public static function ensure_pages(): array
    {
        $pages = Options::get('pages', []);
        foreach (self::definitions() as $key => $def) {
            $existing = isset($pages[$key]) ? get_post((int) $pages[$key]) : null;
            if ($existing && $existing->post_status !== 'trash') {
                continue; // already created and still alive
            }
            $id = wp_insert_post([
                'post_title'   => $def['title'],
                'post_content' => $def['shortcode'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'comment_status' => 'closed',
            ]);
            if ($id && !is_wp_error($id)) {
                $pages[$key] = (int) $id;
            }
        }
        Options::set('pages', $pages);
        return $pages;
    }

    public static function url(string $key): string
    {
        $pages = Options::get('pages', []);
        $id    = isset($pages[$key]) ? (int) $pages[$key] : 0;
        $url   = $id ? get_permalink($id) : '';
        return $url ?: home_url('/');
    }

    /** @return array<string,array{id:int,status:string,url:string}> health info */
    public static function status(): array
    {
        $out = [];
        $pages = Options::get('pages', []);
        foreach (self::definitions() as $key => $def) {
            $id   = isset($pages[$key]) ? (int) $pages[$key] : 0;
            $post = $id ? get_post($id) : null;
            $out[$key] = [
                'id'     => $id,
                'status' => $post ? $post->post_status : 'missing',
                'url'    => $post ? (string) get_permalink($post) : '',
            ];
        }
        return $out;
    }
}
