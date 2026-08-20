<?php
/**
 * Customer dashboard (spec: customer dashboard). Server-rendered tabs via
 * ?tab= links; JS only for top-up and mark-read.
 * @var \WP_User $user @var string $stage @var array $services @var array $orders
 * @var array $ledger @var array $usage @var array $notifications @var string $tab
 * @var array|null $balance @var array $urls
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

$badge = static function (string $status): string {
    return Helpers::status_tag($status);
};
?>
<div class="arvrs-dash-head">
  <div>
    <h1 class="arvrs-page-title"><?php echo esc_html(sprintf(__('سلام، %s', 'arvan-reseller'), $user->display_name)); ?></h1>
    <p class="arvrs-muted"><?php esc_html_e('وضعیت سرویس‌ها و حساب شما در یک نگاه.', 'arvan-reseller'); ?></p>
  </div>
  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
    <input type="hidden" name="action" value="arvrs_logout" />
    <?php wp_nonce_field('arvrs_logout', 'arvrs_nonce'); ?>
    <button type="submit" class="arvrs-btn arvrs-btn-ghost"><?php esc_html_e('خروج', 'arvan-reseller'); ?></button>
  </form>
</div>

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
    <strong><?php echo esc_html($alert_text); ?></strong>
    <a class="arvrs-btn arvrs-btn-secondary" href="<?php echo esc_url(add_query_arg('tab', 'wallet', $dashboard_url)); ?>"><?php esc_html_e('شارژ کیف پول', 'arvan-reseller'); ?></a>
  </div>
<?php endif; ?>

<nav class="arvrs-tabs arvrs-dash-tabs" aria-label="<?php esc_attr_e('بخش‌های پیشخوان', 'arvan-reseller'); ?>">
  <?php foreach ($tabs as $key => $label) : ?>
    <a class="arvrs-tab <?php echo $tab === $key ? 'is-active' : ''; ?>"
       href="<?php echo esc_url(add_query_arg('tab', $key, $dashboard_url)); ?>"
       <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?>
       <?php if ($key === 'inbox' && $unread) : ?><span class="arvrs-badge-dot"><?php echo esc_html(Helpers::fa_digits((string) $unread)); ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php if ($tab === 'overview') : ?>
  <div class="arvrs-grid arvrs-grid-3 arvrs-stat-grid">
    <div class="arvrs-card arvrs-stat">
      <span class="arvrs-muted"><?php esc_html_e('اعتبار قابل استفاده', 'arvan-reseller'); ?></span>
      <strong dir="rtl"><?php echo esc_html(Helpers::money((int) $balance['available'])); ?></strong>
    </div>
    <div class="arvrs-card arvrs-stat">
      <span class="arvrs-muted"><?php esc_html_e('سرویس‌های فعال', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::fa_digits((string) count($services))); ?></strong>
    </div>
    <div class="arvrs-card arvrs-stat">
      <span class="arvrs-muted"><?php esc_html_e('مجموع مصرف', 'arvan-reseller'); ?></span>
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
        <thead><tr><th><?php esc_html_e('سرویس', 'arvan-reseller'); ?></th><th><?php esc_html_e('پلن', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($services, 0, 5) as $service) : ?>
          <tr>
            <td><?php echo esc_html($service['label']); ?></td>
            <td dir="ltr"><?php echo esc_html($service['plan_id']); ?></td>
            <td><?php echo $badge((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in closure ?></td>
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
            <div>
              <strong><?php echo esc_html($service['label']); ?></strong>
              <span class="arvrs-muted" dir="ltr"><?php echo esc_html($service['plan_id']); ?></span>
            </div>
            <?php echo $badge((string) $service['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
          </div>
          <?php if (!empty($service['connection'])) : ?>
            <dl class="arvrs-kv">
              <?php foreach ($service['connection'] as $conn_key => $conn_value) : if (!$conn_value) { continue; } ?>
                <div>
                  <dt><?php echo esc_html($conn_key); ?></dt>
                  <dd dir="ltr"><?php echo esc_html($conn_value); ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          <?php endif; ?>
          <p class="arvrs-field-hint">
            <?php echo esc_html(sprintf(__('تاریخ ایجاد: %s', 'arvan-reseller'), Helpers::fa_digits((string) $service['created_at']))); ?>
          </p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

<?php elseif ($tab === 'orders') : ?>
  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('سفارش‌های اخیر', 'arvan-reseller'); ?></h2>
    <?php if (empty($orders)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('سفارشی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr><th>#</th><th><?php esc_html_e('محصول', 'arvan-reseller'); ?></th><th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('وضعیت', 'arvan-reseller'); ?></th><th><?php esc_html_e('تاریخ', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($orders as $order) : ?>
          <tr>
            <td><?php echo esc_html(Helpers::fa_digits((string) $order['id'])); ?></td>
            <td><?php echo esc_html(Catalog::product_label((string) $order['product'])); ?></td>
            <td><?php echo esc_html(Helpers::money((int) $order['amount'])); ?></td>
            <td><?php echo $badge((string) $order['status']); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
            <td class="arvrs-muted"><?php echo esc_html(Helpers::fa_digits((string) $order['created_at'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'wallet') : ?>
  <div class="arvrs-grid arvrs-grid-2">
    <div class="arvrs-card">
      <h2 class="arvrs-card-title"><?php esc_html_e('شارژ کیف پول', 'arvan-reseller'); ?></h2>
      <p class="arvrs-muted"><?php echo esc_html(sprintf(__('اعتبار فعلی: %s', 'arvan-reseller'), Helpers::money((int) $balance['available']))); ?></p>
      <div class="arvrs-field">
        <label for="arvrs-topup-amount"><?php esc_html_e('مبلغ (تومان)', 'arvan-reseller'); ?></label>
        <input id="arvrs-topup-amount" type="number" min="100000" step="100000" value="1000000" dir="ltr" />
        <p class="arvrs-field-hint"><?php esc_html_e('حداقل ۱۰۰٬۰۰۰ تومان.', 'arvan-reseller'); ?></p>
      </div>
      <button class="arvrs-btn arvrs-btn-primary arvrs-btn-block" id="arvrs-topup-btn"><?php esc_html_e('پرداخت و شارژ', 'arvan-reseller'); ?></button>
      <p class="arvrs-error" id="arvrs-topup-error" role="alert" hidden></p>
    </div>
    <div class="arvrs-card arvrs-stat">
      <span class="arvrs-muted"><?php esc_html_e('مجموع شارژها', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::money((int) $balance['topup_total'])); ?></strong>
      <span class="arvrs-muted"><?php esc_html_e('مجموع مصرف و خرید', 'arvan-reseller'); ?></span>
      <strong><?php echo esc_html(Helpers::money((int) $balance['consumed'])); ?></strong>
    </div>
  </div>

  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('گردش حساب', 'arvan-reseller'); ?></h2>
    <?php if (empty($ledger)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('تراکنشی ثبت نشده است.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr><th><?php esc_html_e('شرح', 'arvan-reseller'); ?></th><th><?php esc_html_e('مبلغ', 'arvan-reseller'); ?></th><th><?php esc_html_e('تاریخ', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($ledger as $entry) : ?>
          <tr>
            <td><?php echo esc_html($entry['description'] ?: $entry['type']); ?></td>
            <td class="<?php echo $entry['direction'] === 'credit' ? 'arvrs-amount-credit' : 'arvrs-amount-debit'; ?>">
              <?php echo esc_html(($entry['direction'] === 'credit' ? '+' : '−') . ' ' . Helpers::money((int) $entry['amount'])); ?>
            </td>
            <td class="arvrs-muted"><?php echo esc_html(Helpers::fa_digits((string) $entry['created_at'])); ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

<?php elseif ($tab === 'usage') : ?>
  <div class="arvrs-card">
    <h2 class="arvrs-card-title"><?php esc_html_e('ریز مصرف سرویس‌ها', 'arvan-reseller'); ?></h2>
    <?php if (empty($usage)) : ?>
      <p class="arvrs-muted"><?php esc_html_e('هنوز مصرفی ثبت نشده است. مصرف سرویس‌ها به‌صورت دوره‌ای همگام‌سازی می‌شود.', 'arvan-reseller'); ?></p>
    <?php else : ?>
      <div class="arvrs-table-wrap"><table class="arvrs-table">
        <thead><tr><th><?php esc_html_e('سرویس', 'arvan-reseller'); ?></th><th><?php esc_html_e('بازه', 'arvan-reseller'); ?></th><th><?php esc_html_e('هزینه', 'arvan-reseller'); ?></th></tr></thead>
        <tbody>
        <?php foreach ($usage as $row) : ?>
          <tr>
            <td><?php echo esc_html(Catalog::product_label((string) $row['product'])); ?> <span class="arvrs-muted" dir="ltr"><?php echo esc_html($row['plan_id']); ?></span></td>
            <td class="arvrs-muted" dir="ltr"><?php echo esc_html(substr((string) $row['period_start'], 5, 11) . ' → ' . substr((string) $row['period_end'], 11, 5)); ?></td>
            <td><?php echo esc_html(Helpers::money((int) $row['cost'])); ?></td>
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
        <div class="arvrs-card arvrs-notification <?php echo $note['is_read'] ? 'is-read' : 'is-unread'; ?>" data-id="<?php echo esc_attr($note['id']); ?>">
          <div class="arvrs-service-head">
            <strong><?php echo esc_html($note['title']); ?></strong>
            <span class="arvrs-muted"><?php echo esc_html(Helpers::fa_digits((string) $note['created_at'])); ?></span>
          </div>
          <p><?php echo esc_html($note['body']); ?></p>
          <?php if (!$note['is_read']) : ?>
            <button class="arvrs-btn arvrs-btn-ghost arvrs-mark-read" data-id="<?php echo esc_attr($note['id']); ?>"><?php esc_html_e('خواندم', 'arvan-reseller'); ?></button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php include __DIR__ . '/partials/shell-bottom.php'; ?>
