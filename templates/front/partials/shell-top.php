<?php
/**
 * Front app shell — header/branding. Expects ctx vars from Shortcodes::ctx().
 * @var string $brand_name @var string $brand_logo @var int $customer_id
 * @var array|null $balance @var int $unread @var array $urls
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;
?>
<div class="arvrs-app" dir="rtl">
  <header class="arvrs-header">
    <a class="arvrs-brand" href="<?php echo esc_url($urls['storefront']); ?>">
      <?php if ($brand_logo) : ?>
        <img src="<?php echo esc_url($brand_logo); ?>" alt="<?php echo esc_attr($brand_name); ?>" class="arvrs-logo" />
      <?php endif; ?>
      <span class="arvrs-brand-name"><?php echo esc_html($brand_name); ?></span>
    </a>
    <nav class="arvrs-nav" aria-label="<?php esc_attr_e('منوی فروشگاه', 'arvan-reseller'); ?>">
      <a href="<?php echo esc_url($urls['cloud_server']); ?>"><?php esc_html_e('سرور ابری', 'arvan-reseller'); ?></a>
      <a href="<?php echo esc_url($urls['cdn']); ?>"><?php esc_html_e('CDN', 'arvan-reseller'); ?></a>
      <a href="<?php echo esc_url($urls['object_storage']); ?>"><?php esc_html_e('فضای ابری', 'arvan-reseller'); ?></a>
    </nav>
    <div class="arvrs-header-actions">
      <?php if ($customer_id) : ?>
        <a class="arvrs-chip" href="<?php echo esc_url(add_query_arg('tab', 'wallet', $urls['dashboard'])); ?>" title="<?php esc_attr_e('اعتبار کیف پول', 'arvan-reseller'); ?>">
          <?php echo esc_html(Helpers::money((int) $balance['available'])); ?>
        </a>
        <a class="arvrs-btn arvrs-btn-ghost" href="<?php echo esc_url($urls['dashboard']); ?>">
          <?php esc_html_e('پیشخوان', 'arvan-reseller'); ?>
          <?php if ($unread) : ?><span class="arvrs-badge-dot" aria-label="<?php echo esc_attr($unread); ?>"><?php echo esc_html(Helpers::fa_digits((string) $unread)); ?></span><?php endif; ?>
        </a>
      <?php else : ?>
        <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['auth']); ?>"><?php esc_html_e('ورود / ثبت‌نام', 'arvan-reseller'); ?></a>
      <?php endif; ?>
    </div>
  </header>
  <main class="arvrs-main">
