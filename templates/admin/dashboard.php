<?php
/**
 * Admin dashboard. Vars from Menu::dashboard().
 *
 * @var bool $licensed @var bool $demo @var int $customers @var array $services
 * @var array $period @var int $mrr @var string $period_from @var int $customer_credit
 * @var array $negatives @var array $attention @var array $jobs @var array $recent
 * @var array $notices @var int $unread
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Labels;
use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

$orders_url = admin_url('admin.php?page=arvan-reseller-orders');
$month      = Helpers::jdate($period_from, 'F Y');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('پیشخوان فروشگاه ابری', 'arvan-reseller'); ?>
    <?php if ($demo) : ?><span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('حالت دمو', 'arvan-reseller'); ?></span><?php endif; ?>
  </h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <?php if (!$licensed) : ?>
    <div class="notice notice-error"><p>
      <strong><?php esc_html_e('افزونه هنوز فعال‌سازی نشده است.', 'arvan-reseller'); ?></strong>
      <a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-setup')); ?>"><?php esc_html_e('اجرای راه‌اندازی', 'arvan-reseller'); ?></a>
    </p></div>
  <?php endif; ?>

  <p class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('ارقام مالی زیر مربوط به %s است. برای دوره‌های دیگر، «گزارش مالی» را ببینید.', 'arvan-reseller'), $month)); ?></p>

  <div class="arvrs-cards">
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('مشتریان', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) $customers)); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سرویس‌های فعال', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (($services['active'] ?? 0) + ($services['at_risk'] ?? 0)))); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('سفارش‌های این ماه', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::fa_digits((string) (int) $period['orders'])); ?></span></div>
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('درآمد این ماه', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $period['revenue'])); ?></span><span class="sub"><?php echo esc_html(sprintf(__('هزینه پایه: %s', 'arvan-reseller'), Helpers::money((int) $period['cost']))); ?></span></div>
    <div class="arvrs-acard is-success"><span class="label"><?php esc_html_e('سود ناخالص برآوردی', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money((int) $period['margin'])); ?></span><span class="sub"><?php esc_html_e('بر پایه هزینه‌های پایه واردشده، نه صورت‌حساب آروان', 'arvan-reseller'); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('درآمد ماهانه تکرارشونده (MRR)', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($mrr)); ?></span></div>
    <div class="arvrs-acard"><span class="label"><?php esc_html_e('اعتبار مشتریان', 'arvan-reseller'); ?></span><span class="value"><?php echo esc_html(Helpers::money($customer_credit)); ?></span></div>
    <div class="arvrs-acard <?php echo ($jobs['dead'] ?? 0) || ($jobs['stale_running'] ?? 0) ? 'is-danger' : ''; ?>">
      <span class="label"><?php esc_html_e('وظایف نیازمند رسیدگی', 'arvan-reseller'); ?></span>
      <span class="value"><?php echo esc_html(Helpers::fa_digits((string) ((int) ($jobs['dead'] ?? 0) + (int) ($jobs['stale_running'] ?? 0)))); ?></span>
      <span class="sub"><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-health')); ?>"><?php echo esc_html(sprintf(__('در صف: %s — رسیدگی', 'arvan-reseller'), Helpers::fa_digits((string) (int) ($jobs['pending'] ?? 0)))); ?></a></span>
    </div>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اعلان‌های مدیر', 'arvan-reseller'); ?>
      <?php if ($unread) : ?><span class="arvrs-tag arvrs-tag-danger"><?php echo esc_html(sprintf(__('%s خوانده‌نشده', 'arvan-reseller'), Helpers::fa_digits((string) $unread))); ?></span><?php endif; ?>
    </h2>
    <?php if (empty($notices)) : ?>
      <p class="arvrs-help"><?php esc_html_e('اعلانی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('نوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('پیام', 'arvan-reseller'); ?></th><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($notices as $note) : ?>
          <tr>
            <td><span class="arvrs-tag arvrs-tag-<?php echo (int) $note['is_read'] ? 'default' : 'warning'; ?>"><?php echo esc_html(Labels::notification_type((string) $note['type'])); ?></span></td>
            <td><strong><?php echo esc_html($note['title']); ?></strong><br /><span class="arvrs-kv-detail"><?php echo esc_html(wp_trim_words((string) $note['body'], 24, '…')); ?></span></td>
            <td class="arvrs-kv-detail" dir="ltr"><?php echo esc_html($note['created_at']); ?></td>
            <td>
              <?php if (!(int) $note['is_read']) : ?>
                <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                  <input type="hidden" name="action" value="arvrs_notification_action" />
                  <input type="hidden" name="do" value="read" />
                  <input type="hidden" name="notification_id" value="<?php echo esc_attr($note['id']); ?>" />
                  <?php wp_nonce_field('arvrs_notification_action', 'arvrs_nonce'); ?>
                  <button class="button"><?php esc_html_e('خواندم', 'arvan-reseller'); ?></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-notifications')); ?>"><?php esc_html_e('همه اعلان‌ها ←', 'arvan-reseller'); ?></a></p>
  </div>

  <?php if (!empty($attention)) : ?>
    <div class="arvrs-panel">
      <h2><?php esc_html_e('سفارش‌های نیازمند رسیدگی', 'arvan-reseller'); ?></h2>
      <p class="arvrs-help"><?php esc_html_e('سفارش‌هایی که پرداخت شده‌اند اما سرویس آن‌ها تحویل نشده است. هر ردیف را باز کنید تا «بازیابی» یا «تلاش دوباره» بزنید.', 'arvan-reseller'); ?></p>
      <table class="widefat striped">
        <thead><tr><th>#</th><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('آخرین تغییر', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($attention as $order) : $user = get_userdata((int) $order['customer_id']); ?>
          <tr>
            <td><?php echo esc_html(Helpers::fa_digits((string) (int) $order['id'])); ?></td>
            <td><?php echo esc_html($user ? $user->display_name : '#' . (int) $order['customer_id']); ?></td>
            <td><?php echo esc_html(Catalog::product_label((string) $order['product'])); ?></td>
            <td><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></td>
            <td><?php echo Helpers::status_tag((string) $order['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?></td>
            <td class="arvrs-kv-detail" dir="ltr"><?php echo esc_html((string) $order['updated_at']); ?></td>
            <td><a class="button" href="<?php echo esc_url(add_query_arg('order', (int) $order['id'], $orders_url)); ?>"><?php esc_html_e('رسیدگی', 'arvan-reseller'); ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (!empty($negatives)) : ?>
    <div class="arvrs-panel">
      <h2><?php esc_html_e('حساب‌های با مانده منفی', 'arvan-reseller'); ?></h2>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('مانده', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($negatives as $row) : $user = get_userdata((int) $row['customer_id']); ?>
          <tr>
            <td><?php echo esc_html($user ? $user->display_name : ('#' . $row['customer_id'])); ?></td>
            <td class="arvrs-negative"><?php echo esc_html(Helpers::money((int) $row['available'])); ?></td>
            <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-customers&customer=' . (int) $row['customer_id'])); ?>"><?php esc_html_e('مشاهده', 'arvan-reseller'); ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('رویدادهای مهم اخیر', 'arvan-reseller'); ?></h2>
    <?php if (empty($recent)) : ?>
      <p class="arvrs-help"><?php esc_html_e('رویدادی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <table class="widefat striped">
        <thead><tr><th><?php esc_html_e('رویداد', 'arvan-reseller'); ?></th><th><?php esc_html_e('موضوع', 'arvan-reseller'); ?></th><th><?php esc_html_e('زمان', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $event) : ?>
          <tr>
            <td dir="ltr"><?php echo esc_html($event['action']); ?></td>
            <td dir="ltr"><?php echo esc_html($event['object_type'] . ($event['object_id'] ? ' #' . $event['object_id'] : '')); ?></td>
            <td class="arvrs-kv-detail" dir="ltr"><?php echo esc_html($event['created_at']); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <p><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-audit')); ?>"><?php esc_html_e('مشاهده گزارش کامل ←', 'arvan-reseller'); ?></a></p>
  </div>
</div>
