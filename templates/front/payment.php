<?php
/**
 * Sandbox gateway page (ابرآروان design system). Standalone centered card.
 *
 * The result area renders from the server-reported provisioning state and from
 * nothing else — `active` is the ONLY branch allowed to say the service is
 * ready (EX-002). `pending` polls GET /orders/{id}/state, bounded, and then
 * degrades to a truthful "we will let you know" carrying the reference.
 *
 * @var string|null $error @var string $ref @var string $type @var int $amount
 * @var string $title @var bool $payable @var string $proof @var string $gateway
 * @var int $order_id @var array|null $provision
 * @var array $urls @var string $brand_name @var string $brand_logo
 * @var string $support_email @var string $support_phone
 */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$brand_initial = function_exists('mb_substr') ? mb_substr($brand_name, 0, 1, 'UTF-8') : substr($brand_name, 0, 1);
$arvrs_dir  = is_rtl() ? 'rtl' : 'ltr';
$arvrs_lang = str_replace('_', '-', get_locale());

$ref       = isset($ref) ? (string) $ref : '';
$type      = isset($type) ? (string) $type : 'order';
$order_id  = isset($order_id) ? (int) $order_id : 0;
$provision = isset($provision) && is_array($provision) ? $provision : null;
$payable   = !empty($payable);

$support_url = $support_email
    ? 'mailto:' . $support_email
    : add_query_arg('tab', 'orders', $urls['dashboard']);

/**
 * One panel body, used both for the server-rendered outcome (a customer coming
 * back to the link later) and for the client-rendered one after a callback.
 * `$state` is 'ready' | 'pending' | 'failed' | 'timeout'.
 */
