<?php
/**
 * System Health (spec: observability). Vars from Menu::health().
 *
 * @var string $plugin_version @var int $schema_version @var int $schema_target
 * @var array $schema_check @var string $wp_version @var string $php_version
 * @var string $mysql_version @var bool $sodium @var bool $cron_disabled
 * @var int|false $next_jobs_run @var int|false $next_usage_run @var int|false $next_daily_run
 * @var string $last_usage_sync @var array $last_usage_stats @var array $jobs
 * @var array $failed_jobs @var array $pages @var array $credentials @var bool $demo
 * @var string $payment_provider @var array $errors @var int $retention_days @var array $last_prune
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Labels;
use ArvanReseller\Support\Helpers;

$ok = static function (bool $good, string $good_label = '', string $bad_label = ''): string {
    return $good
        ? '<span class="arvrs-tag arvrs-tag-success">' . esc_html($good_label ?: __('سالم', 'arvan-reseller')) . '</span>'
        : '<span class="arvrs-tag arvrs-tag-danger">' . esc_html($bad_label ?: __('مشکل', 'arvan-reseller')) . '</span>';
};
$health_url = admin_url('admin.php?page=arvan-reseller-health');
$stale      = (int) ($jobs['stale_running'] ?? 0);
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سلامت سیستم', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <?php if ($demo) : ?>
      <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="arvrs_sync_now" /><?php wp_nonce_field('arvrs_sync_now', 'arvrs_nonce'); ?>
        <button class="button button-primary"><?php esc_html_e('همگام‌سازی مصرف — همین حالا', 'arvan-reseller'); ?></button>
      </form>
    <?php endif; ?>
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_run_jobs" /><?php wp_nonce_field('arvrs_run_jobs', 'arvrs_nonce'); ?>
      <button class="button"><?php esc_html_e('اجرای وظایف در صف', 'arvan-reseller'); ?></button>
    </form>
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_flush_catalog" /><?php wp_nonce_field('arvrs_flush_catalog', 'arvrs_nonce'); ?>
      <button class="button"><?php esc_html_e('نوسازی کش کاتالوگ', 'arvan-reseller'); ?></button>
    </form>
  </div>

  <?php if (!$demo) : ?>
    <div class="notice notice-info inline"><p>
      <?php esc_html_e('در حالت واقعی، ArvanCloud هیچ API عمومی برای گزارش مصرف ندارد؛ به همین دلیل «همگام‌سازی مصرف» در این حالت هیچ ردیفی تولید نمی‌کند و نمایش داده نمی‌شود. درآمد تکرارشونده از تمدید دوره‌ای سرویس‌ها (موتور تمدید) می‌آید، نه از مصرف اندازه‌گیری‌شده.', 'arvan-reseller'); ?>
    </p></div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('محیط', 'arvan-reseller'); ?></h2>
    <table class="widefat striped"><tbody>
      <tr><th><?php esc_html_e('نسخه افزونه', 'arvan-reseller'); ?></th><td dir="ltr"><?php echo esc_html($plugin_version); ?></td></tr>
      <tr><th><?php esc_html_e('نسخه پایگاه‌داده', 'arvan-reseller'); ?></th>
        <td><?php echo esc_html($schema_version . ' / ' . $schema_target); ?> <?php echo $ok($schema_version >= $schema_target, __('به‌روز', 'arvan-reseller'), __('نیازمند مهاجرت', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
      <tr><th><?php esc_html_e('ساختار جدول‌ها و کلیدهای یکتا', 'arvan-reseller'); ?></th>
        <td>
          <?php echo $ok(!empty($schema_check['ok']), __('تأیید شد', 'arvan-reseller'), __('ناقص', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <?php if (!empty($schema_check['missing'])) : ?>
            <span class="arvrs-kv-detail" dir="ltr"><?php echo esc_html(implode('، ', (array) $schema_check['missing'])); ?></span>
            <p class="arvrs-help"><?php esc_html_e('این کلیدهای یکتا همان چیزی هستند که ثبت دوباره یک پرداخت یا یک تمدید را غیرممکن می‌کنند. تا زمانی که ناقص‌اند، افزونه را دوباره فعال کنید تا مهاجرت اجرا شود.', 'arvan-reseller'); ?></p>
          <?php elseif (!empty($schema_check['note'])) : ?>
            <span class="arvrs-kv-detail"><?php echo esc_html((string) $schema_check['note']); ?></span>
          <?php else : ?>
            <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('%s جدول بررسی شد.', 'arvan-reseller'), Helpers::fa_digits((string) count((array) $schema_check['tables'])))); ?></span>
          <?php endif; ?>
        </td></tr>
      <tr><th>WordPress / PHP / MySQL</th><td dir="ltr"><?php echo esc_html($wp_version . ' / ' . $php_version . ' / ' . $mysql_version); ?></td></tr>
      <tr><th><?php esc_html_e('رمزنگاری sodium', 'arvan-reseller'); ?></th><td><?php echo $ok($sodium, __('فعال', 'arvan-reseller'), __('غیرفعال — ذخیره توکن ممکن نیست', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td></tr>
      <tr><th><?php esc_html_e('زمان‌بند (WP-Cron)', 'arvan-reseller'); ?></th>
        <td><?php echo $ok(!$cron_disabled, __('فعال', 'arvan-reseller'), __('DISABLE_WP_CRON تنظیم شده — کرون سرور لازم است', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('اجرای بعدی — وظایف: %1$s، مصرف: %2$s، روزانه: %3$s', 'arvan-reseller'),
              $next_jobs_run ? gmdate('H:i:s', $next_jobs_run) : '—',
              $next_usage_run ? gmdate('H:i:s', $next_usage_run) : '—',
              $next_daily_run ? gmdate('H:i:s', $next_daily_run) : '—')); ?></span></td></tr>
      <?php if ($demo) : ?>
        <tr><th><?php esc_html_e('آخرین همگام‌سازی مصرف', 'arvan-reseller'); ?></th>
          <td dir="ltr"><?php echo esc_html($last_usage_sync ?: '—'); ?>
            <?php if (!empty($last_usage_stats)) : ?>
              <span class="arvrs-kv-detail" dir="rtl"><?php echo esc_html(sprintf(__('%1$s سرویس، %2$s رکورد', 'arvan-reseller'),
                  Helpers::fa_digits((string) (int) ($last_usage_stats['services'] ?? 0)),
                  Helpers::fa_digits((string) (int) ($last_usage_stats['ingested'] ?? 0)))); ?></span>
            <?php endif; ?></td></tr>
      <?php endif; ?>
      <tr><th><?php esc_html_e('درگاه پرداخت', 'arvan-reseller'); ?></th><td><?php echo esc_html($payment_provider); ?> <?php if ($demo) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></span><?php endif; ?></td></tr>
    </tbody></table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('وظایف پس‌زمینه', 'arvan-reseller'); ?></h2>
    <p>
      <?php echo esc_html(sprintf(__('در صف: %1$s — در حال اجرا: %2$s — انجام‌شده: %3$s — متوقف: %4$s', 'arvan-reseller'),
          Helpers::fa_digits((string) (int) $jobs['pending']),
          Helpers::fa_digits((string) (int) $jobs['running']),
          Helpers::fa_digits((string) (int) $jobs['done']),
          Helpers::fa_digits((string) (int) $jobs['dead']))); ?>
    </p>
    <?php if ($stale) : ?>
      <div class="notice notice-warning inline"><p>
        <?php echo esc_html(sprintf(__('%s وظیفه بیش از حد مجاز در وضعیت «در حال اجرا» مانده است. این معمولاً یعنی پردازش وردپرس وسط کار قطع شده (خطای مرگبار PHP، کمبود حافظه یا اتمام زمان اجرا) و آن وظیفه بدون آزادسازی هرگز دوباره اجرا نمی‌شود.', 'arvan-reseller'), Helpers::fa_digits((string) $stale))); ?>
      </p></div>
    <?php endif; ?>
    <div class="arvrs-actions-row" style="margin:8px 0">
      <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="arvrs_job_action" /><input type="hidden" name="do" value="reap" />
        <?php wp_nonce_field('arvrs_job_action', 'arvrs_nonce'); ?>
        <button class="button <?php echo $stale ? 'button-primary' : ''; ?>"><?php esc_html_e('آزادسازی وظایف رهاشده', 'arvan-reseller'); ?></button>
      </form>
    </div>
    <?php if (!empty($failed_jobs)) : ?>
      <table class="widefat striped">
        <thead><tr>
          <th>#</th><th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('موضوع', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('تلاش‌ها', 'arvan-reseller'); ?></th><th><?php esc_html_e('آخرین خطا', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($failed_jobs as $job) :
            $payload  = json_decode((string) $job['payload'], true);
            $order_id = is_array($payload) ? (int) ($payload['order_id'] ?? 0) : 0;
            $job_error = (string) $job['last_error'];
            ?>
          <tr>
            <td><a href="<?php echo esc_url(add_query_arg('job', (int) $job['id'], $health_url)); ?>"><?php echo esc_html(Helpers::fa_digits((string) (int) $job['id'])); ?></a></td>
            <td><?php echo esc_html(Labels::job_type((string) $job['type'])); ?></td>
            <td>
              <?php if ($order_id) : ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-orders&order=' . $order_id)); ?>"><?php echo esc_html(sprintf(__('سفارش #%s', 'arvan-reseller'), Helpers::fa_digits((string) $order_id))); ?></a>
              <?php else : ?>
                <span class="arvrs-kv-detail">—</span>
              <?php endif; ?>
            </td>
            <td><?php echo esc_html(Helpers::fa_digits((string) ((int) $job['attempts'] . '/' . (int) $job['max_attempts']))); ?></td>
            <td>
              <?php if ($job_error === '') : ?>
                <span class="arvrs-kv-detail">—</span>
              <?php else : ?>
                <details>
                  <summary dir="ltr" class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words($job_error, 12, '…')); ?></summary>
                  <pre dir="ltr" class="arvrs-pre"><?php echo esc_html($job_error); ?></pre>
                </details>
              <?php endif; ?>
            </td>
            <td><?php echo Helpers::status_tag((string) $job['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            <td class="arvrs-actions-row">
              <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="arvrs_job_action" /><input type="hidden" name="do" value="retry" />
                <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>" />
                <?php wp_nonce_field('arvrs_job_action', 'arvrs_nonce'); ?>
                <button class="button"><?php esc_html_e('تلاش دوباره', 'arvan-reseller'); ?></button>
              </form>
              <?php if ($job['status'] !== 'dead') : ?>
                <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                      onsubmit="return confirm('<?php echo esc_js(__('این وظیفه برای همیشه متوقف شود؟', 'arvan-reseller')); ?>')">
                  <input type="hidden" name="action" value="arvrs_job_action" /><input type="hidden" name="do" value="kill" />
                  <input type="hidden" name="job_id" value="<?php echo esc_attr($job['id']); ?>" />
                  <?php wp_nonce_field('arvrs_job_action', 'arvrs_nonce'); ?>
                  <button class="button button-link-delete"><?php esc_html_e('توقف', 'arvan-reseller'); ?></button>
                </form>
              <?php endif; ?>
              <a class="button" href="<?php echo esc_url(add_query_arg('job', (int) $job['id'], $health_url)); ?>"><?php esc_html_e('جزئیات', 'arvan-reseller'); ?></a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php else : ?>
      <p class="arvrs-help"><?php esc_html_e('هیچ وظیفه‌ای نیازمند رسیدگی نیست. 🎉', 'arvan-reseller'); ?></p>
    <?php endif; ?>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('نگه‌داری و پاک‌سازی داده', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('دفتر کل مالی هرگز پاک نمی‌شود — سند مالی شماست. آنچه پاک می‌شود: رویدادهای تشخیصی گزارش، اعلان‌های خوانده‌شده، وظایف انجام‌شده و بار خام مصرف (اعداد مصرف باقی می‌مانند).', 'arvan-reseller'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arvrs-form-grid">
      <input type="hidden" name="action" value="arvrs_prune_now" />
      <?php wp_nonce_field('arvrs_prune_now', 'arvrs_nonce'); ?>
      <div>
        <label class="arvrs-lbl" for="arvrs-retention"><?php esc_html_e('دوره نگه‌داری (روز)', 'arvan-reseller'); ?></label>
        <input id="arvrs-retention" type="number" name="retention_days" min="7" max="3650" value="<?php echo esc_attr($retention_days); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-prune-run"><?php esc_html_e('اجرا', 'arvan-reseller'); ?></label>
        <button id="arvrs-prune-run" class="button"><?php esc_html_e('ذخیره و پاک‌سازی همین حالا', 'arvan-reseller'); ?></button>
      </div>
    </form>
    <?php if (!empty($last_prune['at'])) : ?>
      <p class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('آخرین پاک‌سازی: %s', 'arvan-reseller'), Helpers::jdate((string) $last_prune['at'], 'j F Y — H:i'))); ?></p>
    <?php endif; ?>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('صفحات فروشگاه', 'arvan-reseller'); ?></h2>
    <table class="widefat striped"><tbody>
      <?php foreach ($pages as $key => $page) : ?>
        <tr><th><?php echo esc_html(Labels::page_title((string) $key)); ?></th>
          <td><?php echo $ok($page['status'] === 'publish', __('منتشرشده', 'arvan-reseller'), __('ساخته نشده', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($page['url']) : ?><a href="<?php echo esc_url($page['url']); ?>" target="_blank" class="arvrs-kv-detail"><?php esc_html_e('مشاهده ↗', 'arvan-reseller'); ?></a><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </tbody></table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اتصال‌های ArvanCloud', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('این اتصال‌ها هر روز به‌صورت خودکار آزمایش می‌شوند؛ ستون «آخرین آزمایش موفق» تازگی این عدد را نشان می‌دهد. توکن باطل‌شده دیگر «متصل» نمی‌ماند.', 'arvan-reseller'); ?></p>
    <table class="widefat striped"><tbody>
      <?php if (empty($credentials)) : ?><tr><td><?php esc_html_e('اتصالی ثبت نشده — حالت دمو فعال است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($credentials as $credential) :
          $last_ok = (string) $credential['last_ok_at'];
          $age     = $last_ok ? (int) floor((time() - (int) strtotime($last_ok . ' UTC')) / DAY_IN_SECONDS) : -1;
          ?>
        <tr><th><?php echo esc_html($credential['name']); ?></th>
          <td><?php echo $ok((bool) $last_ok && !$credential['last_error'], __('متصل', 'arvan-reseller'), $credential['last_error'] ? __('خطا در آخرین آزمایش', 'arvan-reseller') : __('آزمایش‌نشده', 'arvan-reseller')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <span class="arvrs-kv-detail">
              <?php if ($age < 0) : ?>
                <?php esc_html_e('هرگز آزمایش نشده', 'arvan-reseller'); ?>
              <?php elseif ($age === 0) : ?>
                <?php esc_html_e('آخرین آزمایش موفق: امروز', 'arvan-reseller'); ?>
              <?php else : ?>
                <?php echo esc_html(sprintf(__('آخرین آزمایش موفق: %s روز پیش', 'arvan-reseller'), Helpers::fa_digits((string) $age))); ?>
              <?php endif; ?>
            </span>
            <?php if (!empty($credential['last_error'])) : ?>
              <p class="arvrs-help" dir="ltr"><?php echo esc_html((string) $credential['last_error']); ?></p>
            <?php endif; ?>
          </td></tr>
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
        <?php foreach ($errors as $error_row) : ?>
          <tr><td dir="ltr"><?php echo esc_html($error_row['action']); ?></td>
            <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words((string) $error_row['detail'], 16, '…')); ?></td>
            <td class="arvrs-kv-detail" dir="ltr"><?php echo esc_html($error_row['created_at']); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-audit&level=error')); ?>"><?php esc_html_e('همه خطاها در گزارش امنیتی ←', 'arvan-reseller'); ?></a></p>
  </div>
</div>
