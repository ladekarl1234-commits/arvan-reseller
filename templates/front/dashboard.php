<?php
/**
 * Customer dashboard (ابرآروان design system). Server-rendered tabs via ?tab=.
 * @var \WP_User $user @var string $stage @var array $services @var array $orders
 * @var array $unpaid @var array $ledger @var array $usage @var array $notifications
 * @var string $tab @var string $notice @var string $error
 * @var array|null $balance @var array $urls @var int $unread
 */
defined('ABSPATH') || exit;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Support\Helpers;

include __DIR__ . '/partials/shell-top.php';

$tabs = [
    'overview' => __('نمای کلی', 'arvan-reseller'),
    'services' => __('سرویس‌ها', 'arvan-reseller'),
    'orders'   => __('سفارش‌ها', 'arvan-reseller'),
    'wallet'   => __('کیف پول', 'arvan-reseller'),
    'usage'    => __('مصرف', 'arvan-reseller'),
    'inbox'    => __('اعلان‌ها', 'arvan-reseller'),
];
if (!isset($tabs[$tab])) {
    $tab = 'overview';
}
$dashboard_url = $urls['dashboard'];
$unpaid        = isset($unpaid) && is_array($unpaid) ? $unpaid : [];
$notice        = isset($notice) ? (string) $notice : '';
$error         = isset($error) ? (string) $error : '';
$badge = static function (string $status): string { return Helpers::status_tag($status); };
?>
<div class="arvrs-dash-head">
  <div>
    <h2 class="arvrs-h1"><?php echo esc_html(sprintf(__('سلام، %s', 'arvan-reseller'), $user->display_name)); ?></h2>
    <p><?php esc_html_e('وضعیت سرویس‌ها و حساب شما در یک نگاه.', 'arvan-reseller'); ?></p>
  </div>
  <div class="arvrs-inline-actions">
    <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['storefront']); ?>">+ <?php esc_html_e('سرویس جدید', 'arvan-reseller'); ?></a>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
      <input type="hidden" name="action" value="arvrs_logout" />
      <?php wp_nonce_field('arvrs_logout', 'arvrs_nonce'); ?>
      <button type="submit" class="arvrs-btn arvrs-btn-ghost"><?php esc_html_e('خروج', 'arvan-reseller'); ?></button>
    </form>
  </div>
</div>

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

<?php if (!empty($unpaid)) : ?>
  <?php // An abandoned checkout used to have no route back into the product (EX-018). ?>
  <div class="arvrs-alert arvrs-alert-warning" role="status">
    <span class="arvrs-alert-mark" aria-hidden="true">!</span>
    <div class="arvrs-alert-body">
      <strong><?php echo esc_html(sprintf(
          /* translators: %s: number of unpaid orders, already localised */
          __('%s سفارش در انتظار پرداخت دارید.', 'arvan-reseller'),
          Helpers::fa_digits((string) count($unpaid))
      )); ?></strong>
      <p><?php esc_html_e('سفارش‌های ثبت‌شده تا زمان پرداخت راه‌اندازی نمی‌شوند.', 'arvan-reseller'); ?></p>
    </div>
    <a class="arvrs-btn arvrs-btn-dark" href="<?php echo esc_url($urls['checkout']); ?>"><?php esc_html_e('تکمیل پرداخت', 'arvan-reseller'); ?></a>
  </div>
<?php endif; ?>

<?php if (in_array($stage, ['warning', 'critical', 'grace', 'restricted'], true)) : ?>
  <?php
  $stage_alerts = [
      'warning'    => ['warning', __('اعتبار شما رو به پایان است. برای جلوگیری از وقفه، کیف پول را شارژ کنید.', 'arvan-reseller')],
      'critical'   => ['danger', __('اعتبار شما بحرانی است. هم‌اکنون کیف پول را شارژ کنید.', 'arvan-reseller')],
      'grace'      => ['danger', __('اعتبار شما تمام شده است و در دوره مهلت هستید. در صورت عدم شارژ، سرویس‌ها محدود می‌شوند.', 'arvan-reseller')],
      'restricted' => ['danger', __('به دلیل اتمام اعتبار، خرید جدید غیرفعال و سرویس‌ها در معرض تعلیق هستند.', 'arvan-reseller')],
  ];
  [$alert_kind, $alert_text] = $stage_alerts[$stage];
  ?>
  <div class="arvrs-alert arvrs-alert-<?php echo esc_attr($alert_kind); ?>" role="alert">
    <span class="arvrs-alert-mark" aria-hidden="true">!</span>
    <div class="arvrs-alert-body"><strong><?php echo esc_html($alert_text); ?></strong></div>
    <a class="arvrs-btn arvrs-btn-dark" href="<?php echo esc_url(add_query_arg('tab', 'wallet', $dashboard_url)); ?>"><?php esc_html_e('شارژ کیف پول', 'arvan-reseller'); ?></a>
  </div>
