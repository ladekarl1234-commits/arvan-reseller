<?php
/**
 * @var string $brand_name @var int $brand_logo_id @var string $brand_description
 * @var string $brand_about @var string $support_email @var string $support_phone
 * @var string $brand_color @var float $brand_contrast @var bool $demo_mode
 * @var bool $has_verified @var bool $retention @var array $license
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Brand;
use ArvanReseller\Support\Helpers;

$contrast_ok = $brand_contrast >= Brand::MIN_CONTRAST;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('برند و تنظیمات', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="arvrs_save_branding" />
    <?php wp_nonce_field('arvrs_save_branding', 'arvrs_nonce'); ?>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('هویت فروشگاه', 'arvan-reseller'); ?></h2>
      <div class="arvrs-form-grid">
        <div><label for="arvrs-brand-name"><?php esc_html_e('نام فروشگاه', 'arvan-reseller'); ?></label>
          <input id="arvrs-brand-name" type="text" name="brand_name" value="<?php echo esc_attr($brand_name); ?>" /></div>
        <div><label for="arvrs-brand-description"><?php esc_html_e('توضیح کوتاه', 'arvan-reseller'); ?></label>
          <input id="arvrs-brand-description" type="text" name="brand_description" value="<?php echo esc_attr($brand_description); ?>" /></div>
        <div><label for="arvrs-support-email"><?php esc_html_e('ایمیل پشتیبانی', 'arvan-reseller'); ?></label>
          <input id="arvrs-support-email" type="email" name="support_email" dir="ltr" value="<?php echo esc_attr($support_email); ?>" /></div>
        <div><label for="arvrs-support-phone"><?php esc_html_e('تلفن پشتیبانی', 'arvan-reseller'); ?></label>
          <input id="arvrs-support-phone" type="text" name="support_phone" dir="ltr" value="<?php echo esc_attr($support_phone); ?>" /></div>
        <div>
          <label for="arvrs-brand-color"><?php esc_html_e('رنگ برند', 'arvan-reseller'); ?></label>
          <input id="arvrs-brand-color" type="color" name="brand_color" value="<?php echo esc_attr($brand_color); ?>"
                 aria-describedby="arvrs-brand-color-help" />
          <p id="arvrs-brand-color-help" class="arvrs-help">
            <?php echo esc_html(sprintf(
                /* translators: %s: contrast ratio, e.g. 6.55 */
                __('این رنگ زمینه دکمه‌ها، نشان اعتبار و حلقه فوکوس است و متن روی آن سفید نوشته می‌شود. کنتراست فعلی: %s به ۱.', 'arvan-reseller'),
                Helpers::fa_digits(number_format($brand_contrast, 2))
            )); ?>
            <?php if ($contrast_ok) : ?>
              <span class="arvrs-tag arvrs-tag-success"><?php esc_html_e('استاندارد AA رعایت شده', 'arvan-reseller'); ?></span>
            <?php else : ?>
              <span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('کمتر از حد استاندارد (۴٫۵)', 'arvan-reseller'); ?></span>
            <?php endif; ?>
          </p>
          <?php if (!$contrast_ok) : ?>
            <p class="arvrs-help"><?php esc_html_e('اگر رنگ روشنی انتخاب کنید، هنگام ذخیره به‌طور خودکار تیره می‌شود تا متن سفید روی آن خوانا بماند؛ رنگ انتخابی شما و رنگ ذخیره‌شده در پیام تأیید نشان داده می‌شود.', 'arvan-reseller'); ?></p>
          <?php endif; ?>
        </div>
        <div>
          <label for="arvrs-brand-logo"><?php esc_html_e('لوگو (PNG/JPG/WebP، حداکثر ۱ مگابایت)', 'arvan-reseller'); ?></label>
          <input id="arvrs-brand-logo" type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp" />
          <?php if ($brand_logo_id && ($logo_url = wp_get_attachment_image_url($brand_logo_id, 'thumbnail'))) : ?>
            <p><img class="arvrs-logo-preview" src="<?php echo esc_url($logo_url); ?>" alt="<?php esc_attr_e('لوگوی فعلی فروشگاه', 'arvan-reseller'); ?>" /></p>
          <?php endif; ?>
        </div>
        <div class="arvrs-span-all"><label for="arvrs-brand-about"><?php esc_html_e('درباره ما', 'arvan-reseller'); ?></label>
          <textarea id="arvrs-brand-about" name="brand_about" rows="3"><?php echo esc_textarea($brand_about); ?></textarea></div>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('حالت اجرا', 'arvan-reseller'); ?></h2>
      <label class="arvrs-stack-check" for="arvrs-demo-mode">
        <input id="arvrs-demo-mode" type="checkbox" name="demo_mode" value="1" <?php checked($demo_mode); ?> />
        <span><strong><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></strong> —
          <?php esc_html_e('مرز خارجی (API آروان و درگاه پرداخت) شبیه‌سازی می‌شود؛ همه جریان‌های داخلی واقعی‌اند.', 'arvan-reseller'); ?></span>
      </label>
      <?php if (!$has_verified) : ?>
        <p class="arvrs-help"><?php esc_html_e('توجه: تا زمانی که یک اتصال آزمایش‌شده ArvanCloud نداشته باشید، حتی با خاموش‌کردن این گزینه، فروشگاه در حالت دمو می‌ماند.', 'arvan-reseller'); ?></p>
      <?php endif; ?>
      <label class="arvrs-stack-check" for="arvrs-data-retention">
        <input id="arvrs-data-retention" type="checkbox" name="data_retention" value="1" <?php checked($retention); ?> />
        <span><?php esc_html_e('نگه‌داشتن داده‌های مالی و مشتریان هنگام حذف افزونه (پیشنهادشده)', 'arvan-reseller'); ?></span>
      </label>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('وضعیت فعال‌سازی افزونه', 'arvan-reseller'); ?></h2>
      <?php if ($license['active']) : ?>
        <p><span class="arvrs-tag arvrs-tag-success"><?php esc_html_e('فعال', 'arvan-reseller'); ?></span>
          <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('اثر انگشت توکن: %1$s — تاریخ فعال‌سازی: %2$s', 'arvan-reseller'), $license['fingerprint'], $license['activated_at'])); ?></span></p>
        <p>
          <button type="submit" form="arvrs-license-reset" class="button button-secondary"
                  onclick="return confirm('<?php echo esc_js(__('فعال‌سازی افزونه بازنشانی شود؟ فروش تا ورود دوباره توکن متوقف می‌شود.', 'arvan-reseller')); ?>')">
            <?php esc_html_e('بازنشانی فعال‌سازی', 'arvan-reseller'); ?>
          </button>
        </p>
      <?php else : ?>
        <p><span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('غیرفعال', 'arvan-reseller'); ?></span>
          <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-setup')); ?>"><?php esc_html_e('اجرای راه‌اندازی', 'arvan-reseller'); ?></a></p>
      <?php endif; ?>
    </div>

    <p><button class="button button-primary button-hero"><?php esc_html_e('ذخیره تنظیمات', 'arvan-reseller'); ?></button></p>
  </form>

  <?php if ($license['active']) : ?>
    <form id="arvrs-license-reset" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_license_reset" />
      <?php wp_nonce_field('arvrs_license_reset', 'arvrs_nonce'); ?>
    </form>
  <?php endif; ?>
</div>
