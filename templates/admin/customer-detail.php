<?php
/**
 * Full commercial & operational state of one customer.
 * @var \WP_User|false $customer @var array $balance @var string $stage
 * @var array|null $rules @var array $orders @var array $services @var array $ledger
 */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

if (!$customer) {
    echo '<div class="wrap"><h1>' . esc_html__('مشتری یافت نشد', 'arvan-reseller') . '</h1></div>';
    return;
}
$cid = (int) $customer->ID;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php echo esc_html($customer->display_name); ?>
    <?php echo Helpers::status_tag($stage); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
  </h1>
  <p class="arvrs-kv-detail" dir="ltr"><?php echo esc_html($customer->user_email); ?></p>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <div class="arvrs-cards">
    <div class="arvrs-acard <?php echo $balance['available'] < 0 ? 'is-danger' : 'is-success'; ?>"><span class="label"><?php esc_html_e('اعتبار قابل استفاده', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $balance['available'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('مجموع شارژ', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $balance['topup_total'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('مجموع مصرف', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $balance['consumed'])); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سرویس‌ها', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) count($services))); ?></span></div>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('قوانین اختصاصی این مشتری', 'arvan-reseller'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_rules_save" />
      <input type="hidden" name="customer_id" value="<?php echo esc_attr($cid); ?>" />
      <?php wp_nonce_field('arvrs_rules_save', 'arvrs_nonce'); ?>
      <div class="arvrs-form-grid">
        <div><label><?php esc_html_e('درصد سود اختصاصی (خالی = پیش‌فرض)', 'arvan-reseller'); ?></label>
          <input type="number" step="0.5" name="markup_percent" value="<?php echo esc_attr($rules['markup_percent'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('درصد تخفیف', 'arvan-reseller'); ?></label>
          <input type="number" step="0.5" min="0" max="100" name="discount_percent" value="<?php echo esc_attr($rules['discount_percent'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('تعدیل ثابت (تومان)', 'arvan-reseller'); ?></label>
          <input type="number" name="fixed_adjustment" value="<?php echo esc_attr($rules['fixed_adjustment'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('سقف اعتبار منفی (تومان)', 'arvan-reseller'); ?></label>
          <input type="number" name="credit_limit" value="<?php echo esc_attr($rules['credit_limit'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('سقف خرید (تومان)', 'arvan-reseller'); ?></label>
          <input type="number" name="spending_limit" value="<?php echo esc_attr($rules['spending_limit'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('مهلت اختصاصی (روز)', 'arvan-reseller'); ?></label>
          <input type="number" name="grace_days" value="<?php echo esc_attr($rules['grace_days'] ?? ''); ?>" /></div>
        <div><label><?php esc_html_e('وضعیت حساب', 'arvan-reseller'); ?></label>
          <select name="status">
            <option value="active" <?php selected(($rules['status'] ?? 'active'), 'active'); ?>><?php esc_html_e('فعال', 'arvan-reseller'); ?></option>
            <option value="blocked" <?php selected(($rules['status'] ?? ''), 'blocked'); ?>><?php esc_html_e('مسدود (خرید جدید ممنوع)', 'arvan-reseller'); ?></option>
          </select></div>
        <div><label><?php esc_html_e('محصولات مجاز (هیچ‌کدام = همه)', 'arvan-reseller'); ?></label>
          <?php $allowed = array_filter(explode(',', (string) ($rules['allowed_products'] ?? ''))); ?>
          <?php foreach (Catalog::PRODUCTS as $product) : ?>
            <label style="display:inline-flex;gap:4px;margin-inline-end:10px;font-weight:400">
              <input type="checkbox" name="allowed_products[]" value="<?php echo esc_attr($product); ?>" <?php checked(in_array($product, $allowed, true)); ?> />
              <?php echo esc_html(Catalog::product_label($product)); ?>
            </label>
          <?php endforeach; ?></div>
        <div style="grid-column:1/-1"><label><?php esc_html_e('یادداشت', 'arvan-reseller'); ?></label>
          <textarea name="notes" rows="2"><?php echo esc_textarea($rules['notes'] ?? ''); ?></textarea></div>
      </div>
      <p><button class="button button-primary"><?php esc_html_e('ذخیره قوانین', 'arvan-reseller'); ?></button></p>
    </form>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('تراکنش دستی کیف پول', 'arvan-reseller'); ?></h2>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="arvrs-actions-row">
      <input type="hidden" name="action" value="arvrs_wallet_adjust" />
      <input type="hidden" name="customer_id" value="<?php echo esc_attr($cid); ?>" />
      <?php wp_nonce_field('arvrs_wallet_adjust', 'arvrs_nonce'); ?>
      <select name="kind">
        <option value="promo_credit"><?php esc_html_e('اعتبار هدیه (+)', 'arvan-reseller'); ?></option>
        <option value="refund"><?php esc_html_e('بازپرداخت (+)', 'arvan-reseller'); ?></option>
        <option value="adjustment"><?php esc_html_e('کسر اصلاحی (−)', 'arvan-reseller'); ?></option>
      </select>
      <input type="number" name="amount" min="1000" step="1000" placeholder="<?php esc_attr_e('مبلغ (تومان)', 'arvan-reseller'); ?>" required />
      <input type="text" name="note" placeholder="<?php esc_attr_e('توضیح', 'arvan-reseller'); ?>" />
      <button class="button"><?php esc_html_e('ثبت', 'arvan-reseller'); ?></button>
    </form>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('سرویس‌ها', 'arvan-reseller'); ?></h2>
    <table class="widefat striped">
      <thead><tr><th>#</th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('شناسه ابری', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('ایجاد', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php if (empty($services)) : ?><tr><td colspan="5"><?php esc_html_e('سرویسی ندارد.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($services as $service) : ?>
        <tr>
          <td><?php echo esc_html($service['id']); ?></td>
          <td><?php echo esc_html(Catalog::product_label((string) $service['product'])); ?> <code dir="ltr"><?php echo esc_html($service['plan_id']); ?></code></td>
          <td><code dir="ltr"><?php echo esc_html($service['remote_id']); ?></code></td>
          <td><?php echo Helpers::status_tag((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
          <td class="arvrs-kv-detail"><?php echo esc_html($service['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('گردش حساب اخیر', 'arvan-reseller'); ?></h2>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('مرجع', 'arvan-reseller'); ?></th><th><?php esc_html_e('شرح', 'arvan-reseller'); ?></th><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php if (empty($ledger)) : ?><tr><td colspan="5"><?php esc_html_e('تراکنشی ندارد.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($ledger as $entry) : ?>
        <tr>
          <td dir="ltr"><?php echo esc_html($entry['type']); ?></td>
          <td style="color:<?php echo $entry['direction'] === 'credit' ? '#16a34a' : '#dc2626'; ?>;font-weight:600">
            <?php echo esc_html(($entry['direction'] === 'credit' ? '+' : '−') . ' ' . Helpers::money((int) $entry['amount'])); ?></td>
          <td><code dir="ltr"><?php echo esc_html($entry['ref_type'] . ':' . $entry['ref_id']); ?></code></td>
          <td><?php echo esc_html($entry['description']); ?></td>
          <td class="arvrs-kv-detail"><?php echo esc_html($entry['created_at']); ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
