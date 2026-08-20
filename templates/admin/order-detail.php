<?php
/** @var array|null $order @var array $events @var array|null $service @var \WP_User|false $customer */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

?>
<div class="wrap arvrs-admin" dir="rtl">
  <?php if (!$order) : ?>
    <h1><?php esc_html_e('سفارش یافت نشد', 'arvan-reseller'); ?></h1>
    <?php return; ?>
  <?php endif; ?>
  <h1>
    <?php echo esc_html(sprintf(__('سفارش #%d', 'arvan-reseller'), (int) $order['id'])); ?>
    <?php echo Helpers::status_tag((string) $order['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
  </h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اطلاعات سفارش', 'arvan-reseller'); ?></h2>
    <?php $pricing = json_decode((string) $order['pricing'], true) ?: []; ?>
    <table class="widefat striped">
      <tbody>
        <tr><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><td><?php echo esc_html($customer ? $customer->display_name . ' — ' . $customer->user_email : '#' . $order['customer_id']); ?></td></tr>
        <tr><th><?php esc_html_e('محصول / پلن', 'arvan-reseller'); ?></th><td><?php echo esc_html(Catalog::product_label((string) $order['product'])); ?> — <code dir="ltr"><?php echo esc_html($order['plan_id']); ?></code></td></tr>
        <tr><th><?php esc_html_e('پیکربندی', 'arvan-reseller'); ?></th><td><code dir="ltr"><?php echo esc_html((string) $order['config']); ?></code></td></tr>
        <tr><th><?php esc_html_e('شناسه پرداخت', 'arvan-reseller'); ?></th><td><code dir="ltr"><?php echo esc_html($order['payment_ref']); ?></code></td></tr>
        <tr><th><?php esc_html_e('هزینه پایه', 'arvan-reseller'); ?></th><td><?php echo esc_html(Helpers::money((int) $order['base_cost'])); ?></td></tr>
        <tr><th><?php esc_html_e('قیمت مشتری', 'arvan-reseller'); ?></th><td><strong><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></strong>
          <span class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('(سود: %1$s — قاعده: %2$s %3$s٪)', 'arvan-reseller'), Helpers::money((int) $order['margin']), $pricing['markup_source'] ?? '', $pricing['markup_percent'] ?? '')); ?></span></td></tr>
        <?php if ($service) : ?>
          <tr><th><?php esc_html_e('سرویس', 'arvan-reseller'); ?></th><td>#<?php echo esc_html($service['id']); ?> — <code dir="ltr"><?php echo esc_html($service['remote_id']); ?></code></td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="arvrs-actions-row" style="margin-top:12px">
      <?php if (in_array($order['status'], ['provision_failed', 'paid'], true)) : ?>
        <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_order_action" /><input type="hidden" name="do" value="retry_provision" />
          <input type="hidden" name="order_id" value="<?php echo esc_attr($order['id']); ?>" />
          <?php wp_nonce_field('arvrs_order_action', 'arvrs_nonce'); ?>
          <button class="button button-primary"><?php esc_html_e('تلاش دوباره راه‌اندازی', 'arvan-reseller'); ?></button>
        </form>
      <?php endif; ?>
      <?php if (in_array($order['status'], ['paid', 'active', 'provision_failed'], true)) : ?>
        <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
              onsubmit="return confirm('<?php echo esc_js(__('مبلغ سفارش به کیف پول مشتری برگردانده می‌شود. ادامه می‌دهید؟', 'arvan-reseller')); ?>')">
          <input type="hidden" name="action" value="arvrs_order_action" /><input type="hidden" name="do" value="refund" />
          <input type="hidden" name="order_id" value="<?php echo esc_attr($order['id']); ?>" />
          <?php wp_nonce_field('arvrs_order_action', 'arvrs_nonce'); ?>
          <button class="button"><?php esc_html_e('بازپرداخت به کیف پول', 'arvan-reseller'); ?></button>
        </form>
      <?php endif; ?>
      <?php if (in_array($order['status'], ['pending_payment', 'payment_processing'], true)) : ?>
        <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_order_action" /><input type="hidden" name="do" value="cancel" />
          <input type="hidden" name="order_id" value="<?php echo esc_attr($order['id']); ?>" />
          <?php wp_nonce_field('arvrs_order_action', 'arvrs_nonce'); ?>
          <button class="button"><?php esc_html_e('لغو سفارش', 'arvan-reseller'); ?></button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('تاریخچه وضعیت', 'arvan-reseller'); ?></h2>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('از', 'arvan-reseller'); ?></th><th><?php esc_html_e('به', 'arvan-reseller'); ?></th><th><?php esc_html_e('عامل', 'arvan-reseller'); ?></th><th><?php esc_html_e('یادداشت', 'arvan-reseller'); ?></th><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php foreach ($events as $event) : ?>
        <tr>
          <td dir="ltr"><?php echo esc_html($event['from_status']); ?></td>
          <td dir="ltr"><?php echo esc_html($event['to_status']); ?></td>
          <td dir="ltr"><?php echo esc_html($event['actor']); ?></td>
          <td dir="ltr"><?php echo esc_html($event['note']); ?></td>
          <td class="arvrs-kv-detail"><?php echo esc_html($event['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
