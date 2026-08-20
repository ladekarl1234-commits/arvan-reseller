<?php
/**
 * Sandbox gateway page (ابرآروان design system). Standalone centered card.
 * @var string|null $error @var string $ref @var string $type @var int $amount
 * @var string $title @var bool $payable @var string $proof @var string $gateway
 * @var array $urls @var string $brand_name @var string $brand_logo
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$brand_initial = function_exists('mb_substr') ? mb_substr($brand_name, 0, 1, 'UTF-8') : substr($brand_name, 0, 1);
?>
<div class="arvrs-app" dir="rtl">
  <div class="arvrs-payment-wrap">
    <a class="arvrs-payment-brand" href="<?php echo esc_url($urls['storefront']); ?>">
      <span class="arvrs-brand-mark">
        <?php if ($brand_logo) : ?><img src="<?php echo esc_url($brand_logo); ?>" alt="" /><?php else : ?><?php echo esc_html($brand_initial ?: 'ا'); ?><?php endif; ?>
      </span>
      <span class="arvrs-brand-name"><?php echo esc_html($brand_name); ?></span>
    </a>

    <?php if (!empty($error)) : ?>
      <div class="arvrs-gateway" style="padding:24px"><div class="arvrs-alert arvrs-alert-danger" role="alert" style="margin:0"><span class="arvrs-alert-mark">!</span><strong class="arvrs-alert-body"><?php echo esc_html($error); ?></strong></div></div>
    <?php elseif (!$payable) : ?>
      <div class="arvrs-gateway" style="padding:24px">
        <div class="arvrs-alert arvrs-alert-info" role="status" style="margin:0 0 14px"><span class="arvrs-alert-mark">i</span><strong class="arvrs-alert-body"><?php esc_html_e('این تراکنش قبلاً پردازش شده است.', 'arvan-reseller'); ?></strong></div>
        <a class="arvrs-btn arvrs-btn-primary arvrs-btn-block" href="<?php echo esc_url($urls['dashboard']); ?>"><?php esc_html_e('رفتن به پیشخوان', 'arvan-reseller'); ?></a>
      </div>
    <?php else : ?>
      <div class="arvrs-gateway" id="arvrs-gateway"
           data-ref="<?php echo esc_attr($ref); ?>" data-type="<?php echo esc_attr($type); ?>" data-proof="<?php echo esc_attr($proof); ?>">
        <div class="arvrs-gateway-head">
          <span class="arvrs-gateway-tag"><?php echo esc_html($gateway); ?></span>
          <h1><?php echo esc_html($title); ?></h1>
        </div>
        <div class="arvrs-gateway-body">
          <div class="arvrs-kv-grid">
            <div class="arvrs-kv-cell"><span><?php esc_html_e('شناسه پرداخت', 'arvan-reseller'); ?></span><strong dir="ltr"><?php echo esc_html($ref); ?></strong></div>
            <div class="arvrs-kv-cell"><span><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></span><strong class="is-amount"><?php echo esc_html(Helpers::money($amount)); ?></strong></div>
          </div>

          <div class="arvrs-gateway-actions">
            <button class="arvrs-btn arvrs-btn-primary" id="arvrs-pay-ok"><?php esc_html_e('پرداخت موفق (شبیه‌سازی)', 'arvan-reseller'); ?></button>
            <button class="arvrs-btn arvrs-btn-ghost" id="arvrs-pay-fail"><?php esc_html_e('انصراف / پرداخت ناموفق', 'arvan-reseller'); ?></button>
          </div>

          <div id="arvrs-pay-progress" class="arvrs-pay-progress" hidden>
            <span class="arvrs-spinner" aria-hidden="true"></span>
            <p id="arvrs-pay-status" role="status" style="margin:0;font-weight:600"><?php esc_html_e('در حال تأیید پرداخت…', 'arvan-reseller'); ?></p>
          </div>

          <div id="arvrs-pay-done" hidden style="margin-top:14px">
            <div class="arvrs-alert arvrs-alert-success" role="status">
              <span class="arvrs-alert-mark">✓</span>
              <div class="arvrs-alert-body">
                <strong><?php esc_html_e('پرداخت تأیید شد؛ سرویس شما آماده است.', 'arvan-reseller'); ?></strong>
                <p id="arvrs-pay-message" style="margin:2px 0 0;font-size:13px"></p>
              </div>
            </div>
            <div class="arvrs-gateway-actions" style="margin-top:12px">
              <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url(add_query_arg('tab', 'services', $urls['dashboard'])); ?>"><?php esc_html_e('مشاهده سرویس در پیشخوان', 'arvan-reseller'); ?></a>
              <button class="arvrs-pay-replay" id="arvrs-pay-replay" title="<?php esc_attr_e('نمایش ایمنی در برابر کال‌بک تکراری', 'arvan-reseller'); ?>"><?php esc_html_e('ارسال دوباره کال‌بک (تست ایمنی تکرار)', 'arvan-reseller'); ?></button>
            </div>
            <p class="arvrs-field-hint" id="arvrs-replay-result" role="status" style="color:var(--arvrs-brand-ink);font-weight:600"></p>
          </div>
        </div>
      </div>
      <p class="arvrs-field-hint arvrs-center"><?php esc_html_e('این صفحه شبیه‌ساز درگاه پرداخت است؛ در حالت واقعی، درگاه بانکی جایگزین می‌شود.', 'arvan-reseller'); ?></p>
    <?php endif; ?>
  </div>
</div>
