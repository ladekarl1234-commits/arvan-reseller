<?php
/**
 * Sandbox gateway page. Simulates the external payment provider: the
 * "successful payment" button posts a signed proof to the public callback
 * endpoint — and the replay button posts the SAME proof again to prove
 * duplicate-callback safety live (spec §11).
 * @var string|null $error @var string $ref @var string $type @var int $amount
 * @var string $title @var bool $payable @var string $proof @var string $gateway
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

include __DIR__ . '/partials/shell-top.php';
?>
<div class="arvrs-payment-wrap">
  <?php if (!empty($error)) : ?>
    <div class="arvrs-alert arvrs-alert-danger" role="alert"><strong><?php echo esc_html($error); ?></strong></div>
  <?php elseif (!$payable) : ?>
    <div class="arvrs-alert arvrs-alert-info" role="status">
      <strong><?php esc_html_e('این تراکنش قبلاً پردازش شده است.', 'arvan-reseller'); ?></strong>
      <a class="arvrs-btn arvrs-btn-secondary" href="<?php echo esc_url($urls['dashboard']); ?>"><?php esc_html_e('رفتن به پیشخوان', 'arvan-reseller'); ?></a>
    </div>
  <?php else : ?>
    <div class="arvrs-card arvrs-gateway-card" id="arvrs-gateway"
         data-ref="<?php echo esc_attr($ref); ?>" data-type="<?php echo esc_attr($type); ?>" data-proof="<?php echo esc_attr($proof); ?>">
      <div class="arvrs-gateway-head">
        <span class="arvrs-tag arvrs-tag-warning"><?php echo esc_html($gateway); ?></span>
        <h1 class="arvrs-card-title"><?php echo esc_html($title); ?></h1>
      </div>
      <dl class="arvrs-kv">
        <div><dt><?php esc_html_e('شناسه پرداخت', 'arvan-reseller'); ?></dt><dd dir="ltr"><?php echo esc_html($ref); ?></dd></div>
        <div><dt><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></dt><dd><strong><?php echo esc_html(Helpers::money($amount)); ?></strong></dd></div>
      </dl>

      <div class="arvrs-gateway-actions">
        <button class="arvrs-btn arvrs-btn-primary arvrs-btn-block" id="arvrs-pay-ok">
          <?php esc_html_e('پرداخت موفق (شبیه‌سازی)', 'arvan-reseller'); ?>
        </button>
        <button class="arvrs-btn arvrs-btn-ghost arvrs-btn-block" id="arvrs-pay-fail">
          <?php esc_html_e('انصراف / پرداخت ناموفق', 'arvan-reseller'); ?>
        </button>
      </div>

      <div id="arvrs-pay-progress" class="arvrs-pay-progress" hidden>
        <div class="arvrs-spinner" aria-hidden="true"></div>
        <p id="arvrs-pay-status" role="status"><?php esc_html_e('در حال تأیید پرداخت…', 'arvan-reseller'); ?></p>
      </div>

      <div id="arvrs-pay-done" hidden>
        <div class="arvrs-alert arvrs-alert-success" role="status">
          <strong><?php esc_html_e('پرداخت تأیید شد؛ سرویس شما آماده است.', 'arvan-reseller'); ?></strong>
          <p id="arvrs-pay-message"></p>
        </div>
        <div class="arvrs-gateway-actions">
          <a class="arvrs-btn arvrs-btn-primary arvrs-btn-block" href="<?php echo esc_url(add_query_arg('tab', 'services', $urls['dashboard'])); ?>">
            <?php esc_html_e('مشاهده سرویس در پیشخوان', 'arvan-reseller'); ?>
          </a>
          <button class="arvrs-btn arvrs-btn-ghost arvrs-btn-block" id="arvrs-pay-replay"
                  title="<?php esc_attr_e('نمایش ایمنی در برابر کال‌بک تکراری', 'arvan-reseller'); ?>">
            <?php esc_html_e('ارسال دوباره کال‌بک (تست ایمنی تکرار)', 'arvan-reseller'); ?>
          </button>
        </div>
        <p class="arvrs-field-hint" id="arvrs-replay-result" role="status"></p>
      </div>
    </div>
    <p class="arvrs-field-hint arvrs-center">
      <?php esc_html_e('این صفحه شبیه‌ساز درگاه پرداخت است؛ در حالت واقعی، درگاه بانکی جایگزین می‌شود.', 'arvan-reseller'); ?>
    </p>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
