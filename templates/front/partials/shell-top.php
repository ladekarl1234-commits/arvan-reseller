<?php
/**
 * Front app shell — glassy sticky header + branding (ابرآروان design system).
 * @var string $brand_name @var string $brand_logo @var int $customer_id
 * @var array|null $balance @var int $unread @var array $urls
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$brand_initial = function_exists('mb_substr') ? mb_substr($brand_name, 0, 1, 'UTF-8') : substr($brand_name, 0, 1);
$current = isset($current) ? $current : '';
?>
<div class="arvrs-app" dir="rtl">
  <header class="arvrs-header">
    <div class="arvrs-header-inner">
      <a class="arvrs-brand" href="<?php echo esc_url($urls['storefront']); ?>">
        <span class="arvrs-brand-mark">
          <?php if ($brand_logo) : ?>
            <img src="<?php echo esc_url($brand_logo); ?>" alt="<?php echo esc_attr($brand_name); ?>" />
          <?php else : ?>
            <?php echo esc_html($brand_initial ?: 'ا'); ?>
          <?php endif; ?>
        </span>
        <span class="arvrs-brand-name"><?php echo esc_html($brand_name); ?></span>
      </a>
      <nav class="arvrs-nav" aria-label="<?php esc_attr_e('منوی فروشگاه', 'arvan-reseller'); ?>">
        <a href="<?php echo esc_url($urls['cloud_server']); ?>" class="<?php echo $current === 'cloud_server' ? 'is-active' : ''; ?>"><?php esc_html_e('سرور ابری', 'arvan-reseller'); ?></a>
        <a href="<?php echo esc_url($urls['cdn']); ?>" class="<?php echo $current === 'cdn' ? 'is-active' : ''; ?>"><?php esc_html_e('CDN', 'arvan-reseller'); ?></a>
        <a href="<?php echo esc_url($urls['object_storage']); ?>" class="<?php echo $current === 'object_storage' ? 'is-active' : ''; ?>"><?php esc_html_e('فضای ابری', 'arvan-reseller'); ?></a>
      </nav>
      <div class="arvrs-header-actions">
        <?php if ($customer_id) : ?>
          <a class="arvrs-chip" href="<?php echo esc_url(add_query_arg('tab', 'wallet', $urls['dashboard'])); ?>" title="<?php esc_attr_e('اعتبار کیف پول', 'arvan-reseller'); ?>">
            <?php echo esc_html(Helpers::money((int) $balance['available'])); ?>
          </a>
          <a class="arvrs-btn arvrs-btn-dark" href="<?php echo esc_url($urls['dashboard']); ?>">
            <?php esc_html_e('پیشخوان', 'arvan-reseller'); ?>
            <?php if ($unread) : ?><span class="arvrs-badge-dot"><?php echo esc_html(Helpers::fa_digits((string) $unread)); ?></span><?php endif; ?>
          </a>
        <?php else : ?>
          <a class="arvrs-btn arvrs-btn-dark" href="<?php echo esc_url($urls['auth']); ?>"><?php esc_html_e('ورود / ثبت‌نام', 'arvan-reseller'); ?></a>
        <?php endif; ?>
      </div>
    </div>
  </header>
  <main class="arvrs-main">
