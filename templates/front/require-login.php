<?php
/** @var int $customer_id @var array $urls */
defined('ABSPATH') || exit;

include __DIR__ . '/partials/shell-top.php';
?>
<div style="max-width:460px;margin:24px auto">
  <div class="arvrs-card arvrs-center">
    <?php if ($customer_id) : ?>
      <h1 class="arvrs-card-title"><?php esc_html_e('حساب شما فعال است', 'arvan-reseller'); ?></h1>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['dashboard']); ?>"><?php esc_html_e('رفتن به پیشخوان', 'arvan-reseller'); ?></a>
    <?php else : ?>
      <h1 class="arvrs-card-title"><?php esc_html_e('برای ادامه وارد شوید', 'arvan-reseller'); ?></h1>
      <p class="arvrs-muted"><?php esc_html_e('برای مشاهده این بخش باید وارد حساب کاربری خود شوید.', 'arvan-reseller'); ?></p>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['auth']); ?>"><?php esc_html_e('ورود / ثبت‌نام', 'arvan-reseller'); ?></a>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
