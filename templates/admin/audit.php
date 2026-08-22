<?php
/**
 * Audit investigation screen: filter, page and export.
 *
 * The log was queryable only as "the newest 100 rows, optionally one level",
 * so answering "what happened to order #4127 last Tuesday" was impossible on
 * a site with any volume — the rows had already scrolled off.
 *
 * @var array $result @var array $filters @var array $actions
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$base_url = admin_url('admin.php?page=arvan-reseller-audit');
$rows     = (array) $result['rows'];
$page     = (int) $result['page'];
$pages    = max(1, (int) $result['pages']);
$levels   = [
    ''      => __('همه سطح‌ها', 'arvan-reseller'),
    'audit' => __('امنیتی', 'arvan-reseller'),
    'error' => __('خطا', 'arvan-reseller'),
    'info'  => __('اطلاعات', 'arvan-reseller'),
    'debug' => __('اشکال‌زدایی', 'arvan-reseller'),
];
$objects = ['', 'order', 'service', 'user', 'credential', 'settings', 'license', 'job', 'system', 'audit_log'];
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('گزارش امنیتی و رویدادها', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="get" class="arvrs-panel">
    <input type="hidden" name="page" value="arvan-reseller-audit" />
    <h2><?php esc_html_e('جست‌وجو در رویدادها', 'arvan-reseller'); ?></h2>
    <div class="arvrs-form-grid">
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-level"><?php esc_html_e('سطح', 'arvan-reseller'); ?></label>
        <select id="arvrs-audit-level" name="level">
          <?php foreach ($levels as $key => $label) : ?>
            <option value="<?php echo esc_attr($key); ?>" <?php selected($filters['level'], $key); ?>><?php echo esc_html($label); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-action"><?php esc_html_e('رویداد', 'arvan-reseller'); ?></label>
        <select id="arvrs-audit-action" name="audit_action">
          <option value=""><?php esc_html_e('همه رویدادها', 'arvan-reseller'); ?></option>
          <?php foreach ($actions as $action_name) : ?>
            <option value="<?php echo esc_attr($action_name); ?>" <?php selected($filters['action'], $action_name); ?>><?php echo esc_html($action_name); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-objtype"><?php esc_html_e('نوع موضوع', 'arvan-reseller'); ?></label>
        <select id="arvrs-audit-objtype" name="object_type">
          <?php foreach ($objects as $object) : ?>
            <option value="<?php echo esc_attr($object); ?>" <?php selected($filters['object_type'], $object); ?>><?php echo esc_html($object !== '' ? $object : __('همه', 'arvan-reseller')); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-objid"><?php esc_html_e('شناسه موضوع (مثلاً شماره سفارش)', 'arvan-reseller'); ?></label>
        <input id="arvrs-audit-objid" type="text" name="object_id" dir="ltr" value="<?php echo esc_attr($filters['object_id']); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-user"><?php esc_html_e('شناسه کاربر', 'arvan-reseller'); ?></label>
        <input id="arvrs-audit-user" type="number" name="user_id" min="0" value="<?php echo esc_attr($filters['user_id'] ?: ''); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-from"><?php esc_html_e('از تاریخ (میلادی)', 'arvan-reseller'); ?></label>
        <input id="arvrs-audit-from" type="date" name="from" value="<?php echo esc_attr(substr($filters['from'], 0, 10)); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-to"><?php esc_html_e('تا تاریخ (میلادی)', 'arvan-reseller'); ?></label>
        <input id="arvrs-audit-to" type="date" name="to" value="<?php echo esc_attr(substr($filters['to'], 0, 10)); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-audit-submit"><?php esc_html_e('اعمال', 'arvan-reseller'); ?></label>
        <button id="arvrs-audit-submit" class="button button-primary"><?php esc_html_e('جست‌وجو', 'arvan-reseller'); ?></button>
      </div>
    </div>
    <p class="arvrs-actions-row">
      <a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('پاک‌کردن فیلترها', 'arvan-reseller'); ?></a>
      <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('%s رویداد با این فیلتر', 'arvan-reseller'), Helpers::fa_digits((string) (int) $result['total']))); ?></span>
    </p>
  </form>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arvrs-actions-row" style="margin:12px 0">
    <input type="hidden" name="action" value="arvrs_audit_export" />
    <?php wp_nonce_field('arvrs_audit_export', 'arvrs_nonce'); ?>
    <input type="hidden" name="level" value="<?php echo esc_attr($filters['level']); ?>" />
    <input type="hidden" name="audit_action" value="<?php echo esc_attr($filters['action']); ?>" />
    <input type="hidden" name="object_type" value="<?php echo esc_attr($filters['object_type']); ?>" />
    <input type="hidden" name="object_id" value="<?php echo esc_attr($filters['object_id']); ?>" />
    <input type="hidden" name="user_id" value="<?php echo esc_attr($filters['user_id']); ?>" />
    <input type="hidden" name="from" value="<?php echo esc_attr($filters['from']); ?>" />
    <input type="hidden" name="to" value="<?php echo esc_attr($filters['to']); ?>" />
    <button class="button"><?php esc_html_e('خروجی CSV از همین فیلتر', 'arvan-reseller'); ?></button>
  </form>

  <table class="widefat striped">
    <thead><tr><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th><th><?php esc_html_e('سطح', 'arvan-reseller'); ?></th><th><?php esc_html_e('کاربر', 'arvan-reseller'); ?></th><th><?php esc_html_e('رویداد', 'arvan-reseller'); ?></th><th><?php esc_html_e('موضوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('جزئیات', 'arvan-reseller'); ?></th><th>IP</th></tr></thead>
    <tbody>
    <?php if (empty($rows)) : ?><tr><td colspan="7"><?php esc_html_e('رویدادی با این فیلتر یافت نشد.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $row) :
        $user   = $row['user_id'] ? get_userdata((int) $row['user_id']) : null;
        $detail = (string) $row['detail'];
        ?>
      <tr>
        <td class="arvrs-kv-detail" dir="ltr"><?php echo esc_html($row['created_at']); ?></td>
        <td><?php echo esc_html($levels[(string) $row['level']] ?? (string) $row['level']); ?></td>
        <td><?php echo esc_html($user ? $user->display_name : __('سیستم', 'arvan-reseller')); ?></td>
        <td dir="ltr"><?php echo esc_html($row['action']); ?></td>
        <td dir="ltr">
          <?php if ((string) $row['object_type'] === 'order' && (int) $row['object_id'] > 0) : ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-orders&order=' . (int) $row['object_id'])); ?>">order #<?php echo esc_html((string) (int) $row['object_id']); ?></a>
          <?php else : ?>
            <?php echo esc_html($row['object_type'] . ($row['object_id'] ? ' #' . $row['object_id'] : '')); ?>
          <?php endif; ?>
        </td>
        <td dir="ltr" class="arvrs-kv-detail">
          <?php if ($detail === '') : ?>—<?php else : ?>
            <details><summary><?php echo esc_html(wp_trim_words($detail, 10, '…')); ?></summary><pre class="arvrs-pre"><?php echo esc_html($detail); ?></pre></details>
          <?php endif; ?>
        </td>
        <td dir="ltr" class="arvrs-kv-detail"><?php echo esc_html($row['ip']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if ($page < $pages) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
    <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('صفحه %1$s از %2$s', 'arvan-reseller'), Helpers::fa_digits((string) $page), Helpers::fa_digits((string) $pages))); ?></span>
  </p>
</div>
