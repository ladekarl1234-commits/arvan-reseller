<?php
/** Admin dashboard. Vars from Menu::dashboard(). */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('پیشخوان فروشگاه ابری', 'arvan-reseller'); ?>
    <?php if ($demo) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></span><?php endif; ?>
  </h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <?php if (!$licensed) : ?>
    <div class="notice notice-error"><p>
      <strong><?php esc_html_e('افزونه هنوز فعال‌سازی نشده است.', 'arvan-reseller'); ?></strong>
      <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-setup')); ?>"><?php esc_html_e('اجرای راه‌اندازی', 'arvan-reseller'); ?></a>
    </p></div>
  <?php endif; ?>

  <div class="arvrs-cards">
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('مشتریان', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) $customers)); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سرویس‌های فعال', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (($services['active'] ?? 0) + ($services['at_risk'] ?? 0)))); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سفارش‌ها', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) $orders['total'])); ?></span></div>
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('درآمد', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($revenue)); ?></span><span class="sub"><?php echo esc_html(sprintf(__('هزینه پایه: %s', 'arvan-reseller'), Helpers::money($cost))); ?></span></div>
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('سود ناخالص', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($margin)); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('اعتبار مشتریان', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($customer_credit)); ?></span></div>
    <div class="arvrs-acard <?php echo $orders['failed'] ? 'is-danger' : ''; ?>"><span class="label"><?php esc_html_e('راه‌اندازی ناموفق', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) $orders['failed'])); ?></span></div>
    <div class="arvrs-acard <?php echo ($jobs['dead'] ?? 0) ? 'is-danger' : ''; ?>"><span class="label"><?php esc_html_e('وظایف متوقف', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) ($jobs['dead'] ?? 0))); ?></span><span class="sub"><?php echo esc_html(sprintf(__('در صف: %d', 'arvan-reseller'), $jobs['pending'] ?? 0)); ?></span></div>
  </div>

  <?php if (!empty($negatives)) : ?>
    <div class="arvrs-panel">
      <h2><?php esc_html_e('حساب‌های با مانده منفی', 'arvan-reseller'); ?></h2>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('مانده', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($negatives as $row) : $user = get_userdata((int) $row['customer_id']); ?>
          <tr>
            <td><?php echo esc_html($user ? $user->display_name : ('#' . $row['customer_id'])); ?></td>
            <td style="color:#dc2626;font-weight:600"><?php echo esc_html(Helpers::money((int) $row['available'])); ?></td>
            <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-customers&customer=' . (int) $row['customer_id'])); ?>"><?php esc_html_e('مشاهده', 'arvan-reseller'); ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('رویدادهای مهم اخیر', 'arvan-reseller'); ?></h2>
    <?php if (empty($recent)) : ?>
      <p class="arvrs-help"><?php esc_html_e('رویدادی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('رویداد', 'arvan-reseller'); ?></th><th><?php esc_html_e('موضوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $event) : ?>
          <tr>
            <td dir="ltr"><?php echo esc_html($event['action']); ?></td>
            <td dir="ltr"><?php echo esc_html($event['object_type'] . ($event['object_id'] ? ' #' . $event['object_id'] : '')); ?></td>
            <td class="arvrs-kv-detail"><?php echo esc_html($event['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-audit')); ?>"><?php esc_html_e('مشاهده گزارش کامل ←', 'arvan-reseller'); ?></a></p>
  </div>
</div>
