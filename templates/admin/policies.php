<?php
/** @var int $warning @var int $critical @var int $grace @var array $actions @var int $cooldown */
defined('ABSPATH') || exit;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سیاست اعتبار و محدودسازی', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="arvrs_save_policies" />
    <?php wp_nonce_field('arvrs_save_policies', 'arvrs_nonce'); ?>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('آستانه‌ها', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('مراحل: سالم ← هشدار ← بحرانی ← مهلت (اعتبار صفر) ← محدودشده. سیاست رسمی آروان پس از منفی‌شدن اعتبار: هشدار، قطع شبکه (۲ ساعت)، خاموشی (۴۸ ساعت) و حذف منابع (۱ هفته) — این افزونه فقط اطلاع‌رسانی/محدودسازی محلی انجام می‌دهد و هرگز منبعی را خودکار حذف نمی‌کند.', 'arvan-reseller'); ?></p>
      <div class="arvrs-form-grid">
        <div><label for="arvrs-policy-warning"><?php esc_html_e('آستانه هشدار (تومان)', 'arvan-reseller'); ?></label>
          <input id="arvrs-policy-warning" type="number" name="policy_warning" min="0" step="10000" value="<?php echo esc_attr($warning); ?>" /></div>
        <div><label for="arvrs-policy-critical"><?php esc_html_e('آستانه بحرانی (تومان)', 'arvan-reseller'); ?></label>
          <input id="arvrs-policy-critical" type="number" name="policy_critical" min="0" step="10000" value="<?php echo esc_attr($critical); ?>" /></div>
        <div><label for="arvrs-policy-grace"><?php esc_html_e('دوره مهلت پس از اتمام اعتبار (روز)', 'arvan-reseller'); ?></label>
          <input id="arvrs-policy-grace" type="number" name="policy_grace_days" min="0" value="<?php echo esc_attr($grace); ?>" /></div>
        <div><label for="arvrs-notify-cooldown"><?php esc_html_e('فاصله تکرار اعلان‌ها (ساعت)', 'arvan-reseller'); ?></label>
          <input id="arvrs-notify-cooldown" type="number" name="notify_cooldown" min="1" value="<?php echo esc_attr($cooldown); ?>" /></div>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('اقدام‌های فعال', 'arvan-reseller'); ?></h2>
      <fieldset>
        <legend class="arvrs-lbl"><?php esc_html_e('هنگام رسیدن به هر مرحله، این اقدام‌ها انجام شوند', 'arvan-reseller'); ?></legend>
        <div class="arvrs-check-list">
          <?php
          $known = [
              'notify_customer' => __('اطلاع‌رسانی به مشتری', 'arvan-reseller'),
              'notify_admin'    => __('اطلاع‌رسانی به مدیر', 'arvan-reseller'),
              'block_purchases' => __('مسدودکردن خرید جدید در وضعیت محدودشده', 'arvan-reseller'),
              'mark_at_risk'    => __('علامت‌گذاری سرویس‌ها به‌عنوان در معرض تعلیق', 'arvan-reseller'),
              'suspend_service' => __('تعلیق سرویس (فقط از طریق عملیات مستند API و با تأیید مدیر)', 'arvan-reseller'),
          ];
          foreach ($known as $key => $label) : ?>
            <label class="arvrs-stack-check">
              <input type="checkbox" name="policy_actions[]" value="<?php echo esc_attr($key); ?>" <?php checked(in_array($key, $actions, true)); ?> />
              <?php echo esc_html($label); ?>
            </label>
          <?php endforeach; ?>
        </div>
      </fieldset>
    </div>
    <p><button class="button button-primary"><?php esc_html_e('ذخیره سیاست‌ها', 'arvan-reseller'); ?></button></p>
  </form>
</div>
