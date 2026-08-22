<?php
/** @var array $credentials @var bool $crypto_ok @var array $reconciliation @var bool $demo */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

$cred_names = [];
foreach ($credentials as $c) {
    $cred_names[(int) $c['id']] = $c['name'];
}
?>
<div class="wrap arvrs-admin" dir="rtl">
  <h1><?php esc_html_e('اتصال‌های ArvanCloud', 'arvan-reseller'); ?></h1>
  <?php include __DIR__ . '/partials/notices.php'; ?>

  <?php if (!$crypto_ok) : ?>
    <div class="notice notice-error"><p><?php esc_html_e('افزونه sodium در PHP فعال نیست؛ ذخیره امن توکن ممکن نیست.', 'arvan-reseller'); ?></p></div>
  <?php endif; ?>

  <div class="arvrs-panel">
    <h2><?php esc_html_e('اتصال‌های موجود', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('این اتصال‌ها هر روز به‌طور خودکار آزمایش می‌شوند، بنابراین توکن باطل‌شده دیگر «متصل» نمی‌ماند. برای آزمایش فوری، دکمه زیر را بزنید.', 'arvan-reseller'); ?></p>
    <?php if (!empty($credentials)) : ?>
      <form class="arvrs-inline-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <input type="hidden" name="action" value="arvrs_credential_test" />
        <?php wp_nonce_field('arvrs_credential_test', 'arvrs_nonce'); ?>
        <button class="button button-primary"><?php esc_html_e('آزمایش همه اتصال‌ها', 'arvan-reseller'); ?></button>
      </form>
    <?php endif; ?>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('نام', 'arvan-reseller'); ?></th><th><?php esc_html_e('توکن', 'arvan-reseller'); ?></th><th><?php esc_html_e('محصولات', 'arvan-reseller'); ?></th><th><?php esc_html_e('اولویت', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('آخرین اتصال موفق', 'arvan-reseller'); ?></th><th></th></tr></thead>
      <tbody>
      <?php if (empty($credentials)) : ?><tr><td colspan="7"><?php esc_html_e('هنوز اتصالی ثبت نشده است. تا زمانی که یک اتصال آزمایش‌شده نداشته باشید، فروشگاه در حالت دمو کار می‌کند.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($credentials as $credential) : $last_ok = (string) $credential['last_ok_at']; ?>
        <tr>
          <td><strong><?php echo esc_html($credential['name']); ?></strong>
            <?php if ($credential['is_default']) : ?><span class="arvrs-tag arvrs-tag-info"><?php esc_html_e('پیش‌فرض', 'arvan-reseller'); ?></span><?php endif; ?></td>
          <td><code dir="ltr"><?php echo esc_html($credential['token_masked']); ?></code></td>
          <td dir="ltr"><?php echo esc_html($credential['products'] ?: __('همه', 'arvan-reseller')); ?></td>
          <td><?php echo esc_html(Helpers::fa_digits((string) (int) $credential['priority'])); ?></td>
          <td><?php echo $credential['enabled']
              ? '<span class="arvrs-tag arvrs-tag-success">' . esc_html__('فعال', 'arvan-reseller') . '</span>'
              : '<span class="arvrs-tag arvrs-tag-default">' . esc_html__('غیرفعال', 'arvan-reseller') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <?php if ($credential['last_error']) : ?>
              <span class="arvrs-tag arvrs-tag-danger"><?php esc_html_e('خطا', 'arvan-reseller'); ?></span>
              <p class="arvrs-help" dir="ltr"><?php echo esc_html((string) $credential['last_error']); ?></p>
            <?php endif; ?></td>
          <td class="arvrs-kv-detail"><?php echo esc_html($last_ok ? Helpers::jdate($last_ok, 'j F Y — H:i') : '—'); ?></td>
          <td class="arvrs-actions-row">
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
    <h2><?php esc_html_e('تطبیق حساب — مصرف به تفکیک اتصال', 'arvan-reseller'); ?></h2>
    <p class="arvrs-help"><?php esc_html_e('مجموع هزینه مصرف سرویس‌های ساخته‌شده روی هر اعتبار ArvanCloud (spec §۷).', 'arvan-reseller'); ?></p>
    <table class="widefat striped">
      <thead><tr><th><?php esc_html_e('اتصال', 'arvan-reseller'); ?></th><th><?php esc_html_e('سرویس‌ها', 'arvan-reseller'); ?></th><th><?php esc_html_e('مجموع مصرف', 'arvan-reseller'); ?></th></tr></thead>
      <tbody>
      <?php if (empty($reconciliation)) : ?><tr><td colspan="3"><?php esc_html_e('هنوز مصرفی ثبت نشده است.', 'arvan-reseller'); ?></td></tr><?php endif; ?>
      <?php foreach ($reconciliation as $row) : $cid = (int) $row['credential_id']; ?>
        <tr>
          <td><?php echo esc_html($cid ? ($cred_names[$cid] ?? ('#' . $cid)) : __('بدون اتصال (حالت دمو)', 'arvan-reseller')); ?></td>
          <td><?php echo esc_html(Helpers::fa_digits((string) $row['services'])); ?></td>
          <td><?php echo esc_html(Helpers::money((int) $row['usage_cost'])); ?></td>
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
        <div><label for="arvrs-cred-name"><?php esc_html_e('نام نمایشی *', 'arvan-reseller'); ?></label>
          <input id="arvrs-cred-name" type="text" name="name" required /></div>
        <div><label for="arvrs-cred-token"><?php esc_html_e('توکن API *', 'arvan-reseller'); ?></label>
          <input id="arvrs-cred-token" type="password" name="api_token" dir="ltr" autocomplete="off" required /></div>
        <div><label for="arvrs-cred-priority"><?php esc_html_e('اولویت (عدد کمتر = مقدم)', 'arvan-reseller'); ?></label>
          <input id="arvrs-cred-priority" type="number" name="priority" value="10" min="0" /></div>
        <fieldset>
          <legend class="arvrs-lbl"><?php esc_html_e('محصولات مجاز (هیچ‌کدام = همه)', 'arvan-reseller'); ?></legend>
          <?php foreach (Catalog::PRODUCTS as $product) : ?>
            <label class="arvrs-inline-check">
              <input type="checkbox" name="products[]" value="<?php echo esc_attr($product); ?>" /> <?php echo esc_html(Catalog::product_label($product)); ?>
            </label>
          <?php endforeach; ?>
        </fieldset>
        <fieldset>
          <legend class="arvrs-lbl"><?php esc_html_e('وضعیت اتصال', 'arvan-reseller'); ?></legend>
          <label class="arvrs-inline-check"><input type="checkbox" name="enabled" value="1" checked /> <?php esc_html_e('فعال', 'arvan-reseller'); ?></label>
          <label class="arvrs-inline-check"><input type="checkbox" name="is_default" value="1" /> <?php esc_html_e('اتصال پیش‌فرض', 'arvan-reseller'); ?></label>
        </fieldset>
        <div class="arvrs-span-all"><label for="arvrs-cred-notes"><?php esc_html_e('یادداشت', 'arvan-reseller'); ?></label>
          <textarea id="arvrs-cred-notes" name="notes" rows="2"></textarea></div>
      </div>
      <p><button class="button button-primary"><?php esc_html_e('ذخیره اتصال', 'arvan-reseller'); ?></button></p>
    </form>
  </div>
</div>
