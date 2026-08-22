<?php
/**
 * Sign in / sign up. Both panels are server-rendered; the tabs are progressive
 * enhancement, and the server picks which one opens so a failed registration
 * comes back to the register panel with its values intact (EX-066).
 *
 * @var string $error @var string $notice @var string $tab @var array $prefill
 * @var bool $registration_open @var array $urls
 * @var string $brand_name @var string $brand_logo
 */
defined('ABSPATH') || exit;

$brand_initial = function_exists('mb_substr') ? mb_substr($brand_name, 0, 1, 'UTF-8') : substr($brand_name, 0, 1);
$arvrs_dir  = is_rtl() ? 'rtl' : 'ltr';
$arvrs_lang = str_replace('_', '-', get_locale());

$tab               = (isset($tab) && $tab === 'register') ? 'register' : 'login';
$prefill           = isset($prefill) && is_array($prefill) ? $prefill : ['display_name' => '', 'email' => ''];
$registration_open = !isset($registration_open) || $registration_open;
if (!$registration_open) {
    $tab = 'login';
}
$on_register = $tab === 'register';
?>
<div class="arvrs-app" dir="<?php echo esc_attr($arvrs_dir); ?>" lang="<?php echo esc_attr($arvrs_lang); ?>">
  <div class="arvrs-auth">
    <aside class="arvrs-auth-aside">
      <div class="arvrs-auth-aside-body">
        <span class="arvrs-auth-mark" aria-hidden="true"><?php echo esc_html($brand_initial ?: 'ا'); ?></span>
        <h2 class="arvrs-h1"><?php echo esc_html(sprintf(__('به فروشگاه ابری %s خوش آمدید', 'arvan-reseller'), $brand_name)); ?></h2>
        <p><?php esc_html_e('سرور ابری، CDN و فضای ذخیره‌سازی — با تحویل خودکار و پرداخت ریالی، در چند دقیقه.', 'arvan-reseller'); ?></p>
      </div>
    </aside>

    <div class="arvrs-auth-panel">
      <div class="arvrs-auth-card">
        <?php if ($registration_open) : ?>
          <div class="arvrs-auth-switch" role="tablist">
            <button type="button" class="arvrs-tab <?php echo $on_register ? '' : 'is-active'; ?>" id="arvrs-tab-login" role="tab"
                    aria-selected="<?php echo $on_register ? 'false' : 'true'; ?>" tabindex="<?php echo $on_register ? '-1' : '0'; ?>"
                    aria-controls="arvrs-panel-login"><?php esc_html_e('ورود', 'arvan-reseller'); ?></button>
            <button type="button" class="arvrs-tab <?php echo $on_register ? 'is-active' : ''; ?>" id="arvrs-tab-register" role="tab"
                    aria-selected="<?php echo $on_register ? 'true' : 'false'; ?>" tabindex="<?php echo $on_register ? '0' : '-1'; ?>"
                    aria-controls="arvrs-panel-register"><?php esc_html_e('ثبت‌نام', 'arvan-reseller'); ?></button>
          </div>
        <?php endif; ?>

        <?php if ($error) : ?>
          <div class="arvrs-alert arvrs-alert-danger" role="alert">
            <span class="arvrs-alert-mark" aria-hidden="true">!</span>
            <div class="arvrs-alert-body"><strong><?php echo esc_html($error); ?></strong></div>
          </div>
        <?php endif; ?>
        <?php if ($notice) : ?>
          <div class="arvrs-alert arvrs-alert-info" role="status">
            <span class="arvrs-alert-mark" aria-hidden="true">i</span>
            <div class="arvrs-alert-body"><strong><?php echo esc_html($notice); ?></strong></div>
          </div>
        <?php endif; ?>

        <form id="arvrs-panel-login" role="tabpanel" aria-labelledby="arvrs-tab-login" <?php echo $on_register ? 'hidden' : ''; ?>
              method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="arvrs_login" />
          <?php wp_nonce_field('arvrs_auth', 'arvrs_nonce'); ?>
          <div class="arvrs-field">
            <label for="arvrs-login-email"><?php esc_html_e('ایمیل', 'arvan-reseller'); ?></label>
            <input id="arvrs-login-email" name="email" type="email" required dir="ltr" autocomplete="email"
                   value="<?php echo esc_attr($prefill['email']); ?>" />
          </div>
          <div class="arvrs-field">
            <label for="arvrs-login-pass"><?php esc_html_e('گذرواژه', 'arvan-reseller'); ?></label>
            <input id="arvrs-login-pass" name="password" type="password" required dir="ltr" autocomplete="current-password" />
          </div>
          <button type="submit" class="arvrs-btn arvrs-btn-primary arvrs-btn-block"><?php esc_html_e('ورود به حساب', 'arvan-reseller'); ?></button>
          <?php // WordPress owns password recovery; the storefront just has to offer the door (EX-066). ?>
          <p class="arvrs-center arvrs-auth-link">
            <a href="<?php echo esc_url($urls['lost_password']); ?>"><?php esc_html_e('گذرواژه‌تان را فراموش کرده‌اید؟', 'arvan-reseller'); ?></a>
          </p>
        </form>

        <?php if ($registration_open) : ?>
          <form id="arvrs-panel-register" role="tabpanel" aria-labelledby="arvrs-tab-register" <?php echo $on_register ? '' : 'hidden'; ?>
                method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="arvrs_register" />
            <?php wp_nonce_field('arvrs_auth', 'arvrs_nonce'); ?>
            <div class="arvrs-field">
              <label for="arvrs-reg-name"><?php esc_html_e('نام و نام خانوادگی', 'arvan-reseller'); ?></label>
              <input id="arvrs-reg-name" name="display_name" type="text" required autocomplete="name"
                     value="<?php echo esc_attr($prefill['display_name']); ?>" />
            </div>
            <div class="arvrs-field">
              <label for="arvrs-reg-email"><?php esc_html_e('ایمیل', 'arvan-reseller'); ?></label>
              <input id="arvrs-reg-email" name="email" type="email" required dir="ltr" autocomplete="email"
                     value="<?php echo esc_attr($prefill['email']); ?>" />
            </div>
            <div class="arvrs-field">
              <label for="arvrs-reg-pass"><?php esc_html_e('گذرواژه (دست‌کم ۸ نویسه)', 'arvan-reseller'); ?></label>
              <input id="arvrs-reg-pass" name="password" type="password" required minlength="8" dir="ltr" autocomplete="new-password" />
            </div>
            <button type="submit" class="arvrs-btn arvrs-btn-primary arvrs-btn-block"><?php esc_html_e('ساخت حساب', 'arvan-reseller'); ?></button>
          </form>
        <?php else : ?>
          <p class="arvrs-muted arvrs-center"><?php esc_html_e('ثبت‌نام مشتری جدید در حال حاضر غیرفعال است.', 'arvan-reseller'); ?></p>
        <?php endif; ?>

        <p class="arvrs-center arvrs-auth-link"><a href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('بازگشت به فروشگاه', 'arvan-reseller'); ?></a></p>
      </div>
    </div>
  </div>
</div>