$arvrs_panel = static function (string $state, string $message, bool $hidden) use ($ref, $type, $urls, $support_url, $support_phone) {
    $is_topup = $type === 'topup';
    $copy = [
        'ready' => [
            'kind'  => 'success',
            'mark'  => '✓',
            'title' => $is_topup
                ? __('پرداخت تأیید شد؛ اعتبار کیف پول شما افزایش یافت.', 'arvan-reseller')
                : __('پرداخت تأیید شد؛ سرویس شما آماده است.', 'arvan-reseller'),
        ],
        'pending' => [
            'kind'  => 'info',
            'mark'  => 'i',
            'title' => __('پرداخت تأیید شد؛ سرویس در حال راه‌اندازی است.', 'arvan-reseller'),
        ],
        'timeout' => [
            'kind'  => 'warning',
            'mark'  => 'i',
            'title' => __('پرداخت شما ثبت شد؛ راه‌اندازی سرویس هنوز ادامه دارد.', 'arvan-reseller'),
        ],
        'failed' => [
            'kind'  => 'danger',
            'mark'  => '!',
            'title' => __('پرداخت ثبت شد اما راه‌اندازی سرویس ناموفق بود.', 'arvan-reseller'),
        ],
    ];
    $c = $copy[$state];
    $default_message = [
        'pending' => __('چند لحظه صبر کنید؛ وضعیت را برای شما بررسی می‌کنیم.', 'arvan-reseller'),
        'timeout' => __('به‌محض آماده شدن سرویس، اعلان و ایمیل دریافت می‌کنید. نیازی به پرداخت دوباره نیست.', 'arvan-reseller'),
        'failed'  => __('مبلغ پرداختی شما محفوظ است. تیم پشتیبانی در جریان است؛ با کد پیگیری زیر می‌توانید وضعیت را دنبال کنید.', 'arvan-reseller'),
        'ready'   => '',
    ];
    ?>
    <div id="arvrs-panel-<?php echo esc_attr($state); ?>" <?php echo $hidden ? 'hidden' : ''; ?>
         data-arvrs-announce="<?php echo esc_attr($c['title']); ?>">
      <div class="arvrs-alert arvrs-alert-<?php echo esc_attr($c['kind']); ?>">
        <span class="arvrs-alert-mark" aria-hidden="true"><?php echo esc_html($c['mark']); ?></span>
        <div class="arvrs-alert-body">
          <strong tabindex="-1" data-arvrs-panel-heading><?php echo esc_html($c['title']); ?></strong>
          <p data-arvrs-panel-message><?php echo esc_html($message !== '' ? $message : $default_message[$state]); ?></p>
        </div>
      </div>

      <?php if ($state !== 'ready') : ?>
        <div class="arvrs-kv-grid">
          <div class="arvrs-kv-cell">
            <span><?php esc_html_e('کد پیگیری', 'arvan-reseller'); ?></span>
            <strong dir="ltr"><?php echo esc_html($ref); ?></strong>
          </div>
        </div>
      <?php endif; ?>

      <div class="arvrs-gateway-actions">
        <?php if ($state === 'ready' && $is_topup) : ?>
          <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url(add_query_arg('tab', 'wallet', $urls['dashboard'])); ?>"><?php esc_html_e('مشاهده کیف پول', 'arvan-reseller'); ?></a>
        <?php elseif ($state === 'ready') : ?>
          <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url(add_query_arg('tab', 'services', $urls['dashboard'])); ?>"><?php esc_html_e('مشاهده سرویس در پیشخوان', 'arvan-reseller'); ?></a>
        <?php else : ?>
          <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url(add_query_arg('tab', 'orders', $urls['dashboard'])); ?>"><?php esc_html_e('پیگیری سفارش در پیشخوان', 'arvan-reseller'); ?></a>
        <?php endif; ?>
        <?php if ($state === 'failed' || $state === 'timeout') : ?>
          <a class="arvrs-btn arvrs-btn-secondary" href="<?php echo esc_url($support_url); ?>"><?php esc_html_e('تماس با پشتیبانی', 'arvan-reseller'); ?></a>
          <?php if ($support_phone) : ?>
            <p class="arvrs-field-hint arvrs-center"><?php esc_html_e('تلفن پشتیبانی:', 'arvan-reseller'); ?> <span dir="ltr"><?php echo esc_html($support_phone); ?></span></p>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php
};
?>
<div class="arvrs-app" dir="<?php echo esc_attr($arvrs_dir); ?>" lang="<?php echo esc_attr($arvrs_lang); ?>">
  <div class="arvrs-payment-wrap">
    <a class="arvrs-payment-brand" href="<?php echo esc_url($urls['storefront']); ?>">
      <span class="arvrs-brand-mark">
        <?php if ($brand_logo) : ?><img src="<?php echo esc_url($brand_logo); ?>" alt="" /><?php else : ?><?php echo esc_html($brand_initial ?: 'ا'); ?><?php endif; ?>
      </span>
      <span class="arvrs-brand-name"><?php echo esc_html($brand_name); ?></span>
    </a>

    <?php if (!empty($error)) : ?>
      <div class="arvrs-gateway arvrs-gateway-pad">
        <div class="arvrs-alert arvrs-alert-danger arvrs-alert-flush" role="alert">
          <span class="arvrs-alert-mark" aria-hidden="true">!</span>
          <div class="arvrs-alert-body"><strong><?php echo esc_html($error); ?></strong></div>
        </div>
      </div>

    <?php elseif (!$payable) : ?>
      <?php // Already processed: show what actually became of it, not a shrug. ?>
      <div class="arvrs-gateway arvrs-gateway-pad">
        <?php if ($provision) : ?>
          <?php
          $state = $provision['state'] === 'active' ? 'ready' : ($provision['state'] === 'pending' ? 'pending' : 'failed');
          $arvrs_panel($state, (string) $provision['message'], false);
          ?>
        <?php else : ?>
          <div class="arvrs-alert arvrs-alert-info arvrs-alert-flush" role="status">
            <span class="arvrs-alert-mark" aria-hidden="true">i</span>
            <div class="arvrs-alert-body"><strong><?php esc_html_e('این تراکنش قبلاً پردازش شده است.', 'arvan-reseller'); ?></strong></div>
          </div>
          <div class="arvrs-gateway-actions">
            <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['dashboard']); ?>"><?php esc_html_e('رفتن به پیشخوان', 'arvan-reseller'); ?></a>
          </div>
        <?php endif; ?>
      </div>

    <?php else : ?>
      <div class="arvrs-gateway" id="arvrs-gateway"
           data-ref="<?php echo esc_attr($ref); ?>" data-type="<?php echo esc_attr($type); ?>"
           data-proof="<?php echo esc_attr($proof); ?>" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
        <div class="arvrs-gateway-head">
          <span class="arvrs-gateway-tag"><?php echo esc_html($gateway); ?></span>
          <h2 class="arvrs-h1"><?php echo esc_html($title); ?></h2>
        </div>
        <div class="arvrs-gateway-body">
          <div class="arvrs-kv-grid">
            <div class="arvrs-kv-cell"><span><?php esc_html_e('شناسه پرداخت', 'arvan-reseller'); ?></span><strong dir="ltr"><?php echo esc_html($ref); ?></strong></div>
            <div class="arvrs-kv-cell"><span><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></span><strong class="is-amount"><?php echo esc_html(Helpers::money($amount)); ?></strong></div>
          </div>

          <div class="arvrs-gateway-actions" id="arvrs-pay-actions">
            <button type="button" class="arvrs-btn arvrs-btn-primary" id="arvrs-pay-ok"><?php esc_html_e('پرداخت موفق (شبیه‌سازی)', 'arvan-reseller'); ?></button>
            <button type="button" class="arvrs-btn arvrs-btn-ghost" id="arvrs-pay-fail"><?php esc_html_e('انصراف / پرداخت ناموفق', 'arvan-reseller'); ?></button>
            <p class="arvrs-error" id="arvrs-pay-error" role="alert" hidden></p>
          </div>

          <div id="arvrs-pay-progress" class="arvrs-pay-progress" hidden>
            <span class="arvrs-spinner" aria-hidden="true"></span>
            <p id="arvrs-pay-progress-text" class="arvrs-pay-progress-text"></p>
          </div>

          <?php // Permanently rendered live region: announcements are written
                // into it after the matching panel is visible, never by
                // un-hiding pre-baked text (EX-112). ?>
          <p id="arvrs-pay-live" class="arvrs-sr-only" role="status" aria-live="polite"></p>

          <div id="arvrs-pay-result" class="arvrs-pay-result" hidden>
            <?php
            $arvrs_panel('ready', '', true);
            $arvrs_panel('pending', '', true);
            $arvrs_panel('timeout', '', true);
            $arvrs_panel('failed', '', true);
            ?>
            <button type="button" class="arvrs-pay-replay" id="arvrs-pay-replay" title="<?php esc_attr_e('نمایش ایمنی در برابر کال‌بک تکراری', 'arvan-reseller'); ?>"><?php esc_html_e('ارسال دوباره کال‌بک (تست ایمنی تکرار)', 'arvan-reseller'); ?></button>
            <p class="arvrs-field-hint arvrs-replay-result" id="arvrs-replay-result" role="status"></p>
          </div>
        </div>
      </div>
      <p class="arvrs-field-hint arvrs-center"><?php esc_html_e('این صفحه شبیه‌ساز درگاه پرداخت است؛ در حالت واقعی، درگاه بانکی جایگزین می‌شود.', 'arvan-reseller'); ?></p>
    <?php endif; ?>
  </div>
</div>
