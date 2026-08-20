<?php
/** @var array $orders @var array $urls */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

include __DIR__ . '/partials/shell-top.php';
?>
<h1 class="arvrs-page-title"><?php esc_html_e('سفارش‌های در انتظار پرداخت', 'arvan-reseller'); ?></h1>

<?php if (empty($orders)) : ?>
  <div class="arvrs-card arvrs-center arvrs-empty">
    <p class="arvrs-muted"><?php esc_html_e('سفارش در انتظار پرداختی ندارید.', 'arvan-reseller'); ?></p>
    <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('مشاهده فروشگاه', 'arvan-reseller'); ?></a>
  </div>
<?php else : ?>
  <div class="arvrs-stack">
    <?php foreach ($orders as $order) : ?>
      <div class="arvrs-card arvrs-row-card">
        <div>
          <strong><?php echo esc_html(sprintf(__('سفارش #%d', 'arvan-reseller'), (int) $order['id'])); ?></strong>
          <span class="arvrs-muted"> — <?php echo esc_html(Catalog::product_label((string) $order['product'])); ?> (<?php echo esc_html($order['plan_id']); ?>)</span>
          <div class="arvrs-muted"><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></div>
        </div>
        <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($order['pay_url']); ?>"><?php esc_html_e('پرداخت', 'arvan-reseller'); ?></a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
