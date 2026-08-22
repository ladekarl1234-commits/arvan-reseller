<?php
/**
 * Onboarding wizard shell. Vars come from Wizard::render().
 * @var int $step @var string $step_key @var array $steps @var bool $licensed
 * @var string $error @var string $notice
 */
defined('ABSPATH') || exit;

use ArvanReseller\Admin\Labels;
use ArvanReseller\Support\Options;
?>
<div class="wrap arvrs-admin arvrs-wizard" dir="rtl">
  <div class="arvrs-wizard-progress" aria-hidden="true">
    <?php foreach ($steps as $i => $s) : ?>
      <span class="seg <?php echo $i <= $step ? 'is-done' : ''; ?>"></span>
    <?php endforeach; ?>
  </div>
  <p class="arvrs-kv-detail"><?php echo esc_html(sprintf(__('گام %1$d از %2$d', 'arvan-reseller'), $step + 1, count($steps))); ?></p>

  <?php if ($error) : ?><div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div><?php endif; ?>
  <?php if ($notice) : ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

  <form class="arvrs-wizard-card" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
    <input type="hidden" name="action" value="arvrs_wizard" />
    <?php wp_nonce_field('arvrs_wizard', 'arvrs_nonce'); ?>

    <?php if ($step_key === 'welcome') : ?>
      <h1><?php esc_html_e('به فروشگاه ابری خود خوش آمدید 👋', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('در چند گام کوتاه، وب‌سایت وردپرسی شما به یک فروشگاه کامل خدمات ابری تبدیل می‌شود: سرور ابری، CDN و فضای ذخیره‌سازی — با تحویل خودکار، کیف پول مشتری و مدیریت مصرف.', 'arvan-reseller'); ?></p>
      <ul class="arvrs-check-list">
        <li>✅ <?php esc_html_e('فعال‌سازی افزونه با توکن دسترسی', 'arvan-reseller'); ?></li>
        <li>✅ <?php esc_html_e('معرفی برند شما', 'arvan-reseller'); ?></li>
        <li>✅ <?php esc_html_e('اتصال به ArvanCloud (یا حالت دمو)', 'arvan-reseller'); ?></li>
        <li>✅ <?php esc_html_e('قیمت‌گذاری و ساخت خودکار صفحات', 'arvan-reseller'); ?></li>
      </ul>

    <?php elseif ($step_key === 'license') : ?>
      <h1><?php esc_html_e('توکن دسترسی افزونه', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('این توکن مجوز استفاده شما از افزونه است و از تیم فروش دریافت کرده‌اید. با توکن ArvanCloud API اشتباه گرفته نشود — آن را در گام بعد وارد می‌کنید.', 'arvan-reseller'); ?></p>
      <?php if ($licensed) : ?>
        <div class="notice notice-success inline"><p><?php esc_html_e('افزونه قبلاً با موفقیت فعال شده است.', 'arvan-reseller'); ?></p></div>
      <?php else : ?>
        <div class="arvrs-field">
          <label for="arvrs-access-token"><?php esc_html_e('توکن دسترسی', 'arvan-reseller'); ?></label>
          <input id="arvrs-access-token" name="access_token" type="password" required dir="ltr" autocomplete="off" />
          <p class="arvrs-help"><?php esc_html_e('توکن به‌صورت امن بررسی می‌شود و هرگز به شکل خام ذخیره نمی‌گردد.', 'arvan-reseller'); ?></p>
        </div>
      <?php endif; ?>

    <?php elseif ($step_key === 'identity') : ?>
      <h1><?php esc_html_e('برند فروشگاه شما', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('مشتریان، فروشگاه را با برند شما می‌بینند؛ نه ArvanCloud.', 'arvan-reseller'); ?></p>
      <div class="arvrs-field">
        <label for="arvrs-brand-name"><?php esc_html_e('نام فروشگاه *', 'arvan-reseller'); ?></label>
        <input id="arvrs-brand-name" name="brand_name" type="text" required value="<?php echo esc_attr($brand_name); ?>" />
      </div>
      <div class="arvrs-field">
        <label for="arvrs-brand-desc"><?php esc_html_e('توضیح کوتاه', 'arvan-reseller'); ?></label>
        <input id="arvrs-brand-desc" name="brand_description" type="text" value="<?php echo esc_attr($brand_description); ?>" placeholder="<?php esc_attr_e('زیرساخت ابری برای کسب‌وکار شما', 'arvan-reseller'); ?>" />
      </div>
      <div class="arvrs-field">
        <label for="arvrs-support-email"><?php esc_html_e('ایمیل پشتیبانی', 'arvan-reseller'); ?></label>
        <input id="arvrs-support-email" name="support_email" type="email" dir="ltr" value="<?php echo esc_attr($support_email); ?>" />
      </div>
      <div class="arvrs-field">
        <label for="arvrs-support-phone"><?php esc_html_e('تلفن پشتیبانی', 'arvan-reseller'); ?></label>
        <input id="arvrs-support-phone" name="support_phone" type="text" dir="ltr" value="<?php echo esc_attr($support_phone); ?>" />
      </div>
      <div class="arvrs-field">
        <label for="arvrs-brand-color"><?php esc_html_e('رنگ برند', 'arvan-reseller'); ?></label>
        <input id="arvrs-brand-color" name="brand_color" type="color" value="<?php echo esc_attr($brand_color ?: Options::BRAND_COLOR); ?>" />
        <p class="arvrs-help"><?php esc_html_e('لوگو را بعداً می‌توانید از بخش «برند و تنظیمات» بارگذاری کنید.', 'arvan-reseller'); ?></p>
      </div>

    <?php elseif ($step_key === 'arvan') : ?>
      <h1><?php esc_html_e('اتصال به ArvanCloud', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('توکن API (ماشین‌یوزر) را از پنل آروان بسازید: تنظیمات ← فضای کاری ← ماشین‌یوزر. اگر فعلاً توکن ندارید، خالی بگذارید تا با «حالت دمو» ادامه دهید.', 'arvan-reseller'); ?></p>
      <?php if (!$crypto_ok) : ?>
        <div class="notice notice-error inline"><p><?php esc_html_e('افزونه sodium در PHP فعال نیست؛ ذخیره امن توکن ممکن نیست. با میزبان خود تماس بگیرید یا با حالت دمو ادامه دهید.', 'arvan-reseller'); ?></p></div>
      <?php endif; ?>
      <?php if (!empty($credentials)) : ?>
        <div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('%d اتصال ذخیره شده دارید.', 'arvan-reseller'), count($credentials))); ?></p></div>
      <?php endif; ?>
      <div class="arvrs-field">
        <label for="arvrs-cred-name"><?php esc_html_e('نام اتصال', 'arvan-reseller'); ?></label>
        <input id="arvrs-cred-name" name="credential_name" type="text" placeholder="<?php esc_attr_e('حساب اصلی آروان', 'arvan-reseller'); ?>" />
      </div>
      <div class="arvrs-field">
        <label for="arvrs-api-token"><?php esc_html_e('توکن ArvanCloud API', 'arvan-reseller'); ?></label>
        <input id="arvrs-api-token" name="api_token" type="password" dir="ltr" autocomplete="off" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" />
        <p class="arvrs-help"><?php esc_html_e('توکن رمزنگاری‌شده ذخیره می‌شود، هرگز نمایش داده نمی‌شود و بلافاصله آزمایش اتصال انجام می‌گیرد.', 'arvan-reseller'); ?></p>
      </div>

    <?php elseif ($step_key === 'pricing') : ?>
      <h1><?php esc_html_e('قیمت‌گذاری و محصولات', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('قیمت مشتری = هزینه پایه آروان + درصد سود شما. هزینه‌های پایه از صفحه قیمت آروان مقداردهی اولیه می‌شوند و بعداً از بخش «قیمت‌گذاری» قابل ویرایش‌اند.', 'arvan-reseller'); ?></p>
      <div class="arvrs-field">
        <label for="arvrs-markup"><?php esc_html_e('درصد سود سراسری', 'arvan-reseller'); ?></label>
        <input id="arvrs-markup" name="global_markup" type="number" step="0.5" min="-100" value="<?php echo esc_attr($global_markup); ?>" />
        <p class="arvrs-help"><?php esc_html_e('مثال: هزینه پایه ۱۰٬۰۰۰٬۰۰۰ تومان + سود ۲۰٪ = قیمت مشتری ۱۲٬۰۰۰٬۰۰۰ تومان.', 'arvan-reseller'); ?></p>
      </div>
      <fieldset class="arvrs-field">
        <legend class="arvrs-lbl"><?php esc_html_e('محصولات قابل فروش', 'arvan-reseller'); ?></legend>
        <?php foreach (['cloud_server' => __('سرور ابری', 'arvan-reseller'), 'cdn' => __('CDN', 'arvan-reseller'), 'object_storage' => __('فضای ابری', 'arvan-reseller')] as $pk => $pl) : ?>
          <label class="arvrs-inline-check">
            <input type="checkbox" name="enabled_products[]" value="<?php echo esc_attr($pk); ?>" <?php checked(in_array($pk, $enabled_products, true)); ?> />
            <?php echo esc_html($pl); ?>
          </label>
        <?php endforeach; ?>
      </fieldset>

    <?php elseif ($step_key === 'pages') : ?>
      <h1><?php esc_html_e('ساخت خودکار صفحات', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('با تأیید این گام، صفحات فروشگاه، محصولات، پرداخت و پیشخوان مشتری به‌صورت خودکار ساخته می‌شوند. اجرای دوباره، صفحه تکراری نمی‌سازد.', 'arvan-reseller'); ?></p>
      <ul class="arvrs-validation">
        <?php foreach ($pages as $key => $page) : ?>
          <li>
            <span class="<?php echo $page['status'] === 'publish' ? 'ok' : 'fail'; ?>"><?php echo $page['status'] === 'publish' ? '✓' : '•'; ?></span>
            <span><?php echo esc_html(Labels::page_title((string) $key)); ?></span>
            <span class="arvrs-kv-detail"><?php echo $page['status'] === 'publish' ? esc_html__('ساخته شده', 'arvan-reseller') : esc_html__('ساخته می‌شود', 'arvan-reseller'); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>

    <?php elseif ($step_key === 'ready') : ?>
      <h1><?php esc_html_e('بررسی نهایی', 'arvan-reseller'); ?></h1>
      <p class="arvrs-wizard-sub"><?php esc_html_e('وضعیت راه‌اندازی شما:', 'arvan-reseller'); ?></p>
      <ul class="arvrs-validation">
        <?php foreach ($checks as $check) : ?>
          <li>
            <span class="<?php echo $check['ok'] ? 'ok' : 'fail'; ?>"><?php echo $check['ok'] ? '✓' : '✗'; ?></span>
            <strong><?php echo esc_html($check['label']); ?></strong>
            <span class="arvrs-kv-detail"><?php echo esc_html($check['detail']); ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (empty($checks['pricing']['ok'])) : ?>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=arvan-reseller-pricing')); ?>"><?php esc_html_e('رفتن به قیمت‌گذاری و درون‌ریزی پلن‌های آروان ←', 'arvan-reseller'); ?></a></p>
      <?php endif; ?>
      <p><a href="<?php echo esc_url(\ArvanReseller\Install\PageFactory::url('storefront')); ?>" target="_blank"><?php esc_html_e('پیش‌نمایش فروشگاه ↗', 'arvan-reseller'); ?></a></p>
    <?php endif; ?>

    <div class="arvrs-wizard-nav">
      <?php if ($step > 0) : ?>
        <button type="submit" name="direction" value="back" class="button button-secondary"><?php esc_html_e('بازگشت', 'arvan-reseller'); ?></button>
      <?php else : ?><span></span><?php endif; ?>
      <button type="submit" name="direction" value="next" class="button button-primary button-hero">
        <?php echo $step_key === 'ready' ? esc_html__('پایان و شروع فروش 🚀', 'arvan-reseller') : esc_html__('ادامه', 'arvan-reseller'); ?>
      </button>
    </div>
  </form>
</div>