<?php endif; ?>

<nav class="arvrs-tabs" aria-label="<?php esc_attr_e('بخش‌های پیشخوان', 'arvan-reseller'); ?>">
  <?php foreach ($tabs as $key => $label) : ?>
    <a class="arvrs-tab <?php echo $tab === $key ? 'is-active' : ''; ?>"
       href="<?php echo esc_url(add_query_arg('tab', $key, $dashboard_url)); ?>"
       <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?>
       <?php if ($key === 'inbox' && $unread) : ?><span class="arvrs-badge-dot"><?php echo esc_html(Helpers::fa_digits((string) $unread)); ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview') : ?>
  <div class="arvrs-stat-grid">
    <div class="arvrs-stat is-brand">
      <span class="arvrs-stat-label"><?php esc_html_e('اعتبار قابل استفاده', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::money((int) $balance['available'])); ?></strong>
    </div>
    <div class="arvrs-stat">
      <span class="arvrs-stat-label"><?php esc_html_e('سرویس‌های فعال', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::fa_digits((string) count($services))); ?></strong>
    </div>
    <div class="arvrs-stat">
      <span class="arvrs-stat-label"><?php esc_html_e('مجموع مصرف', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::money((int) $balance['consumed'])); ?></strong>
    </div>
  </div>

  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('آخرین سرویس‌ها', 'arvan-reseller'); ?></h2>
    <?php if (empty($services)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('هنوز سرویسی ندارید. اولین سرویس ابری خود را از فروشگاه تهیه کنید.', 'arvan-reseller'); ?></p>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('رفتن به فروشگاه', 'arvan-reseller'); ?></a>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr>
          <th><?php esc_html_e('سرویس', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('پلن', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('تمدید بعدی', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($services, 0, 5) as $service) : ?>
          <tr>
            <td class="is-strong"><?php echo esc_html($service['label']); ?></td>
            <td dir="ltr"><?php echo esc_html($service['plan_id']); ?></td>
            <td class="arvrs-muted"><?php
                echo esc_html(!empty($service['renews_at'])
                    ? Helpers::jdate((string) $service['renews_at'])
                    : __('بدون تمدید خودکار', 'arvan-reseller'));
            ?></td>
            <td><?php echo $badge((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'services') : ?>
  <?php if (empty($services)) : ?>
    <div class="arvrs-card arvrs-center arvrs-empty">
      <p class="arvrs-muted"><?php esc_html_e('هنوز سرویسی ندارید.', 'arvan-reseller'); ?></p>
      <a class="arvrs-btn arvrs-btn-primary" href="<?php echo esc_url($urls['storefront']); ?>"><?php esc_html_e('خرید سرویس', 'arvan-reseller'); ?></a>
    </div>
  <?php else : ?>
    <div class="arvrs-stack">
      <?php foreach ($services as $service) : ?>
        <div class="arvrs-card arvrs-service-card">
          <div class="arvrs-service-head">
            <div class="arvrs-inline-actions">
              <strong><?php echo esc_html($service['label']); ?></strong>
              <span class="arvrs-muted" dir="ltr"><?php echo esc_html($service['plan_id']); ?></span>
            </div>
            <?php echo $badge((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
          <?php if (!empty($service['connection'])) : ?>
            <div class="arvrs-kv">
              <?php foreach ($service['connection'] as $conn_key => $conn_value) : if (!$conn_value) { continue; } ?>
                <div>
                  <span class="arvrs-kv-k"><?php echo esc_html(Helpers::connection_label((string) $conn_key)); ?></span>
                  <strong class="arvrs-kv-v" dir="ltr"><?php echo esc_html($conn_value); ?></strong>
                  <button type="button" class="arvrs-copy" data-arvrs-copy="<?php echo esc_attr($conn_value); ?>"><?php esc_html_e('کپی', 'arvan-reseller'); ?></button>
                </div>
              <?php endforeach; ?>
            </div>
            <?php
            $hints = [
                'cloud_server'   => __('اتصال از طریق SSH با نام کاربری و نشانی IP بالا انجام می‌شود.', 'arvan-reseller'),
                'cdn'            => __('برای فعال شدن CDN، سرورهای نام دامنه را به مقادیر بالا تغییر دهید.', 'arvan-reseller'),
                'object_storage' => __('برای اتصال، از نشانی سرویس و کلید دسترسی در هر کلاینت سازگار با S3 استفاده کنید.', 'arvan-reseller'),
            ];
            ?>
            <?php if (isset($hints[$service['product']])) : ?>
              <p class="arvrs-field-hint"><?php echo esc_html($hints[$service['product']]); ?></p>
            <?php endif; ?>
          <?php endif; ?>

          <div class="arvrs-service-meta">
            <span><?php echo esc_html(sprintf(__('تاریخ ایجاد: %s', 'arvan-reseller'), Helpers::jdate((string) $service['created_at']))); ?></span>
            <?php if (!empty($service['renews_at'])) : ?>
              <span><?php echo esc_html(sprintf(
                  /* translators: 1: Jalali date, 2: formatted amount */
                  __('تمدید بعدی: %1$s — %2$s', 'arvan-reseller'),
                  Helpers::jdate((string) $service['renews_at']),
                  Helpers::money(isset($service['renewal_price']) ? (int) $service['renewal_price'] : 0)
              )); ?></span>
            <?php elseif (!empty($service['cancelled_at'])) : ?>
              <span><?php esc_html_e('تمدید خودکار لغو شده است.', 'arvan-reseller'); ?></span>
            <?php endif; ?>
          </div>

          <?php if (!empty($service['renews_at'])) : ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="arvrs_cancel_renewal" />
              <input type="hidden" name="service_id" value="<?php echo esc_attr((string) $service['id']); ?>" />
              <?php wp_nonce_field('arvrs_cancel_renewal', 'arvrs_nonce'); ?>
              <button type="submit" class="arvrs-btn arvrs-btn-ghost arvrs-btn-sm"><?php esc_html_e('لغو تمدید خودکار', 'arvan-reseller'); ?></button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'orders') : ?>
  <div class="arvrs-card">
    <div class="arvrs-card-head">
      <h2 class="arvrs-card-title"><?php esc_html_e('سفارش‌های اخیر', 'arvan-reseller'); ?></h2>
      <a class="arvrs-btn arvrs-btn-secondary arvrs-btn-sm" href="<?php echo esc_url($urls['checkout']); ?>"><?php esc_html_e('سفارش‌های در انتظار پرداخت', 'arvan-reseller'); ?></a>
    </div>
    <?php if (empty($orders)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('سفارشی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr><th>#</th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('تاریخ', 'arvan-reseller'); ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order) : ?>
          <tr>
            <td class="is-strong"><?php echo esc_html(Helpers::fa_digits((string) $order['id'])); ?></td>
            <td><?php echo esc_html(Catalog::product_label((string) $order['product'])); ?></td>
            <td class="is-strong"><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></td>
            <td><?php echo $badge((string) $order['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            <td class="arvrs-muted"><?php echo esc_html(Helpers::jdate((string) $order['created_at'], 'j F Y — H:i')); ?></td>
            <td>
              <?php if (in_array($order['status'], ['pending_payment', 'payment_processing'], true)) : ?>
                <a class="arvrs-btn arvrs-btn-primary arvrs-btn-sm" href="<?php echo esc_url($urls['checkout']); ?>"><?php esc_html_e('پرداخت', 'arvan-reseller'); ?></a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'wallet') : ?>
  <div class="arvrs-grid arvrs-grid-2 arvrs-grid-gap">
    <div class="arvrs-card">
      <h2 class="arvrs-card-title"><?php esc_html_e('شارژ کیف پول', 'arvan-reseller'); ?></h2>
      <p class="arvrs-muted"><?php echo esc_html(sprintf(__('اعتبار فعلی: %s', 'arvan-reseller'), Helpers::money((int) $balance['available']))); ?></p>
      <div class="arvrs-field">
        <label for="arvrs-topup-amount"><?php esc_html_e('مبلغ (تومان)', 'arvan-reseller'); ?></label>
        <input id="arvrs-topup-amount" type="number" min="100000" step="100000" value="1000000" dir="ltr" />
        <p class="arvrs-field-hint"><?php esc_html_e('حداقل ۱۰۰٬۰۰۰ تومان.', 'arvan-reseller'); ?></p>
      </div>
      <button type="button" class="arvrs-btn arvrs-btn-primary arvrs-btn-block" id="arvrs-topup-btn"
              data-label="<?php esc_attr_e('پرداخت و شارژ', 'arvan-reseller'); ?>"><?php esc_html_e('پرداخت و شارژ', 'arvan-reseller'); ?></button>
      <p class="arvrs-error" id="arvrs-topup-error" role="alert" hidden></p>
    </div>
    <div class="arvrs-card arvrs-stat is-brand arvrs-stat-split">
      <div><span class="arvrs-stat-label"><?php esc_html_e('مجموع شارژها', 'arvan-reseller'); ?></span><div class="arvrs-stat-value"><?php echo esc_html(Helpers::money((int) $balance['topup_total'])); ?></div></div>
      <div class="arvrs-stat-rule" aria-hidden="true"></div>
      <div><span class="arvrs-stat-label"><?php esc_html_e('مجموع مصرف و خرید', 'arvan-reseller'); ?></span><div class="arvrs-stat-value"><?php echo esc_html(Helpers::money((int) $balance['consumed'])); ?></div></div>
    </div>
  </div>

  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('گردش حساب', 'arvan-reseller'); ?></h2>
    <?php if (empty($ledger)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('تراکنشی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <?php foreach ($ledger as $entry) : $credit = $entry['direction'] === 'credit'; ?>
        <div class="arvrs-ledger-row">
          <span class="arvrs-ledger-icon <?php echo $credit ? 'is-credit' : 'is-debit'; ?>" aria-hidden="true"><?php echo $credit ? '+' : '−'; ?></span>
          <div class="arvrs-ledger-body">
            <strong><?php echo esc_html($entry['description'] ?: $entry['type']); ?></strong>
            <div><?php echo esc_html(Helpers::jdate((string) $entry['created_at'], 'j F Y — H:i')); ?></div>
          </div>
          <strong class="<?php echo $credit ? 'arvrs-amount-credit' : 'arvrs-amount-debit'; ?>">
            <?php echo esc_html(($credit ? '+ ' : '− ') . Helpers::money((int) $entry['amount'])); ?>
          </strong>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'usage') : ?>
  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('ریز مصرف سرویس‌ها', 'arvan-reseller'); ?></h2>
    <?php if (empty($usage)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('هنوز مصرفی ثبت نشده است. مصرف سرویس‌ها به‌صورت دوره‌ای همگام‌سازی می‌شود.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr>
          <th><?php esc_html_e('سرویس', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('از تاریخ', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('تا تاریخ', 'arvan-reseller'); ?></th>
          <th><?php esc_html_e('هزینه', 'arvan-reseller'); ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($usage as $row) : ?>
          <tr>
            <td class="is-strong"><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?> <span class="arvrs-muted arvrs-plan-inline" dir="ltr"><?php echo esc_html($row['plan_id']); ?></span></td>
            <td class="arvrs-muted"><?php echo esc_html(Helpers::jdate((string) $row['period_start'], 'j F Y — H:i')); ?></td>
            <td class="arvrs-muted"><?php echo esc_html(Helpers::jdate((string) $row['period_end'], 'j F Y — H:i')); ?></td>
            <?php // The customer is billed `price`; `cost` is the reseller's upstream figure and is never shown here. ?>
            <td class="is-strong"><?php echo esc_html(Helpers::money((int) (isset($row['price']) && (int) $row['price'] > 0 ? $row['price'] : $row['cost']))); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'inbox') : ?>
  <div class="arvrs-stack">
    <?php if (empty($notifications)) : ?>
      <div class="arvrs-card arvrs-center arvrs-empty"><p class="arvrs-muted"><?php esc_html_e('اعلانی ندارید.', 'arvan-reseller'); ?></p></div>
    <?php else : ?>
      <?php foreach ($notifications as $note) : ?>
        <div class="arvrs-notification <?php echo $note['is_read'] ? 'is-read' : 'is-unread'; ?>" data-id="<?php echo esc_attr($note['id']); ?>">
          <div class="arvrs-service-head">
            <strong><?php echo esc_html($note['title']); ?></strong>
            <span class="arvrs-muted"><?php echo esc_html(Helpers::jdate((string) $note['created_at'], 'j F Y — H:i')); ?></span>
          </div>
          <p class="arvrs-notification-body"><?php echo esc_html($note['body']); ?></p>
          <?php if (!$note['is_read']) : ?>
            <button type="button" class="arvrs-btn arvrs-btn-secondary arvrs-btn-sm arvrs-mark-read" data-id="<?php echo esc_attr($note['id']); ?>"><?php esc_html_e('خواندم', 'arvan-reseller'); ?></button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
