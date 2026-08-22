<?php
/**
 * One background job, whole.
 *
 * The dead-job list used to show twelve words of the error, no payload and no
 * link — so "which order is this?" meant opening `wp_arvrs_jobs` in
 * phpMyAdmin. Everything the row holds is here, untruncated.
 *
 * @var array|null $job @var int $order
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Labels;
use ArvanReseller\Support\Helpers;

$health_url = admin_url('admin.php?page=arvan-reseller-health');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <p><a href="<?php echo esc_url($health_url); ?>">← <?php esc_html_e('بازگشت به سلامت سیستم', 'arvan-reseller'); ?></a></p>

  <?php if (!$job) : ?>
    <h1><?php esc_html_e('وظیفه یافت نشد', 'arvan-reseller'); ?></h1>
  <?php else : ?>
    <h1>
      <?php echo esc_html(sprintf(__('وظیفه #%s', 'arvan-reseller'), Helpers::fa_digits((string) (int) $job['id']))); ?>
      <?php echo Helpers::status_tag((string) $job['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
    </h1>
    <?php include __DIR__ . '/partials/notices.php'; ?>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('مشخصات', 'arvan-reseller'); ?></h2>
      <table class="widefat striped"><tbody>
        <tr><th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th><td><?php echo esc_html(Labels::job_type((string) $job['type'])); ?> <code dir="ltr"><?php echo esc_html((string) $job['type']); ?></code></td></tr>
        <tr><th><?php esc_html_e('تلاش‌ها', 'arvan-reseller'); ?></th><td><?php echo esc_html(Helpers::fa_digits((int) $job['attempts'] . '/' . (int) $job['max_attempts'])); ?></td></tr>
        <tr><th><?php esc_html_e('زمان اجرا', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html((string) $job['run_at']); ?></td></tr>
        <tr><th><?php esc_html_e('زمان تحویل به کارگر', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html((string) ($job['claimed_at'] ?: '—')); ?></td></tr>
        <tr><th><?php esc_html_e('آخرین تغییر', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html((string) $job['updated_at']); ?></td></tr>
        <?php if ($order) : ?>
          <tr><th><?php esc_html_e('سفارش مرتبط', 'arvan-reseller'); ?></th>
            <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-orders&order=' . $order)); ?>"><?php echo esc_html(sprintf(__('سفارش #%s', 'arvan-reseller'), Helpers::fa_digits((string) $order))); ?></a></td></tr>
        <?php endif; ?>
      </tbody></table>

      <div class="arvrs-actions-row" style="margin-top:12px">
        <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_job_action" /><input type="hidden" name="do" value="retry" />
          <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>" />
          <?php wp_nonce_field('arvrs_job_action', 'arvrs_nonce'); ?>
          <button class="button button-primary"><?php esc_html_e('تلاش دوباره', 'arvan-reseller'); ?></button>
        </form>
        <?php if ($job['status'] !== 'done' && $job['status'] !== 'dead') : ?>
          <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                onsubmit="return confirm('<?php echo esc_js(__('این وظیفه برای همیشه متوقف شود؟', 'arvan-reseller')); ?>')">
            <input type="hidden" name="action" value="arvrs_job_action" /><input type="hidden" name="do" value="kill" />
            <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>" />
            <?php wp_nonce_field('arvrs_job_action', 'arvrs_nonce'); ?>
            <button class="button button-link-delete"><?php esc_html_e('توقف وظیفه', 'arvan-reseller'); ?></button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('بار داده (payload)', 'arvan-reseller'); ?></h2>
      <pre dir="ltr" class="arvrs-pre"><?php echo esc_html((string) $job['payload']); ?></pre>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('آخرین خطا — کامل', 'arvan-reseller'); ?></h2>
      <?php if ((string) $job['last_error'] === '') : ?>
        <p class="arvrs-help"><?php esc_html_e('خطایی ثبت نشده است.', 'arvan-reseller'); ?></p>
      <?php else : ?>
        <pre dir="ltr" class="arvrs-pre"><?php echo esc_html((string) $job['last_error']); ?></pre>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
