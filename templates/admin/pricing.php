<?php
/** @var float $global_markup @var array $product_markup @var int $fixed_adjustment @var array $base_costs */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('قیمت‌گذاری', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="arvrs_save_pricing" />
    <?php wp_nonce_field('arvrs_save_pricing', 'arvrs_nonce'); ?>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('قواعد سود', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('قیمت مشتری = هزینه پایه × (۱ + درصد سود) + تعدیل ثابت. اولویت: قاعده مشتری ← قاعده محصول ← قاعده سراسری.', 'arvan-reseller'); ?></p>
      <div class="arvrs-form-grid">
        <div><label><?php esc_html_e('درصد سود سراسری', 'arvan-reseller'); ?></label>
          <input type="number" step="0.5" min="-100" name="global_markup" value="<?php echo esc_attr($global_markup); ?>" /></div>
        <?php foreach (Catalog::PRODUCTS as $product) : ?>
          <div><label><?php echo esc_html(sprintf(__('درصد سود %s (خالی = سراسری)', 'arvan-reseller'), Catalog::product_label($product))); ?></label>
            <input type="number" step="0.5" min="-100" name="product_markup[<?php echo esc_attr($product); ?>]" value="<?php echo esc_attr($product_markup[$product] ?? ''); ?>" /></div>
        <?php endforeach; ?>
        <div><label><?php esc_html_e('تعدیل ثابت سراسری (تومان)', 'arvan-reseller'); ?></label>
          <input type="number" name="fixed_adjustment" value="<?php echo esc_attr($fixed_adjustment); ?>" /></div>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('هزینه‌های پایه ArvanCloud', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('آروان API عمومی برای قیمت ندارد؛ این مقادیر از صفحه قیمت‌گذاری آروان (arvancloud.ir/fa/pricing) نگه‌داری و به‌روزرسانی می‌شوند. ستون «منبع» تاریخ آخرین به‌روزرسانی را نشان می‌دهد.', 'arvan-reseller'); ?></p>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('پلن', 'arvan-reseller'); ?></th><th><?php esc_html_e('هزینه پایه ماهانه (تومان)', 'arvan-reseller'); ?></th><th><?php esc_html_e('قیمت مشتری (با سود فعلی)', 'arvan-reseller'); ?></th><th><?php esc_html_e('منبع', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($base_costs as $row) :
            $markup = isset($product_markup[$row['product']]) && $product_markup[$row['product']] !== '' ? (float) $product_markup[$row['product']] : $global_markup;
            $customer_price = (int) round((int) $row['base_cost'] * (1 + $markup / 100)) + $fixed_adjustment;
            ?>
          <tr>
            <td><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?></td>
            <td><code dir="ltr"><?php echo esc_html($row['plan_id']); ?></code></td>
            <td><input type="number" name="base_cost[<?php echo esc_attr($row['product']); ?>][<?php echo esc_attr($row['plan_id']); ?>]" value="<?php echo esc_attr($row['base_cost']); ?>" min="0" step="10000" style="width:140px" /></td>
            <td><?php echo esc_html(Helpers::money(max(0, $customer_price))); ?></td>
            <td class="arvrs-kv-detail"><?php echo esc_html($row['source'] . ' — ' . $row['updated_at']); ?></td>
          </tr>
        <?php endforeach; ?>
        <tr>
          <td><select name="new_product">
            <?php foreach (Catalog::PRODUCTS as $product) : ?><option value="<?php echo esc_attr($product); ?>"><?php echo esc_html(Catalog::product_label($product)); ?></option><?php endforeach; ?>
          </select></td>
          <td><input type="text" name="new_plan_id" dir="ltr" placeholder="plan-id" /></td>
          <td><input type="number" name="new_base_cost" min="0" step="10000" style="width:140px" placeholder="<?php esc_attr_e('هزینه پایه', 'arvan-reseller'); ?>" /></td>
          <td colspan="2" class="arvrs-kv-detail"><?php esc_html_e('افزودن پلن جدید (مثلاً از فهرست flavors واقعی آروان)', 'arvan-reseller'); ?></td>
        </tr>
        </tbody>
      </table>
    </div>
    <p><button class="button button-primary button-hero"><?php esc_html_e('ذخیره قیمت‌گذاری', 'arvan-reseller'); ?></button></p>
  </form>
</div>
