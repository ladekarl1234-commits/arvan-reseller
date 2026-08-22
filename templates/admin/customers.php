<?php
/** @var array $customers @var string $search @var int $page @var int $total */
defined('ABSPATH') || exit;

use ArvanReseller\Support\Helpers;

$base_url = admin_url('admin.php?page=arvan-reseller-customers');
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('مشتریان', 'arvan-reseller'); ?> <span class="arvrs-kv-detail">(<?php echo esc_html(Helpers::fa_digits((string) $total)); ?>)</span></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="get" class="arvrs-actions-row" style="margin:12px 0">
    <input type="hidden" name="page" value="arvan-reseller-customers" />
    <label class="arvrs-lbl" for="arvrs-customer-search"><?php esc_html_e('جست‌وجوی مشتری', 'arvan-reseller'); ?></label>
    <input id="arvrs-customer-search" type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('نام یا ایمیل…', 'arvan-reseller'); ?>" />
    <button class="button"><?php esc_html_e('جست‌وجو', 'arvan-reseller'); ?></button>
  </form>

  <table class="widefat striped">
    <thead><tr><th>#</th><th><?php esc_html_e('نام', 'arvan-reseller'); ?></th><th><?php esc_html_e('ایمیل', 'arvan-reseller'); ?></th><th><?php esc_html_e('اعتبار', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت اعتبار', 'arvan-reseller'); ?></th><th><?php esc_html_e('عضویت', 'arvan-reseller'); ?></th><th></th></tr></thead>
    <tbody>
    <?php if (empty($customers)) : ?>
      <tr><td colspan="7"><?php esc_html_e('مشتری‌ای یافت نشد.', 'arvan-reseller'); ?></td></tr>
    <?php endif; ?>
    <?php foreach ($customers as $customer) : ?>
      <tr>
        <td><?php echo esc_html($customer['id']); ?></td>
        <td><?php echo esc_html($customer['name']); ?></td>
        <td dir="ltr"><?php echo esc_html($customer['email']); ?></td>
        <td class="<?php echo $customer['balance'] < 0 ? 'arvrs-negative' : ''; ?>"><?php echo esc_html(Helpers::money((int) $customer['balance'])); ?></td>
        <td><?php echo Helpers::status_tag((string) $customer['stage']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html(Helpers::jdate((string) $customer['registered'], 'j F Y')); ?></td>
        <td><a class="button" href="<?php echo esc_url(add_query_arg('customer', (int) $customer['id'], $base_url)); ?>"><?php esc_html_e('پرونده', 'arvan-reseller'); ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if ($page * 20 < $total) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
  </p>
</div>
