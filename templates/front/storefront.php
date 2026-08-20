<?php
/** @var array $products @var array $urls @var string $brand_name @var string $brand_desc */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

include __DIR__ . '/partials/shell-top.php';

$icons = [
    'cloud_server'   => '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><circle cx="7.5" cy="7.5" r="1"/><circle cx="7.5" cy="16.5" r="1"/></svg>',
    'cdn'            => '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 4 5.6 4 9s-1.5 6.4-4 9c-2.5-2.6-4-5.6-4-9s1.5-6.4 4-9z"/></svg>',
    'object_storage' => '<svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="8" ry="2.8"/><path d="M4 5.5v13c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8v-13"/><path d="M4 12c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8"/></svg>',
];
$descriptions = [
    'cloud_server'   => __('سرور مجازی پرسرعت با تحویل آنی؛ مناسب وب‌سایت، اپلیکیشن و پروژه‌های شما.', 'arvan-reseller'),
    'cdn'            => __('سرعت و امنیت وب‌سایت با شبکه توزیع محتوا؛ همراه SSL رایگان و محافظت DDoS.', 'arvan-reseller'),
    'object_storage' => __('فضای ذخیره‌سازی ابری سازگار با S3 برای فایل‌ها، بکاپ‌ها و رسانه‌ها.', 'arvan-reseller'),
];
?>
<section class="arvrs-hero">
  <h1><?php echo esc_html(sprintf(__('زیرساخت ابری، از %s', 'arvan-reseller'), $brand_name)); ?></h1>
  <p class="arvrs-hero-sub"><?php echo esc_html($brand_desc ?: __('سرور ابری، CDN و فضای ذخیره‌سازی — خرید آنلاین، تحویل خودکار و آنی، پرداخت ریالی.', 'arvan-reseller')); ?></p>
</section>

<section class="arvrs-grid arvrs-grid-3">
  <?php foreach ($products as $key => $product) : ?>
    <a class="arvrs-card arvrs-product-card" href="<?php echo esc_url($urls[$key]); ?>">
      <span class="arvrs-product-icon"><?php echo $icons[$key]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
      <h2><?php echo esc_html($product['label']); ?></h2>
      <p class="arvrs-muted"><?php echo esc_html($descriptions[$key]); ?></p>
      <?php if ($product['from']) : ?>
        <p class="arvrs-price-from"><?php echo esc_html(sprintf(__('شروع از %s در ماه', 'arvan-reseller'), Helpers::money((int) $product['from']))); ?></p>
      <?php endif; ?>
      <span class="arvrs-btn arvrs-btn-primary arvrs-btn-block"><?php esc_html_e('مشاهده پلن‌ها', 'arvan-reseller'); ?></span>
    </a>
  <?php endforeach; ?>
</section>

<section class="arvrs-features">
  <div class="arvrs-feature"><strong><?php esc_html_e('تحویل آنی', 'arvan-reseller'); ?></strong><span class="arvrs-muted"><?php esc_html_e('سرویس بلافاصله پس از پرداخت ساخته می‌شود.', 'arvan-reseller'); ?></span></div>
  <div class="arvrs-feature"><strong><?php esc_html_e('پرداخت ریالی', 'arvan-reseller'); ?></strong><span class="arvrs-muted"><?php esc_html_e('بدون نیاز به ارز و کارت بین‌المللی.', 'arvan-reseller'); ?></span></div>
  <div class="arvrs-feature"><strong><?php esc_html_e('پشتیبانی فارسی', 'arvan-reseller'); ?></strong><span class="arvrs-muted"><?php esc_html_e('در کنار شما، به زبان خودتان.', 'arvan-reseller'); ?></span></div>
</section>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
