<?php
/** @var array $products @var array $urls @var string $brand_name @var string $brand_desc @var string $brand_about */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

include __DIR__ . '/partials/shell-top.php';

$icons = [
    'cloud_server'   => '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><rect x="3" y="4" width="18" height="7" rx="2"/><rect x="3" y="13" width="18" height="7" rx="2"/><circle cx="7.5" cy="7.5" r="1"/><circle cx="7.5" cy="16.5" r="1"/></svg>',
    'cdn'            => '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 4 5.6 4 9s-1.5 6.4-4 9c-2.5-2.6-4-5.6-4-9s1.5-6.4 4-9z"/></svg>',
    'object_storage' => '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><ellipse cx="12" cy="5.5" rx="8" ry="2.8"/><path d="M4 5.5v13c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8v-13"/><path d="M4 12c0 1.5 3.6 2.8 8 2.8s8-1.3 8-2.8"/></svg>',
];
$descriptions = [
    'cloud_server'   => __('سرور مجازی پرسرعت با تحویل آنی؛ مناسب وب‌سایت، اپلیکیشن و پروژه‌های شما.', 'arvan-reseller'),
    'cdn'            => __('سرعت و امنیت وب‌سایت با شبکه توزیع محتوا؛ همراه SSL رایگان و محافظت DDoS.', 'arvan-reseller'),
    'object_storage' => __('فضای ذخیره‌سازی ابری سازگار با S3 برای فایل‌ها، بکاپ‌ها و رسانه‌ها.', 'arvan-reseller'),
];
?>
<section class="arvrs-hero">
  <span class="arvrs-shape arvrs-shape-1" aria-hidden="true"></span>
  <span class="arvrs-shape arvrs-shape-2" aria-hidden="true"></span>
  <span class="arvrs-shape arvrs-shape-3" aria-hidden="true"></span>
  <div class="arvrs-hero-body">
    <span class="arvrs-hero-badge"><?php esc_html_e('تحویل خودکار و آنی', 'arvan-reseller'); ?></span>
    <h2 class="arvrs-h1"><?php echo esc_html(sprintf(__('زیرساخت ابری، از %s', 'arvan-reseller'), $brand_name)); ?></h2>
    <p class="arvrs-hero-sub"><?php echo esc_html($brand_desc ?: __('سرور ابری، CDN و فضای ذخیره‌سازی — خرید آنلاین، تحویل خودکار و آنی، پرداخت ریالی.', 'arvan-reseller')); ?></p>
    <div class="arvrs-hero-actions">
      <a class="arvrs-btn arvrs-btn-white" href="#arvrs-products"><?php esc_html_e('مشاهده پلن‌ها', 'arvan-reseller'); ?></a>
      <?php if (!$customer_id) : ?>
        <a class="arvrs-btn arvrs-btn-outline-light" href="<?php echo esc_url($urls['auth']); ?>"><?php esc_html_e('ورود / ثبت‌نام', 'arvan-reseller'); ?></a>
      <?php endif; ?>
    </div>
  </div>
</section>

<section id="arvrs-products" class="arvrs-products">
  <?php foreach ($products as $key => $product) : $coral = $key === 'cdn'; ?>
    <a class="arvrs-product-card" href="<?php echo esc_url($urls[$key]); ?>">
      <span class="arvrs-product-icon <?php echo $coral ? 'is-coral' : ''; ?>"><?php echo $icons[$key]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG ?></span>
      <h2><?php echo esc_html($product['label']); ?></h2>
      <p><?php echo esc_html($descriptions[$key]); ?></p>
      <?php if ($product['from']) : ?>
        <p class="arvrs-price-from"><?php esc_html_e('شروع از', 'arvan-reseller'); ?> <b><?php echo esc_html(Helpers::money((int) $product['from'])); ?></b> <?php esc_html_e('در ماه', 'arvan-reseller'); ?></p>
      <?php endif; ?>
      <span class="arvrs-product-cta"><?php esc_html_e('مشاهده پلن‌ها', 'arvan-reseller'); ?></span>
    </a>
  <?php endforeach; ?>
</section>

<section class="arvrs-features">
  <div class="arvrs-feature"><span class="arvrs-feature-mark" aria-hidden="true">✓</span><div><strong><?php esc_html_e('تحویل آنی', 'arvan-reseller'); ?></strong><div><?php esc_html_e('سرویس بلافاصله پس از پرداخت ساخته می‌شود.', 'arvan-reseller'); ?></div></div></div>
  <div class="arvrs-feature"><span class="arvrs-feature-mark is-coral" aria-hidden="true">✓</span><div><strong><?php esc_html_e('پرداخت ریالی', 'arvan-reseller'); ?></strong><div><?php esc_html_e('بدون نیاز به ارز و کارت بین‌المللی.', 'arvan-reseller'); ?></div></div></div>
  <div class="arvrs-feature"><span class="arvrs-feature-mark" aria-hidden="true">✓</span><div><strong><?php esc_html_e('پشتیبانی فارسی', 'arvan-reseller'); ?></strong><div><?php esc_html_e('در کنار شما، به زبان خودتان.', 'arvan-reseller'); ?></div></div></div>
</section>

<?php if (!empty($brand_about)) : ?>
<section class="arvrs-card arvrs-about">
  <h2 class="arvrs-card-title"><?php echo esc_html(sprintf(__('درباره %s', 'arvan-reseller'), $brand_name)); ?></h2>
  <p class="arvrs-muted"><?php echo nl2br(esc_html($brand_about)); ?></p>
</section>
<?php endif; ?>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
