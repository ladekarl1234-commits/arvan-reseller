<?php
/** @var array $rows @var string $level */
defined('ABSPATH') || exit;

$base_url = admin_url('admin.php?page=arvan-reseller-audit');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('گزارش امنیتی و رویدادها', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <a class="button <?php echo $level === '' ? 'button-primary' : ''; ?>" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('همه', 'arvan-reseller'); ?></a>
    <a class="button <?php echo $level === 'audit' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('level', 'audit', $base_url)); ?>"><?php esc_html_e('امنیتی', 'arvan-reseller'); ?></a>
    <a class="button <?php echo $level === 'error' ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('level', 'error', $base_url)); ?>"><?php esc_html_e('خطاها', 'arvan-reseller'); ?></a>
  </div>

  <table class="widefat striped">
    <thead><tr><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th><th><?php esc_html_e('کاربر', 'arvan-reseller'); ?></th><th><?php esc_html_e('رویداد', 'arvan-reseller'); ?></th><th><?php esc_html_e('موضوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('جزئیات', 'arvan-reseller'); ?></th><th>IP</th></tr></thead>
    <tbody>
    <?php if (empty($rows)) : ?><tr><td colspan="6"><?php esc_html_e('رویدادی ثبت نشده است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $row) : $user = $row['user_id'] ? get_userdata((int) $row['user_id']) : null; ?>
      <tr>
        <td class="arvrs-kv-detail"><?php echo esc_html($row['created_at']); ?></td>
        <td><?php echo esc_html($user ? $user->display_name : __('سیستم', 'arvan-reseller')); ?></td>
        <td dir="ltr"><?php echo esc_html($row['action']); ?></td>
        <td dir="ltr"><?php echo esc_html($row['object_type'] . ($row['object_id'] ? ' #' . $row['object_id'] : '')); ?></td>
        <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words((string) $row['detail'], 14, '…')); ?></td>
        <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html($row['ip']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
