<?php
/** @var string $error @var string $notice @var array $urls */
defined('ABSPATH') || exit;

include __DIR__ . '/partials/shell-top.php';
?>
<div class="arvrs-auth-wrap">
  <div class="arvrs-card arvrs-auth-card">
    <div class="arvrs-tabs" role="tablist">
      <button class="arvrs-tab is-active" id="arvrs-tab-login" role="tab" aria-selected="true" aria-controls="arvrs-panel-login"><?php esc_html_e('ورود', 'arvan-reseller'); ?></button>
      <button class="arvrs-tab" id="arvrs-tab-register" role="tab" aria-selected="false" aria-controls="arvrs-panel-register"><?php esc_html_e('ثبت‌نام', 'arvan-reseller'); ?></button>
    </div>

    <?php if ($error) : ?>
      <div class="arvrs-alert arvrs-alert-danger" role="alert"><?php echo esc_html($error); ?></div>
    <?php endif; ?>
    <?php if ($notice) : ?>
      <div class="arvrs-alert arvrs-alert-info" role="status"><?php echo esc_html($notice); ?></div>
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
  </div>
</div>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
