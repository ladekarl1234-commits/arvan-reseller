<?php
/** @var string $brand_name @var string $support_email @var string $support_phone @var string $brand_desc */
defined('ABSPATH') || exit;
?>
  </div>
  <footer class="arvrs-footer">
    <div>
      <strong><?php echo esc_html($brand_name); ?></strong>
      <p class="arvrs-muted"><?php echo esc_html($brand_desc ?: __('زیرساخت ابری برای کسب‌وکار شما', 'arvan-reseller')); ?></p>
    </div>
    <div class="arvrs-footer-contact">
      <?php if ($support_email) : ?>
        <a href="<?php echo esc_url('mailto:' . $support_email); ?>"><?php echo esc_html($support_email); ?></a>
      <?php endif; ?>
      <?php if ($support_phone) : ?>
        <span dir="ltr"><?php echo esc_html($support_phone); ?></span>
      <?php endif; ?>
    </div>
  </footer>
</div>
