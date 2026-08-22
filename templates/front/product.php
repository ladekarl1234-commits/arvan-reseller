<?php
/**
 * Product configuration + purchase page (ابرآروان design system).
 * @var string $product @var string $product_label @var array $plans
 * @var array $options @var bool $logged_in @var array $urls
 */
defined('ABSPATH') || exit;

$current = $product;
include __DIR__ . '/partials/shell-top.php';
?>
<nav class="arvrs-breadcrumb" aria-label="<?php esc_attr_e('مسیر', 'arvan-reseller'); ?>">
  <a href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('فروشگاه', 'arvan-reseller'); ?></a>
  <span aria-hidden="true">‹</span>
  <b><?php echo esc_html($product_label); ?></b>
</nav>

<div class="arvrs-title-row">
  <h2 class="arvrs-h1"><?php echo esc_html($product_label); ?></h2>
  <?php // A selling point is a positive claim: it gets the success token, not
        // the same red the system uses for "suspended" (EX-096). ?>
  <span class="arvrs-tag arvrs-tag-success"><?php esc_html_e('تحویل آنی', 'arvan-reseller'); ?></span>
</div>

<?php if (empty($plans)) : ?>
  <div class="arvrs-alert arvrs-alert-warning" role="alert">
    <span class="arvrs-alert-mark" aria-hidden="true">!</span>
    <div class="arvrs-alert-body">
      <strong><?php esc_html_e('پلن‌ها موقتاً در دسترس نیستند', 'arvan-reseller'); ?></strong>
      <p><?php esc_html_e('دریافت فهرست پلن‌ها با مشکل مواجه شد. چند لحظه دیگر دوباره تلاش کنید.', 'arvan-reseller'); ?></p>
    </div>
    <a class="arvrs-btn arvrs-btn-secondary" href="<?php echo esc_url(add_query_arg([])); ?>"><?php esc_html_e('تلاش دوباره', 'arvan-reseller'); ?></a>
  </div>
<?php else : ?>

<form id="arvrs-order-form" class="arvrs-order-layout" data-product="<?php echo esc_attr($product); ?>">
  <section class="arvrs-plans" role="radiogroup" aria-label="<?php esc_attr_e('انتخاب پلن', 'arvan-reseller'); ?>">
    <?php foreach ($plans as $i => $plan) : $featured = ($i === 1 && count($plans) >= 3); ?>
      <label class="arvrs-plan-card">
        <input type="radio" name="plan_id" value="<?php echo esc_attr($plan['id']); ?>" <?php checked($i, 0); ?>
               data-price-label="<?php echo esc_attr($plan['price_label']); ?>" />
        <span class="arvrs-plan-body">
          <span class="arvrs-plan-head">
            <span class="arvrs-plan-dot" aria-hidden="true"></span>
            <span class="arvrs-plan-name"><?php echo esc_html($plan['name']); ?></span>
            <?php if ($featured) : ?><span class="arvrs-plan-tag is-featured"><?php esc_html_e('پیشنهاد ما', 'arvan-reseller'); ?></span><?php endif; ?>
            <span class="arvrs-plan-price"><?php echo esc_html($plan['price_label']); ?> <span>/ <?php esc_html_e('ماهانه', 'arvan-reseller'); ?></span></span>
          </span>
          <span class="arvrs-plan-specs">
            <?php foreach ($plan['specs'] as $spec_key => $spec_value) : ?>
              <span><span class="arvrs-spec-k"><?php echo esc_html($spec_key); ?>:</span> <?php echo esc_html($spec_value); ?></span>
            <?php endforeach; ?>
          </span>
        </span>
      </label>
    <?php endforeach; ?>
  </section>

  <aside class="arvrs-config">
    <h2 class="arvrs-card-title"><?php esc_html_e('پیکربندی سرویس', 'arvan-reseller'); ?></h2>

    <?php if ($product === 'cloud_server') : ?>
      <div class="arvrs-field">
        <label for="arvrs-region"><?php esc_html_e('مرکز داده', 'arvan-reseller'); ?></label>
        <select id="arvrs-region" name="region" required>
          <?php foreach (($options['regions'] ?? []) as $region) : ?>
            <option value="<?php echo esc_attr($region['id']); ?>"><?php echo esc_html($region['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="arvrs-field">
        <label for="arvrs-image"><?php esc_html_e('سیستم‌عامل', 'arvan-reseller'); ?></label>
        <select id="arvrs-image" name="image" required>
          <?php foreach (($options['images'] ?? []) as $image) : ?>
            <option value="<?php echo esc_attr($image['id']); ?>"><?php echo esc_html($image['name']); ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="arvrs-field">
        <label for="arvrs-name"><?php esc_html_e('نام سرور (اختیاری)', 'arvan-reseller'); ?></label>
        <input id="arvrs-name" name="name" type="text" maxlength="50" placeholder="my-server" dir="ltr" />
      </div>
    <?php elseif ($product === 'cdn') : ?>
      <div class="arvrs-field">
        <label for="arvrs-domain"><?php esc_html_e('نام دامنه', 'arvan-reseller'); ?></label>
        <input id="arvrs-domain" name="domain" type="text" required dir="ltr" inputmode="url"
               placeholder="example.ir" pattern="^([a-zA-Z0-9]([a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$" />
        <p class="arvrs-field-hint"><?php esc_html_e('دامنه‌ای که می‌خواهید پشت CDN قرار گیرد.', 'arvan-reseller'); ?></p>
      </div>
    <?php elseif ($product === 'object_storage') : ?>
      <div class="arvrs-field">
        <label for="arvrs-bucket"><?php esc_html_e('نام باکت', 'arvan-reseller'); ?></label>
        <input id="arvrs-bucket" name="bucket" type="text" required dir="ltr"
               placeholder="my-bucket" pattern="^[a-z0-9][a-z0-9\-]{2,62}$" />
        <p class="arvrs-field-hint"><?php esc_html_e('۳ تا ۶۳ نویسه؛ حروف کوچک انگلیسی، عدد و خط تیره.', 'arvan-reseller'); ?></p>
      </div>
    <?php endif; ?>

    <div class="arvrs-order-summary">
      <span class="arvrs-muted"><?php esc_html_e('مبلغ قابل پرداخت', 'arvan-reseller'); ?></span>
      <strong id="arvrs-total"><?php echo esc_html($plans[0]['price_label'] ?? ''); ?></strong>
    </div>

    <?php if ($logged_in) : ?>
      <button type="submit" class="arvrs-btn arvrs-btn-primary arvrs-btn-block" id="arvrs-buy" data-label="<?php esc_attr_e('ادامه و پرداخت', 'arvan-reseller'); ?>">
        <?php esc_html_e('ادامه و پرداخت', 'arvan-reseller'); ?>
      </button>
      <p class="arvrs-error" id="arvrs-order-error" role="alert" hidden></p>
    <?php else : ?>
      <a class="arvrs-btn arvrs-btn-primary arvrs-btn-block" href="<?php echo esc_url($urls['auth']); ?>">
        <?php esc_html_e('برای خرید وارد شوید', 'arvan-reseller'); ?>
      </a>
    <?php endif; ?>
    <p class="arvrs-field-hint"><?php esc_html_e('قیمت نهایی به صورت ماهانه است و سرویس بلافاصله پس از پرداخت تحویل می‌شود.', 'arvan-reseller'); ?></p>
  </aside>
</form>
<?php endif; ?>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
