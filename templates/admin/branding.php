<?php
/**
 * @var string $brand_name @var int $brand_logo_id @var string $brand_description
 * @var string $brand_about @var string $support_email @var string $support_phone
 * @var string $brand_color @var bool $demo_mode @var bool $has_verified
 * @var bool $retention @var array $license
 */
defined('ABSPATH') || exit;
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
        <div><label><?php esc_html_e('نام فروشگاه', 'arvan-reseller'); ?></label>
          <input type="text" name="brand_name" value="<?php echo esc_attr($brand_name); ?>" /></div>
        <div><label><?php esc_html_e('توضیح کوتاه', 'arvan-reseller'); ?></label>
          <input type="text" name="brand_description" value="<?php echo esc_attr($brand_description); ?>" /></div>
        <div><label><?php esc_html_e('ایمیل پشتیبانی', 'arvan-reseller'); ?></label>
          <input type="email" name="support_email" dir="ltr" value="<?php echo esc_attr($support_email); ?>" /></div>
        <div><label><?php esc_html_e('تلفن پشتیبانی', 'arvan-reseller'); ?></label>
          <input type="text" name="support_phone" dir="ltr" value="<?php echo esc_attr($support_phone); ?>" /></div>
        <div><label><?php esc_html_e('رنگ برند', 'arvan-reseller'); ?></label>
          <input type="color" name="brand_color" value="<?php echo esc_attr($brand_color); ?>" /></div>
        <div>
          <label><?php esc_html_e('لوگو (PNG/JPG/WebP، حداکثر ۱ مگابایت)', 'arvan-reseller'); ?></label>
          <input type="file" name="brand_logo" accept="image/png,image/jpeg,image/webp" />
          <?php if ($brand_logo_id && ($logo_url = wp_get_attachment_image_url($brand_logo_id, 'thumbnail'))) : ?>
            <p><img src="<?php echo esc_url($logo_url); ?>" alt="" style="height:48px;border-radius:8px" /></p>
          <?php endif; ?>
        </div>
        <div style="grid-column:1/-1"><label><?php esc_html_e('درباره ما', 'arvan-reseller'); ?></label>
          <textarea name="brand_about" rows="3"><?php echo esc_textarea($brand_about); ?></textarea></div>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('حالت اجرا', 'arvan-reseller'); ?></h2>
      <label style="font-weight:400;display:flex;gap:8px">
        <input type="checkbox" name="demo_mode" value="1" <?php checked($demo_mode); ?> />
        <span><strong><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></strong> —
          <?php esc_html_e('مرز خارجی (API آروان و درگاه پرداخت) شبیه‌سازی می‌شود؛ همه جریان‌های داخلی واقعی‌اند.', 'arvan-reseller'); ?></span>
      </label>
      <?php if (!$has_verified) : ?>
        <p class="arvrs-help"><?php esc_html_e('توجه: تا زمانی که یک اتصال آزمایش‌شده ArvanCloud نداشته باشید، حتی با خاموش‌کردن این گزینه، فروشگاه در حالت دمو می‌ماند.', 'arvan-reseller'); ?></p>
      <?php endif; ?>
      <label style="font-weight:400;display:flex;gap:8px;margin-top:8px">
        <input type="checkbox" name="data_retention" value="1" <?php checked($retention); ?> />
        <span><?php esc_html_e('نگه‌داشتن داده‌های مالی و مشتریان هنگام حذف افزونه (پیشنهادشده)', 'arvan-reseller'); ?></span>
      </label>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('وضعیت فعال‌سازی افزونه', 'arvan-reseller'); ?></h2>
      <?php if ($license['active']) : ?>
        <p><span class="arvrs-tag arvrs-tag-success"><?php esc_html_e('فعال', 'arvan-reseller'); ?></span>
          <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('اثر انگشت توکن: %1$s — تاریخ فعال‌سازی: %2$s', 'arvan-reseller'), $license['fingerprint'], $license['activated_at'])); ?></span></p>
      <?php else : ?>
        <p><span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('غیرفعال', 'arvan-reseller'); ?></span>
          <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-setup')); ?>"><?php esc_html_e('اجرای راه‌اندازی', 'arvan-reseller'); ?></a></p>
      <?php endif; ?>
    </div>

    <p><button class="button button-primary button-hero"><?php esc_html_e('ذخیره تنظیمات', 'arvan-reseller'); ?></button></p>
  </form>
</div>
