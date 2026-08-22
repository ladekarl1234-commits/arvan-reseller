<?php
/**
 * Services, operable. This screen used to be pure read-out: when a resource
 * was deleted or changed upstream, or a local status was wrong after a partial
 * failure, the plugin's view of it drifted forever and every correction was a
 * database edit.
 *
 * @var array $services @var int $page @var string $status @var bool $demo
 * @var string $search @var int $total
 */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;

$base_url  = admin_url('admin.php?page=arvan-reseller-services');
$post_url  = admin_url('admin-post.php');
$statuses  = Services::STATUSES;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('سرویس‌ها', 'arvan-reseller'); ?> <span class="arvrs-kv-detail">(<?php echo esc_html(Helpers::fa_digits((string) $total)); ?>)</span></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <form method="get" class="arvrs-actions-row" style="margin:12px 0">
    <input type="hidden" name="page" value="arvan-reseller-services" />
    <?php if ($status !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($status); ?>" /><?php endif; ?>
    <label class="arvrs-lbl" for="arvrs-service-search"><?php esc_html_e('جست‌وجوی سرویس', 'arvan-reseller'); ?></label>
    <input id="arvrs-service-search" type="search" name="s" dir="ltr" value="<?php echo esc_attr($search); ?>"
           placeholder="<?php esc_attr_e('شناسه سرویس، شناسه ابری یا ایمیل مشتری', 'arvan-reseller'); ?>" />
    <button class="button"><?php esc_html_e('جست‌وجو', 'arvan-reseller'); ?></button>
    <?php if ($search !== '') : ?><a class="button" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('پاک‌کردن', 'arvan-reseller'); ?></a><?php endif; ?>
  </form>

  <div class="arvrs-actions-row" style="margin:12px 0">
    <a class="button <?php echo $status === '' ? 'button-primary' : ''; ?>" href="<?php echo esc_url($base_url); ?>"><?php esc_html_e('همه', 'arvan-reseller'); ?></a>
    <?php foreach ($statuses as $state) : ?>
      <a class="button <?php echo $status === $state ? 'button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('status', $state, $base_url)); ?>">
        <?php echo Helpers::status_tag($state); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside ?>
      </a>
    <?php endforeach; ?>
  </div>

  <table class="widefat striped">
    <thead><tr>
      <th>#</th><th><?php esc_html_e('مشتری', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('شناسه ابری', 'arvan-reseller'); ?></th><th><?php esc_html_e('اتصال', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('تمدید بعدی', 'arvan-reseller'); ?></th>
      <th><?php esc_html_e('آخرین همگام‌سازی', 'arvan-reseller'); ?></th><th><?php esc_html_e('عملیات', 'arvan-reseller'); ?></th>
    </tr></thead>
    <tbody>
    <?php if (empty($services)) : ?><tr><td colspan="9"><?php esc_html_e('سرویسی ثبت نشده است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
    <?php foreach ($services as $service) :
        $sid       = (int) $service['id'];
        $sstatus   = (string) $service['status'];
        $live      = in_array($sstatus, Services::LIVE_STATUSES, true);
        $renews_at = isset($service['renews_at']) ? (string) $service['renews_at'] : '';
        $synced_at = isset($service['last_synced_at']) ? (string) $service['last_synced_at'] : '';
        ?>
      <tr>
        <td><?php echo esc_html(Helpers::fa_digits((string) $sid)); ?><?php if ($service['is_demo']) : ?> <span class="arvrs-tag arvrs-tag-warning"><?php esc_html_e('دمو', 'arvan-reseller'); ?></span><?php endif; ?></td>
        <td><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-customers&customer=' . (int) $service['customer_id'])); ?>"><?php echo esc_html($service['customer']); ?></a></td>
        <td><?php echo esc_html(Catalog::product_label((string) $service['product'])); ?> <code dir="ltr"><?php echo esc_html($service['plan_id']); ?></code></td>
        <td><code dir="ltr"><?php echo esc_html($service['remote_id']); ?></code></td>
        <td>
          <details>
            <summary class="arvrs-kv-detail"><?php esc_html_e('نمایش', 'arvan-reseller'); ?></summary>
            <pre dir="ltr" class="arvrs-pre"><?php echo esc_html((string) $service['connection']); ?></pre>
          </details>
        </td>
        <td><?php echo Helpers::status_tag($sstatus); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html($renews_at ? Helpers::jdate($renews_at, 'j F Y') : __('بدون تمدید', 'arvan-reseller')); ?></td>
        <td class="arvrs-kv-detail"><?php echo esc_html($synced_at ? Helpers::jdate($synced_at, 'j F Y — H:i') : '—'); ?></td>
        <td class="arvrs-actions-row">
          <form class="arvrs-inline-form" method="post" action="<?php echo esc_url($post_url); ?>">
            <input type="hidden" name="action" value="arvrs_service_action" /><input type="hidden" name="do" value="resync" />
            <input type="hidden" name="service_id" value="<?php echo esc_attr($sid); ?>" />
            <?php wp_nonce_field('arvrs_service_action', 'arvrs_nonce'); ?>
            <button class="button" title="<?php esc_attr_e('وضعیت و اطلاعات اتصال را از ArvanCloud بازخوانی می‌کند', 'arvan-reseller'); ?>"><?php esc_html_e('همگام‌سازی', 'arvan-reseller'); ?></button>
          </form>

          <?php if ($sstatus === 'suspended') : ?>
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url($post_url); ?>">
              <input type="hidden" name="action" value="arvrs_service_action" /><input type="hidden" name="do" value="resume" />
              <input type="hidden" name="service_id" value="<?php echo esc_attr($sid); ?>" />
              <?php wp_nonce_field('arvrs_service_action', 'arvrs_nonce'); ?>
              <button class="button"><?php esc_html_e('رفع تعلیق', 'arvan-reseller'); ?></button>
            </form>
          <?php elseif ($live) : ?>
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url($post_url); ?>">
              <input type="hidden" name="action" value="arvrs_service_action" /><input type="hidden" name="do" value="suspend" />
              <input type="hidden" name="service_id" value="<?php echo esc_attr($sid); ?>" />
              <?php wp_nonce_field('arvrs_service_action', 'arvrs_nonce'); ?>
              <button class="button"><?php esc_html_e('تعلیق', 'arvan-reseller'); ?></button>
            </form>
          <?php endif; ?>

          <?php if ($renews_at !== '') : ?>
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url($post_url); ?>"
                  onsubmit="return confirm('<?php echo esc_js(__('تمدید خودکار این سرویس متوقف شود؟ سرویس تا پایان دوره فعال می‌ماند.', 'arvan-reseller')); ?>')">
              <input type="hidden" name="action" value="arvrs_service_action" /><input type="hidden" name="do" value="cancel_renewal" />
              <input type="hidden" name="service_id" value="<?php echo esc_attr($sid); ?>" />
              <?php wp_nonce_field('arvrs_service_action', 'arvrs_nonce'); ?>
              <button class="button"><?php esc_html_e('لغو تمدید', 'arvan-reseller'); ?></button>
            </form>
          <?php endif; ?>

          <?php if ($sstatus !== 'cancelled') : ?>
            <details>
              <summary class="arvrs-kv-detail"><?php esc_html_e('حذف سرویس…', 'arvan-reseller'); ?></summary>
              <form method="post" action="<?php echo esc_url($post_url); ?>">
                <input type="hidden" name="action" value="arvrs_service_action" /><input type="hidden" name="do" value="terminate" />
                <input type="hidden" name="service_id" value="<?php echo esc_attr($sid); ?>" />
                <?php wp_nonce_field('arvrs_service_action', 'arvrs_nonce'); ?>
                <p class="arvrs-help"><?php esc_html_e('منبع در ArvanCloud حذف می‌شود و برگشت‌پذیر نیست. داده‌های مشتری از بین می‌رود.', 'arvan-reseller'); ?></p>
                <label class="arvrs-lbl" for="arvrs-confirm-<?php echo esc_attr($sid); ?>">
                  <?php echo esc_html(sprintf(__('برای تأیید، عدد %s را وارد کنید', 'arvan-reseller'), Helpers::fa_digits((string) $sid))); ?>
                </label>
                <input id="arvrs-confirm-<?php echo esc_attr($sid); ?>" type="number" name="confirm" dir="ltr" required />
                <button class="button button-link-delete"><?php esc_html_e('حذف قطعی سرویس', 'arvan-reseller'); ?></button>
              </form>
            </details>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="arvrs-actions-row">
    <?php if ($page > 1) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page - 1)); ?>">‹ <?php esc_html_e('قبلی', 'arvan-reseller'); ?></a><?php endif; ?>
    <?php if ($page * 20 < $total) : ?><a class="button" href="<?php echo esc_url(add_query_arg('paged', $page + 1)); ?>"><?php esc_html_e('بعدی', 'arvan-reseller'); ?> ›</a><?php endif; ?>
  </p>
</div>
