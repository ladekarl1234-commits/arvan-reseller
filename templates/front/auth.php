<?php
/** @var string $error @var string $notice @var array $urls @var string $brand_name @var string $brand_logo */
defined('ABSPATH') || exit;

$brand_initial = function_exists('mb_substr') ? mb_substr($brand_name, 0, 1, 'UTF-8') : substr($brand_name, 0, 1);
?>
<div class="arvrs-app" dir="rtl">
  <div class="arvrs-auth">
    <aside class="arvrs-auth-aside">
      <div class="arvrs-auth-aside-body">
        <span class="arvrs-auth-mark"><?php echo esc_html($brand_initial ?: 'ا'); ?></span>
        <h1><?php echo esc_html(sprintf(__('به فروشگاه ابری %s خوش آمدید', 'arvan-reseller'), $brand_name)); ?></h1>
        <p><?php esc_html_e('سرور ابری، CDN و فضای ذخیره‌سازی — با تحویل خودکار و پرداخت ریالی، در چند دقیقه.', 'arvan-reseller'); ?></p>
      </div>
    </aside>

    <div class="arvrs-auth-panel">
      <div class="arvrs-auth-card">
        <div class="arvrs-auth-switch" role="tablist">
          <button class="arvrs-tab is-active" id="arvrs-tab-login" role="tab" aria-selected="true" aria-controls="arvrs-panel-login"><?php esc_html_e('ورود', 'arvan-reseller'); ?></button>
          <button class="arvrs-tab" id="arvrs-tab-register" role="tab" aria-selected="false" aria-controls="arvrs-panel-register"><?php esc_html_e('ثبت‌نام', 'arvan-reseller'); ?></button>
        </div>

        <?php if ($error) : ?>
          <div class="arvrs-alert arvrs-alert-danger" role="alert"><span class="arvrs-alert-mark">!</span><span class="arvrs-alert-body"><?php echo esc_html($error); ?></span></div>
        <?php endif; ?>
        <?php if ($notice) : ?>
          <div class="arvrs-alert arvrs-alert-info" role="status"><span class="arvrs-alert-mark">i</span><span class="arvrs-alert-body"><?php echo esc_html($notice); ?></span></div>
        <?php endif; ?>

        <form id="arvrs-panel-login" role="tabpanel" aria-labelledby="arvrs-tab-login"
              method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_login" />
          <?php wp_nonce_field('arvrs_auth', 'arvrs_nonce'); ?>
          <div class="arvrs-field">
            <label for="arvrs-login-email"><?php esc_html_e('ایمیل', 'arvan-reseller'); ?></label>
            <input id="arvrs-login-email" name="email" type="email" required dir="ltr" autocomplete="email" />
          </div>
          <div class="arvrs-field">
            <label for="arvrs-login-pass"><?php esc_html_e('گذرواژه', 'arvan-reseller'); ?></label>
            <input id="arvrs-login-pass" name="password" type="password" required dir="ltr" autocomplete="current-password" />
          </div>
          <button type="submit" class="arvrs-btn arvrs-btn-primary arvrs-btn-block"><?php esc_html_e('ورود به حساب', 'arvan-reseller'); ?></button>
        </form>

        <form id="arvrs-panel-register" role="tabpanel" aria-labelledby="arvrs-tab-register" hidden
              method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_register" />
          <?php wp_nonce_field('arvrs_auth', 'arvrs_nonce'); ?>
          <div class="arvrs-field">
            <label for="arvrs-reg-name"><?php esc_html_e('نام و نام خانوادگی', 'arvan-reseller'); ?></label>
            <input id="arvrs-reg-name" name="display_name" type="text" required autocomplete="name" />
          </div>
          <div class="arvrs-field">
            <label for="arvrs-reg-email"><?php esc_html_e('ایمیل', 'arvan-reseller'); ?></label>
            <input id="arvrs-reg-email" name="email" type="email" required dir="ltr" autocomplete="email" />
          </div>
          <div class="arvrs-field">
            <label for="arvrs-reg-pass"><?php esc_html_e('گذرواژه (دست‌کم ۸ نویسه)', 'arvan-reseller'); ?></label>
            <input id="arvrs-reg-pass" name="password" type="password" required minlength="8" dir="ltr" autocomplete="new-password" />
          </div>
          <button type="submit" class="arvrs-btn arvrs-btn-primary arvrs-btn-block"><?php esc_html_e('ساخت حساب', 'arvan-reseller'); ?></button>
        </form>

        <p class="arvrs-center arvrs-muted" style="margin:20px 0 0"><a href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('بازگشت به فروشگاه', 'arvan-reseller'); ?></a></p>
      </div>
    </div>
  </div>
</div>
