<?php
/** @var array $orders @var string $status @var int $page */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Support\Helpers;

$base_url = admin_url('admin.php?page=arvan-reseller-orders');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سفارش‌ها', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <a class="button <?php echo $status === '' ? 'button-primary' : ''; ?>" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('همه', 'arvan-reseller'); ?></a>
    <?php foreach (StateMachine::all() as $state) : ?>
      <a class="button <?php echo $status === $state ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('status', $state, $base_url)); ?>" dir="ltr"><?php echo esc_html($state); ?></a>
    <?php endforeach; ?>
  </div>

  <table class="widefat striped">
    <thead><tr>
      <th>#</th><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('سود', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('تاریخ', 'arvan-reseller'); ?></th><th></th>
    </tr></thead>
    <tbody>
    <?php if (empty($orders)) : ?>
      <tr><td colspan="8"><?php esc_html_e('سفارشی یافت نشد.', 'arvan-reseller'); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($orders as $order) : $user = get_userdata((int) $order['customer_id']); ?>
      <tr>
        <td><?php echo esc_html($order['id']); ?><?php if ($order['is_demo']) : ?> <span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('دمو', 'arvan-reseller'); ?></span><?php endif; ?></td>
        <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-customers&customer=' . (int) $order['customer_id'])); ?>"><?php echo esc_html($user ? $user->display_name : '#' . $order['customer_id']); ?></a></td>
        <td><?php echo esc_html(Catalog::product_label((string) $order['product'])); ?> <code dir="ltr"><?php echo esc_html($order['plan_id']); ?></code></td>
        <td><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></td>
        <td><?php echo esc_html(Helpers::money((int) $order['margin'])); ?></td>
        <td><?php echo Helpers::status_tag((string) $order['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html($order['created_at']); ?></td>
        <td><a href="<?php echo esc_url(add_query_arg('order', (int) $order['id'], $base_url)); ?>"><?php esc_html_e('جزئیات', 'arvan-reseller'); ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if (count($orders) === 20) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
  </p>
</div>
