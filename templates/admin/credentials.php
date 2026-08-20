<?php
/** @var array $credentials @var bool $crypto_ok */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('اتصال‌های ArvanCloud', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <?php if (!$crypto_ok) : ?>
    <div class="notice notice-error"><p><?php esc_html_e('افزونه sodium در PHP فعال نیست؛ ذخیره امن توکن ممکن نیست.', 'arvan-reseller'); ?></p></div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اتصال‌های موجود', 'arvan-reseller'); ?></h2>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('نام', 'arvan-reseller'); ?></th><th><?php esc_html_e('توکن', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصولات', 'arvan-reseller'); ?></th><th><?php esc_html_e('اولویت', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('آخرین اتصال موفق', 'arvan-reseller'); ?></th><th></th></tr></thead>
      <tbody>
      <?php if (empty($credentials)) : ?><tr><td colspan="7"><?php esc_html_e('هنوز اتصالی ثبت نشده است. تا زمانی که یک اتصال آزمایش‌شده نداشته باشید، فروشگاه در حالت دمو کار می‌کند.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($credentials as $credential) : ?>
        <tr>
          <td><strong><?php echo esc_html($credential['name']); ?></strong>
            <?php if ($credential['is_default']) : ?><span class="arvrs-tag arvrs-tag-info"><?php esc_html_e('پیش‌فرض', 'arvan-reseller'); ?></span><?php endif; ?></td>
          <td><code dir="ltr"><?php echo esc_html($credential['token_masked']); ?></code></td>
          <td dir="ltr"><?php echo esc_html($credential['products'] ?: __('همه', 'arvan-reseller')); ?></td>
          <td><?php echo esc_html($credential['priority']); ?></td>
          <td><?php echo $credential['enabled']
              ? '<span class="arvrs-tag arvrs-tag-success">' . esc_html__('فعال', 'arvan-reseller') . '</span>'
              : '<span class="arvrs-tag arvrs-tag-default">' . esc_html__('غیرفعال', 'arvan-reseller') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($credential['last_error']) : ?><span class="arvrs-tag arvrs-tag-danger" title="<?php echo esc_attr($credential['last_error']); ?>"><?php esc_html_e('خطا', 'arvan-reseller'); ?></span><?php endif; ?></td>
          <td class="arvrs-kv-detail"><?php echo esc_html($credential['last_ok_at'] ?: '—'); ?></td>
          <td class="arvrs-actions-row">
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="arvrs_credential_test" />
              <input type="hidden" name="credential_id" value="<?php echo esc_attr($credential['id']); ?>" />
              <?php wp_nonce_field('arvrs_credential_test', 'arvrs_nonce'); ?>
              <button class="button"><?php esc_html_e('آزمایش اتصال', 'arvan-reseller'); ?></button>
            </form>
            <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  onsubmit="return confirm('<?php echo esc_js(__('این اتصال حذف شود؟', 'arvan-reseller')); ?>')">
              <input type="hidden" name="action" value="arvrs_credential_delete" />
              <input type="hidden" name="credential_id" value="<?php echo esc_attr($credential['id']); ?>" />
              <?php wp_nonce_field('arvrs_credential_delete', 'arvrs_nonce'); ?>
              <button class="button button-link-delete"><?php esc_html_e('حذف', 'arvan-reseller'); ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('افزودن اتصال جدید', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('توکن ماشین‌یوزر را از پنل آروان بسازید: تنظیمات ← فضای کاری ← ماشین‌یوزر. توکن رمزنگاری‌شده ذخیره می‌شود و پس از ذخیره دیگر نمایش داده نمی‌شود.', 'arvan-reseller'); ?></p>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_credential_save" />
      <?php wp_nonce_field('arvrs_credential_save', 'arvrs_nonce'); ?>
      <div class="arvrs-form-grid">
        <div><label><?php esc_html_e('نام نمایشی *', 'arvan-reseller'); ?></label><input type="text" name="name" required /></div>
        <div><label><?php esc_html_e('توکن API *', 'arvan-reseller'); ?></label><input type="password" name="api_token" dir="ltr" autocomplete="off" required /></div>
        <div><label><?php esc_html_e('اولویت (عدد کمتر = مقدم)', 'arvan-reseller'); ?></label><input type="number" name="priority" value="10" min="0" /></div>
        <div><label><?php esc_html_e('محصولات مجاز (هیچ‌کدام = همه)', 'arvan-reseller'); ?></label>
          <?php foreach (Catalog::PRODUCTS as $product) : ?>
            <label style="display:inline-flex;gap:4px;margin-inline-end:10px;font-weight:400">
              <input type="checkbox" name="products[]" value="<?php echo esc_attr($product); ?>" /> <?php echo esc_html(Catalog::product_label($product)); ?>
            </label>
          <?php endforeach; ?></div>
        <div><label style="font-weight:400;display:inline-flex;gap:6px"><input type="checkbox" name="enabled" value="1" checked /> <?php esc_html_e('فعال', 'arvan-reseller'); ?></label>
          <label style="font-weight:400;display:inline-flex;gap:6px"><input type="checkbox" name="is_default" value="1" /> <?php esc_html_e('اتصال پیش‌فرض', 'arvan-reseller'); ?></label></div>
        <div style="grid-column:1/-1"><label><?php esc_html_e('یادداشت', 'arvan-reseller'); ?></label><textarea name="notes" rows="2"></textarea></div>
      </div>
      <p><button class="button button-primary"><?php esc_html_e('ذخیره اتصال', 'arvan-reseller'); ?></button></p>
    </form>
  </div>
</div>
