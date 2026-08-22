<?php
/**
 * The "you cannot see this yet" card, in three flavours.
 *
 * The third one is the fix for EX-065: a logged-in WordPress user who is not a
 * customer used to be bounced auth → dashboard → auth forever, because both
 * pages agreed they were "not a customer" and neither said so. They now get
 * told what happened and handed two ways out.
 *
 * @var int $customer_id @var bool $foreign_login @var array $urls
 */
defined('ABSPATH') || exit;

$foreign_login = !empty($foreign_login);

include __DIR__ . '/partials/shell-top.php';
?>
<div class="arvrs-narrow">
  <div class="arvrs-card arvrs-center">
    <?php if ($customer_id) : ?>
      <h2 class="arvrs-card-title"><?php esc_html_e('حساب شما فعال است', 'arvan-reseller'); ?></h2>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['dashboard']); ?>"><?php esc_html_e('رفتن به پیشخوان', 'arvan-reseller'); ?></a>

    <?php elseif ($foreign_login) : ?>
      <h2 class="arvrs-card-title"><?php esc_html_e('شما با حساب مدیریت وارد شده‌اید', 'arvan-reseller'); ?></h2>
      <p class="arvrs-muted"><?php esc_html_e('این حساب کاربری وردپرس، حساب مشتری فروشگاه نیست؛ بنابراین پیشخوان مشتری برای آن وجود ندارد. برای دیدن تجربه مشتری، با یک حساب مشتری وارد شوید.', 'arvan-reseller'); ?></p>
      <div class="arvrs-inline-actions arvrs-center">
        <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['admin']); ?>"><?php esc_html_e('بازگشت به پیشخوان وردپرس', 'arvan-reseller'); ?></a>
        <a class="arvrs-btn arvrs-btn-secondary" href="<?php echo esc_url(wp_logout_url($urls['auth'])); ?>"><?php esc_html_e('خروج و ورود به‌عنوان مشتری', 'arvan-reseller'); ?></a>
        <a class="arvrs-btn arvrs-btn-ghost" href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('مشاهده فروشگاه', 'arvan-reseller'); ?></a>
      </div>

    <?php else : ?>
      <h2 class="arvrs-card-title"><?php esc_html_e('برای ادامه وارد شوید', 'arvan-reseller'); ?></h2>
      <p class="arvrs-muted"><?php esc_html_e('برای مشاهده این بخش باید وارد حساب کاربری خود شوید.', 'arvan-reseller'); ?></p>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['auth']); ?>"><?php esc_html_e('ورود / ثبت‌نام', 'arvan-reseller'); ?></a>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
