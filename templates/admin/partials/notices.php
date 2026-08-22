<?php
/**
 * One-shot admin messages.
 *
 * These used to be read straight out of `?arvrs_notice=` / `?arvrs_error=`,
 * which meant anyone could hand an administrator a link that rendered
 * arbitrary text inside a first-party WordPress banner — on the same screen
 * as the ArvanCloud token form. Nothing from the request reaches this sink
 * any more: the text comes from Admin\Flash, written server-side by the
 * action that redirected here.
 *
 * @var string $notice @var string $error (supplied by Menu::render / Flash::take)
 */
defined('ABSPATH') || exit;

$arvrs_notice = isset($notice) ? (string) $notice : '';
$arvrs_error  = isset($error) ? (string) $error : '';

if ($arvrs_notice !== '') : ?>
  <div class="notice notice-success is-dismissible"><p><?php echo esc_html($arvrs_notice); ?></p></div>
<?php endif;
if ($arvrs_error !== '') : ?>
  <div class="notice notice-error is-dismissible"><p><?php echo esc_html($arvrs_error); ?></p></div>
<?php endif; ?>
