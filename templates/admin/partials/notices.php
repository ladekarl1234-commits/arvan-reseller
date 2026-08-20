<?php
defined('ABSPATH') || exit;
// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flash messages
$arvrs_notice = isset($_GET['arvrs_notice']) ? sanitize_text_field(wp_unslash($_GET['arvrs_notice'])) : '';
$arvrs_error  = isset($_GET['arvrs_error']) ? sanitize_text_field(wp_unslash($_GET['arvrs_error'])) : '';
// phpcs:enable
if ($arvrs_notice) : ?>
  <div class="notice notice-success is-dismissible"><p><?php echo esc_html($arvrs_notice); ?></p></div>
<?php endif;
if ($arvrs_error) : ?>
  <div class="notice notice-error is-dismissible"><p><?php echo esc_html($arvrs_error); ?></p></div>
<?php endif; ?>
