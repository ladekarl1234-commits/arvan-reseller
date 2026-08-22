<?php
/**
 * @var float $global_markup @var array $product_markup @var int $fixed_adjustment
 * @var array $base_costs @var array $unsellable @var bool $demo @var array $products
 */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

$post_url = admin_url('admin-post.php');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('قیمت‌گذاری', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <?php if (!empty($unsellable)) : ?>
    <div class="notice notice-warning">
      <p><strong><?php echo esc_html(sprintf(
          __('%s پلن هزینه پایه ندارند و به همین دلیل در فروشگاه نمایش داده نمی‌شوند و قابل خرید نیستند.', 'arvan-reseller'),
          Helpers::fa_digits((string) count($unsellable))
      )); ?></strong></p>
      <p class="arvrs-help"><?php esc_html_e('برای هرکدام هزینه پایه ماهانه ArvanCloud را در جدول پایین وارد کنید. تا آن زمان، قیمت مشتری قابل محاسبه نیست و سفارش با خطای «بدون قیمت» رد می‌شود.', 'arvan-reseller'); ?></p>
      <ul>
        <?php foreach ($unsellable as $row) : ?>
          <li><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?> — <code dir="ltr"><?php echo esc_html($row['plan_id']); ?></code></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('درون‌ریزی پلن‌های آروان', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('شناسه پلن‌های واقعی آروان (flavor ids) با شناسه‌های نمونه یکی نیستند، بنابراین پس از اتصال واقعی، فهرست پلن‌ها را از آروان بگیرید تا ردیف قیمت‌گذاری هرکدام ساخته شود؛ سپس فقط هزینه پایه را وارد کنید.', 'arvan-reseller'); ?></p>
    <?php if ($demo) : ?>
      <p class="arvrs-help"><?php esc_html_e('در حالت دمو، پلن‌ها از کاتالوگ نمونه می‌آیند و درون‌ریزی لازم نیست.', 'arvan-reseller'); ?></p>
    <?php endif; ?>
    <div class="arvrs-actions-row">
      <?php foreach ($products as $product) : ?>
        <form class="arvrs-inline-form" method="post" action="<?php echo esc_url($post_url); ?>">
          <input type="hidden" name="action" value="arvrs_import_plans" />
          <input type="hidden" name="product" value="<?php echo esc_attr($product); ?>" />
          <?php wp_nonce_field('arvrs_import_plans', 'arvrs_nonce'); ?>
          <button class="button"><?php echo esc_html(sprintf(__('درون‌ریزی پلن‌های %s', 'arvan-reseller'), Catalog::product_label($product))); ?></button>
        </form>
      <?php endforeach; ?>
    </div>
  </div>

  <form method="post" action="<?php echo esc_url($post_url); ?>">
    <input type="hidden" name="action" value="arvrs_save_pricing" />
    <?php wp_nonce_field('arvrs_save_pricing', 'arvrs_nonce'); ?>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('قواعد سود', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('قیمت مشتری = هزینه پایه × (۱ + درصد سود) + تعدیل ثابت. اولویت: قاعده مشتری ← قاعده محصول ← قاعده سراسری.', 'arvan-reseller'); ?></p>
      <div class="arvrs-form-grid">
        <div><label for="arvrs-global-markup"><?php esc_html_e('درصد سود سراسری', 'arvan-reseller'); ?></label>
          <input id="arvrs-global-markup" type="number" step="0.5" min="-100" name="global_markup" value="<?php echo esc_attr($global_markup); ?>" /></div>
        <?php foreach (Catalog::PRODUCTS as $product) : ?>
          <div><label for="arvrs-markup-<?php echo esc_attr($product); ?>"><?php echo esc_html(sprintf(__('درصد سود %s (خالی = سراسری)', 'arvan-reseller'), Catalog::product_label($product))); ?></label>
            <input id="arvrs-markup-<?php echo esc_attr($product); ?>" type="number" step="0.5" min="-100" name="product_markup[<?php echo esc_attr($product); ?>]" value="<?php echo esc_attr($product_markup[$product] ?? ''); ?>" /></div>
        <?php endforeach; ?>
        <div><label for="arvrs-fixed-global"><?php esc_html_e('تعدیل ثابت سراسری (تومان)', 'arvan-reseller'); ?></label>
          <input id="arvrs-fixed-global" type="number" name="fixed_adjustment" value="<?php echo esc_attr($fixed_adjustment); ?>" /></div>
      </div>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('هزینه‌های پایه ArvanCloud', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('آروان API عمومی برای قیمت ندارد؛ این مقادیر از صفحه قیمت‌گذاری آروان (arvancloud.ir/fa/pricing) نگه‌داری و به‌روزرسانی می‌شوند. ستون «منبع» تاریخ آخرین به‌روزرسانی را نشان می‌دهد — هرچه قدیمی‌تر، سود گزارش‌شده کم‌دقت‌تر.', 'arvan-reseller'); ?></p>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('پلن', 'arvan-reseller'); ?></th><th><?php esc_html_e('هزینه پایه ماهانه (تومان)', 'arvan-reseller'); ?></th><th><?php esc_html_e('قیمت مشتری (با سود فعلی)', 'arvan-reseller'); ?></th><th><?php esc_html_e('منبع', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($base_costs as $row) :
            $markup = isset($product_markup[$row['product']]) && $product_markup[$row['product']] !== '' ? (float) $product_markup[$row['product']] : $global_markup;
            $customer_price = (int) round((int) $row['base_cost'] * (1 + $markup / 100)) + $fixed_adjustment;
            $field_id = 'arvrs-cost-' . sanitize_html_class($row['product'] . '-' . $row['plan_id']);
            $updated  = strtotime((string) $row['updated_at'] . ' UTC');
            // Only claim staleness when the date actually parses — an
            // unreadable timestamp is not evidence of an old price.
            $stale    = $updated && $updated < time() - 180 * DAY_IN_SECONDS;
            ?>
          <tr>
            <td><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?></td>
            <td><label for="<?php echo esc_attr($field_id); ?>"><code dir="ltr"><?php echo esc_html($row['plan_id']); ?></code></label></td>
            <td><input id="<?php echo esc_attr($field_id); ?>" type="number" name="base_cost[<?php echo esc_attr($row['product']); ?>][<?php echo esc_attr($row['plan_id']); ?>]" value="<?php echo esc_attr($row['base_cost']); ?>" min="0" step="10000" /></td>
            <td><?php if ((int) $row['base_cost'] <= 0) : ?>
                <span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('غیرقابل فروش', 'arvan-reseller'); ?></span>
              <?php else : ?>
                <?php echo esc_html(Helpers::money(max(0, $customer_price))); ?>
              <?php endif; ?></td>
            <td class="arvrs-kv-detail"><?php echo esc_html($row['source'] . ' — ' . $row['updated_at']); ?>
              <?php if ($stale) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('قدیمی', 'arvan-reseller'); ?></span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="arvrs-panel">
      <h2><?php esc_html_e('افزودن پلن به‌صورت دستی', 'arvan-reseller'); ?></h2>
      <div class="arvrs-form-grid">
        <div><label for="arvrs-new-product"><?php esc_html_e('محصول', 'arvan-reseller'); ?></label>
          <select id="arvrs-new-product" name="new_product">
            <?php foreach (Catalog::PRODUCTS as $product) : ?><option value="<?php echo esc_attr($product); ?>"><?php echo esc_html(Catalog::product_label($product)); ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="arvrs-new-plan"><?php esc_html_e('شناسه پلن (flavor id آروان)', 'arvan-reseller'); ?></label>
          <input id="arvrs-new-plan" type="text" name="new_plan_id" dir="ltr" /></div>
        <div><label for="arvrs-new-cost"><?php esc_html_e('هزینه پایه ماهانه (تومان)', 'arvan-reseller'); ?></label>
          <input id="arvrs-new-cost" type="number" name="new_base_cost" min="0" step="10000" /></div>
      </div>
    </div>

    <p><button class="button button-primary button-hero"><?php esc_html_e('ذخیره قیمت‌گذاری', 'arvan-reseller'); ?></button></p>
  </form>
</div>
