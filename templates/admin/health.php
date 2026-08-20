<?php
/** System Health (spec: observability). Vars from Menu::health(). */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$ok  = static function (bool $good, string $good_label = '', string $bad_label = ''): string {
    return $good
        ? '<span class="arvrs-tag arvrs-tag-success">' . esc_html($good_label ?: __('سالم', 'arvan-reseller')) . '</span>'
        : '<span class="arvrs-tag arvrs-tag-danger">' . esc_html($bad_label ?: __('مشکل', 'arvan-reseller')) . '</span>';
};
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سلامت سیستم', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_sync_now" /><?php wp_nonce_field('arvrs_sync_now', 'arvrs_nonce'); ?>
      <button class="button button-primary"><?php esc_html_e('همگام‌سازی مصرف — همین حالا', 'arvan-reseller'); ?></button>
    </form>
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_run_jobs" /><?php wp_nonce_field('arvrs_run_jobs', 'arvrs_nonce'); ?>
      <button class="button"><?php esc_html_e('اجرای وظایف در صف', 'arvan-reseller'); ?></button>
    </form>
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_flush_catalog" /><?php wp_nonce_field('arvrs_flush_catalog', 'arvrs_nonce'); ?>
      <button class="button"><?php esc_html_e('نوسازی کش کاتالوگ', 'arvan-reseller'); ?></button>
    </form>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('محیط', 'arvan-reseller'); ?></h2>
    <table class="widefat striped"><tbody>
      <tr><th><?php esc_html_e('نسخه افزونه', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html($plugin_version); ?></td></tr>
      <tr><th><?php esc_html_e('نسخه پایگاه‌داده', 'arvan-reseller'); ?></th>
        <td><?php echo esc_html($schema_version . ' / ' . $schema_target); ?> <?php echo $ok($schema_version >= $schema_target, __('به‌روز', 'arvan-reseller'), __('نیازمند مهاجرت', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
      <tr><th>WordPress / PHP / MySQL</th><td dir="ltr"><?php echo esc_html($wp_version . ' / ' . $php_version . ' / ' . $mysql_version); ?></td></tr>
      <tr><th><?php esc_html_e('رمزنگاری sodium', 'arvan-reseller'); ?></th><td><?php echo $ok($sodium, __('فعال', 'arvan-reseller'), __('غیرفعال — ذخیره توکن ممکن نیست', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
      <tr><th><?php esc_html_e('زمان‌بند (WP-Cron)', 'arvan-reseller'); ?></th>
        <td><?php echo $ok(!$cron_disabled, __('فعال', 'arvan-reseller'), __('DISABLE_WP_CRON تنظیم شده — کرون سرور لازم است', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('اجرای بعدی وظایف: %1$s — مصرف: %2$s', 'arvan-reseller'),
              $next_jobs_run ? gmdate('H:i:s', $next_jobs_run) : '—', $next_usage_run ? gmdate('H:i:s', $next_usage_run) : '—')); ?></span></td></tr>
      <tr><th><?php esc_html_e('آخرین همگام‌سازی مصرف', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html($last_usage_sync ?: '—'); ?></td></tr>
      <tr><th><?php esc_html_e('درگاه پرداخت', 'arvan-reseller'); ?></th><td><?php echo esc_html($payment_provider); ?> <?php if ($demo) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></span><?php endif; ?></td></tr>
    </tbody></table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('وظایف پس‌زمینه', 'arvan-reseller'); ?></h2>
    <p>
      <?php echo esc_html(sprintf(__('در صف: %1$d — در حال اجرا: %2$d — انجام‌شده: %3$d — متوقف: %4$d', 'arvan-reseller'),
          $jobs['pending'], $jobs['running'], $jobs['done'], $jobs['dead'])); ?>
    </p>
    <?php if (!empty($failed_jobs)) : ?>
      <table class="widefat striped">
        <thead><tr><th>#</th><th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('تلاش‌ها', 'arvan-reseller'); ?></th><th><?php esc_html_e('آخرین خطا', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($failed_jobs as $job) : ?>
          <tr>
            <td><?php echo esc_html($job['id']); ?></td>
            <td dir="ltr"><?php echo esc_html($job['type']); ?></td>
            <td><?php echo esc_html($job['attempts']); ?></td>
            <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words((string) $job['last_error'], 12, '…')); ?></td>
            <td><?php echo Helpers::status_tag((string) $job['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            <td><?php if ($job['status'] === 'dead') : ?>
              <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="arvrs_job_retry" />
                <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>" />
                <?php wp_nonce_field('arvrs_job_retry', 'arvrs_nonce'); ?>
                <button class="button"><?php esc_html_e('تلاش دوباره', 'arvan-reseller'); ?></button>
              </form>
            <?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('صفحات فروشگاه', 'arvan-reseller'); ?></h2>
    <table class="widefat striped"><tbody>
      <?php foreach ($pages as $key => $page) : ?>
        <tr><th dir="ltr"><?php echo esc_html($key); ?></th>
          <td><?php echo $ok($page['status'] === 'publish', __('منتشرشده', 'arvan-reseller'), esc_html($page['status'])); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($page['url']) : ?><a href="<?php echo esc_url($page['url']); ?>" target="_blank" class="arvrs-kv-detail"><?php esc_html_e('مشاهده ↗', 'arvan-reseller'); ?></a><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اتصال‌های ArvanCloud', 'arvan-reseller'); ?></h2>
    <table class="widefat striped"><tbody>
      <?php if (empty($credentials)) : ?><tr><td><?php esc_html_e('اتصالی ثبت نشده — حالت دمو فعال است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($credentials as $credential) : ?>
        <tr><th><?php echo esc_html($credential['name']); ?></th>
          <td><?php echo $ok((bool) $credential['last_ok_at'] && !$credential['last_error'], __('متصل', 'arvan-reseller'), $credential['last_error'] ? __('خطا در آخرین آزمایش', 'arvan-reseller') : __('آزمایش‌نشده', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span class="arvrs-kv-detail"><?php echo esc_html($credential['last_ok_at'] ?: ''); ?></span></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('خطاهای اخیر (محرمانه‌زدایی‌شده)', 'arvan-reseller'); ?></h2>
    <?php if (empty($errors)) : ?>
      <p class="arvrs-help"><?php esc_html_e('خطایی ثبت نشده است. 🎉', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <table class="widefat striped">
        <tbody>
        <?php foreach ($errors as $error) : ?>
          <tr><td dir="ltr"><?php echo esc_html($error['action']); ?></td>
            <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words((string) $error['detail'], 16, '…')); ?></td>
            <td class="arvrs-kv-detail"><?php echo esc_html($error['created_at']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
