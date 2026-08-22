<?php
/**
 * Financial reporting with a real time dimension. Vars from Menu::reports().
 *
 * @var string $preset @var string $from @var string $to @var bool $demo
 * @var array $period @var array $by_product @var array $monthly @var int $mrr
 * @var float $churn @var array $services @var string $month_key @var int $invoice
 */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

$base_url = admin_url('admin.php?page=arvan-reseller-reports');
$presets  = [
    'this_month' => __('این ماه', 'arvan-reseller'),
    'last_month' => __('ماه گذشته', 'arvan-reseller'),
    'last_90'    => __('۹۰ روز اخیر', 'arvan-reseller'),
    'year'       => __('از ابتدای سال میلادی', 'arvan-reseller'),
];
$invoice_delta = $invoice > 0 ? ((int) $period['cost'] - $invoice) : 0;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('گزارش مالی', 'arvan-reseller'); ?>
    <?php if ($demo) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('شامل داده‌های دمو', 'arvan-reseller'); ?></span><?php endif; ?>
  </h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <?php foreach ($presets as $key => $label) : ?>
      <a class="button <?php echo $preset === $key ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('period', $key, $base_url)); ?>"><?php echo esc_html($label); ?></a>
    <?php endforeach; ?>
  </div>

  <form method="get" class="arvrs-form-grid" style="margin-bottom:12px">
    <input type="hidden" name="page" value="arvan-reseller-reports" />
    <input type="hidden" name="period" value="custom" />
    <div>
      <label class="arvrs-lbl" for="arvrs-from"><?php esc_html_e('از تاریخ (میلادی)', 'arvan-reseller'); ?></label>
      <input id="arvrs-from" type="date" name="from" value="<?php echo esc_attr(substr($from, 0, 10)); ?>" />
    </div>
    <div>
      <label class="arvrs-lbl" for="arvrs-to"><?php esc_html_e('تا تاریخ (میلادی)', 'arvan-reseller'); ?></label>
      <input id="arvrs-to" type="date" name="to" value="<?php echo esc_attr(substr($to, 0, 10)); ?>" />
    </div>
    <div>
      <label class="arvrs-lbl" for="arvrs-apply-period"><?php esc_html_e('اعمال بازه دلخواه', 'arvan-reseller'); ?></label>
      <button id="arvrs-apply-period" class="button"><?php esc_html_e('نمایش', 'arvan-reseller'); ?></button>
    </div>
  </form>

  <p class="arvrs-kv-detail"><?php echo esc_html(sprintf(
      __('بازه: %1$s تا %2$s', 'arvan-reseller'),
      Helpers::jdate($from, 'j F Y'),
      Helpers::jdate($to, 'j F Y')
  )); ?></p>

  <div class="arvrs-cards">
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('درآمد', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $period['revenue'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('هزینه پایه (برآوردی)', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $period['cost'])); ?></span></div>
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('سود ناخالص', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $period['margin'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سفارش‌ها', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (int) $period['orders'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سرویس‌های جدید', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (int) $period['services'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('MRR', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($mrr)); ?></span><span class="sub"><?php esc_html_e('مجموع تمدید سرویس‌های فعال، نرمال‌شده به ۳۰ روز', 'arvan-reseller'); ?></span></div>
    <div class="arvrs-acard <?php echo $churn > 0.05 ? 'is-danger' : ''; ?>"><span class="label"><?php esc_html_e('ریزش (Churn)', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits(number_format($churn * 100, 1)) . '٪'); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سرویس‌های فعال', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (int) (($services['active'] ?? 0) + ($services['at_risk'] ?? 0)))); ?></span></div>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('تطبیق با صورت‌حساب واقعی آروان', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('«هزینه پایه» عددی است که خودتان در صفحه قیمت‌گذاری وارد کرده‌اید، نه مبلغی که واقعاً به آروان پرداخته‌اید. مبلغ صورت‌حساب هر ماه را اینجا ثبت کنید تا اختلاف سود واقعی و برآوردی دیده شود.', 'arvan-reseller'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arvrs-form-grid">
      <input type="hidden" name="action" value="arvrs_invoice_save" />
      <input type="hidden" name="month" value="<?php echo esc_attr($month_key); ?>" />
      <?php wp_nonce_field('arvrs_invoice_save', 'arvrs_nonce'); ?>
      <div>
        <label class="arvrs-lbl" for="arvrs-invoice"><?php echo esc_html(sprintf(__('صورت‌حساب آروان برای %s (تومان)', 'arvan-reseller'), $month_key)); ?></label>
        <input id="arvrs-invoice" type="number" name="invoice_amount" min="0" step="1000" value="<?php echo esc_attr($invoice); ?>" />
      </div>
      <div>
        <label class="arvrs-lbl" for="arvrs-invoice-save"><?php esc_html_e('ثبت', 'arvan-reseller'); ?></label>
        <button id="arvrs-invoice-save" class="button"><?php esc_html_e('ذخیره صورت‌حساب', 'arvan-reseller'); ?></button>
      </div>
    </form>
    <?php if ($invoice > 0) : ?>
      <p>
        <?php echo esc_html(sprintf(
            __('هزینه برآوردی %1$s در برابر صورت‌حساب %2$s — اختلاف: %3$s', 'arvan-reseller'),
            Helpers::money((int) $period['cost']),
            Helpers::money($invoice),
            Helpers::money($invoice_delta)
        )); ?>
        <?php if ($invoice_delta < 0) : ?>
          <span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('هزینه واقعی بیشتر از برآورد است — درصد سود را بازبینی کنید', 'arvan-reseller'); ?></span>
        <?php endif; ?>
      </p>
    <?php endif; ?>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('به تفکیک محصول', 'arvan-reseller'); ?></h2>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('سفارش‌ها', 'arvan-reseller'); ?></th><th><?php esc_html_e('درآمد', 'arvan-reseller'); ?></th><th><?php esc_html_e('هزینه', 'arvan-reseller'); ?></th><th><?php esc_html_e('سود', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php if (empty($by_product)) : ?><tr><td colspan="5"><?php esc_html_e('در این بازه فروشی ثبت نشده است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($by_product as $row) : ?>
        <tr>
          <td><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?></td>
          <td><?php echo esc_html(Helpers::fa_digits((string) (int) $row['orders'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['revenue'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['cost'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['margin'])); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('روند ۱۲ ماه اخیر', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('ماه‌های بدون فروش با صفر نمایش داده می‌شوند — افت درآمد باید دیده شود، نه حذف.', 'arvan-reseller'); ?></p>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('ماه', 'arvan-reseller'); ?></th><th><?php esc_html_e('سفارش‌ها', 'arvan-reseller'); ?></th><th><?php esc_html_e('درآمد', 'arvan-reseller'); ?></th><th><?php esc_html_e('هزینه', 'arvan-reseller'); ?></th><th><?php esc_html_e('سود', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php foreach ($monthly as $key => $row) : ?>
        <tr>
          <td dir="ltr"><?php echo esc_html($key); ?></td>
          <td><?php echo esc_html(Helpers::fa_digits((string) (int) $row['orders'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['revenue'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['cost'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['margin'])); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
