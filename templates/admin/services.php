<?php
/** @var array $services @var int $page */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سرویس‌ها', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>
  <table class="widefat striped">
    <thead><tr><th>#</th><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('شناسه ابری', 'arvan-reseller'); ?></th><th><?php esc_html_e('اتصال', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('ایجاد', 'arvan-reseller'); ?></th></tr></thead>
    <tbody>
    <?php if (empty($services)) : ?><tr><td colspan="7"><?php esc_html_e('سرویسی ثبت نشده است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
    <?php foreach ($services as $service) : ?>
      <tr>
        <td><?php echo esc_html($service['id']); ?><?php if ($service['is_demo']) : ?> <span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('دمو', 'arvan-reseller'); ?></span><?php endif; ?></td>
        <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-customers&customer=' . (int) $service['customer_id'])); ?>"><?php echo esc_html($service['customer']); ?></a></td>
        <td><?php echo esc_html(Catalog::product_label((string) $service['product'])); ?> <code dir="ltr"><?php echo esc_html($service['plan_id']); ?></code></td>
        <td><code dir="ltr"><?php echo esc_html($service['remote_id']); ?></code></td>
        <td><code dir="ltr" style="font-size:11px"><?php echo esc_html(wp_trim_words((string) $service['connection'], 8, '…')); ?></code></td>
        <td><?php echo Helpers::status_tag((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html($service['created_at']); ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if (count($services) === 20) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
  </p>
</div>
