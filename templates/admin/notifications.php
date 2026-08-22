<?php
/**
 * Admin alert feed. Vars from Menu::notifications().
 *
 * @var array $rows @var int $total @var int $page @var int $per_page
 * @var string $type @var bool $unread @var array $types @var int $unread_count
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Labels;
use ArvanReseller\Support\Helpers;

$base_url = admin_url('admin.php?page=arvan-reseller-notifications');
$pages    = (int) ceil($total / max(1, $per_page));
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('اعلان‌های مدیر', 'arvan-reseller'); ?>
    <?php if ($unread_count) : ?><span class="arvrs-tag arvrs-tag-danger"><?php echo esc_html(sprintf(__('%s خوانده‌نشده', 'arvan-reseller'), Helpers::fa_digits((string) $unread_count))); ?></span><?php endif; ?>
  </h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <p class="arvrs-help"><?php esc_html_e('هر خطای راه‌اندازی، وظیفه متوقف‌شده، اتصال ناموفق ArvanCloud و مشتری در معرض خطر اینجا ثبت می‌شود.', 'arvan-reseller'); ?></p>

  <form method="get" class="arvrs-actions-row" style="margin:12px 0">
    <input type="hidden" name="page" value="arvan-reseller-notifications" />
    <label class="arvrs-lbl" for="arvrs-note-type"><?php esc_html_e('نوع اعلان', 'arvan-reseller'); ?></label>
    <select id="arvrs-note-type" name="type">
      <option value=""><?php esc_html_e('همه', 'arvan-reseller'); ?></option>
      <?php foreach ($types as $key) : ?>
        <option value="<?php echo esc_attr($key); ?>" <?php selected($type, $key); ?>><?php echo esc_html(Labels::notification_type((string) $key)); ?></option>
      <?php endforeach; ?>
    </select>
    <label class="arvrs-inline-check">
      <input type="checkbox" name="unread" value="1" <?php checked($unread); ?> />
      <?php esc_html_e('فقط خوانده‌نشده‌ها', 'arvan-reseller'); ?>
    </label>
    <button class="button"><?php esc_html_e('اعمال فیلتر', 'arvan-reseller'); ?></button>
    <a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('پاک‌کردن', 'arvan-reseller'); ?></a>
  </form>

  <?php if ($unread_count) : ?>
    <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_notification_action" />
      <input type="hidden" name="do" value="read_all" />
      <?php wp_nonce_field('arvrs_notification_action', 'arvrs_nonce'); ?>
      <button class="button button-primary"><?php esc_html_e('همه را خوانده‌شده کن', 'arvan-reseller'); ?></button>
    </form>
  <?php endif; ?>

  <table class="widefat striped">
    <thead><tr>
      <th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('عنوان', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('متن', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th>
      <th></th>
    </tr></thead>
    <tbody>
    <?php if (empty($rows)) : ?>
      <tr><td colspan="5"><?php esc_html_e('اعلانی با این فیلتر یافت نشد.', 'arvan-reseller'); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row) : $is_read = (int) $row['is_read'] === 1; ?>
      <tr>
        <td><span class="arvrs-tag arvrs-tag-<?php echo $is_read ? 'default' : 'warning'; ?>"><?php echo esc_html(Labels::notification_type((string) $row['type'])); ?></span></td>
        <td><?php echo $is_read ? esc_html($row['title']) : '<strong>' . esc_html($row['title']) . '</strong>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped on both branches ?></td>
        <td><?php echo esc_html($row['body']); ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html(Helpers::jdate((string) $row['created_at'], 'j F Y — H:i')); ?></td>
        <td>
          <?php if (!$is_read) : ?>
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="arvrs_notification_action" />
              <input type="hidden" name="do" value="read" />
              <input type="hidden" name="notification_id" value="<?php echo esc_attr($row['id']); ?>" />
              <?php wp_nonce_field('arvrs_notification_action', 'arvrs_nonce'); ?>
              <button class="button"><?php esc_html_e('خواندم', 'arvan-reseller'); ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if ($page < $pages) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
    <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('صفحه %1$s از %2$s — %3$s اعلان', 'arvan-reseller'), Helpers::fa_digits((string) $page), Helpers::fa_digits((string) max(1, $pages)), Helpers::fa_digits((string) $total))); ?></span>
  </p>
</div>
