<?php
namespace ArvanReseller\Provisioning;

use ArvanReseller\Arvan\Catalog;
use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Plugin;
use ArvanReseller\Services\Services;

defined('ABSPATH') || exit;

/**
 * Instant provisioning after verified payment (spec §5.4). Idempotency is
 * layered: (1) atomic order claim paid→provisioning, (2) service-row lookup
 * before any remote call, (3) UNIQUE(order_id) on the services table.
 * A refresh, replayed callback or retried job can never create a second
 * remote resource.
 *
 * The fourth layer is `reclaim_stale()`. Layers 1-3 make a *completed* run
 * safe to repeat; they do nothing for a run that was killed halfway, which is
 * the common failure (max_execution_time inside a chain of upstream calls).
 * That left the order in `provisioning` — a state nothing could claim from,
 * so the paid customer waited forever. Reclaiming is safe because the remote
 * name is derived from the order id (see RealProvider): a re-create adopts
 * the resource the dead worker started instead of buying a second one.
 */
final class Provisioner
{
    /** How long an order may sit in `provisioning` before it is presumed abandoned. */
    private const STALE_MINUTES = 20;

    /** Reclaim at most this many orders per sweep — a cron tick must stay short. */
    private const SWEEP_LIMIT = 50;

    /**
     * Provision one order. Never throws: the caller decides what to do with
     * the outcome, and the payment callback must not die on a provider fault.
     *
     * @return array{ok:bool,kind:string,message:string,service_id:int}
     *   kind:
     *     provisioned   — a remote resource was created; the order is ACTIVE
     *     already       — nothing to do: the service exists, or the order
     *                     reached a terminal state before the job ran
     *     not_claimable — another worker holds it, or it is mid-flight and not
     *                     yet stale; the caller should come back later
     *     not_found     — no such order
     *     failed        — permanent provider failure; the order is
     *                     PROVISION_FAILED and admin + customer were told
     *     retryable     — transient failure; retrying can still succeed
     *
     *   `message` is Persian customer-facing prose. Callers branch on `kind`,
     *   never on `message` — the previous runner matched English substrings
     *   that half these paths never produced.
     */
    public static function provision(int $order_id): array
    {
        $order = OrderService::get($order_id);
        if (!$order) {
            return self::result(false, 'not_found', __('سفارش یافت نشد.', 'arvan-reseller'));
        }

        // Idempotency layer 2: a service row means the remote resource is
        // already ours. Never call the provider again for it.
        $existing = Services::by_order($order_id);
        if ($existing) {
            OrderService::transition($order_id, (string) $order['status'], StateMachine::ACTIVE, 'provisioner', 'service already exists');
            return self::result(true, 'already', __('این سفارش پیش‌تر راه‌اندازی شده است.', 'arvan-reseller'), (int) $existing['id']);
        }

        // A cancelled or refunded order will never provision. Saying so once
        // is better than burning five retries and waking the admin with a
        // dead job for work that was deliberately called off.
        if (in_array((string) $order['status'], [StateMachine::CANCELLED, StateMachine::REFUNDED], true)) {
            return self::result(true, 'already', __('سفارش در وضعیت نهایی است و نیازی به راه‌اندازی ندارد.', 'arvan-reseller'));
        }

        // Layer 4: free an abandoned claim before trying to take it. The
        // service lookup above already proved nothing was created locally.
        if ((string) $order['status'] === StateMachine::PROVISIONING
            && self::reclaim_stale(self::STALE_MINUTES, $order_id) > 0) {
            $order = OrderService::get($order_id) ?: $order;
        }

        // Idempotency layer 1: claim the order. Losing the claim means another
        // worker owns it right now.
        $claimed = OrderService::transition($order_id, StateMachine::PAID, StateMachine::PROVISIONING, 'provisioner')
            || OrderService::transition($order_id, StateMachine::PROVISION_FAILED, StateMachine::PROVISIONING, 'provisioner', 'retry');
        if (!$claimed) {
            return self::result(false, 'not_claimable', sprintf(
                /* translators: %s: order status slug */
                __('سفارش در وضعیت «%s» قابل راه‌اندازی نیست؛ کمی بعد دوباره بررسی می‌شود.', 'arvan-reseller'),
                (string) $order['status']
            ));
        }

        $product    = (string) $order['product'];
        $config     = json_decode((string) $order['config'], true) ?: [];
        $credential = Plugin::demo_mode() ? null : Credentials::select_for($product);

        // Re-check right before the call that would adopt an existing remote
        // resource by name (RealProvider::create_cdn/create_bucket): the
        // order-time check in OrderService::create() closes the door for a
        // fresh order, but this is the point that actually reaches for
        // someone else's domain/bucket, so it gets its own guard against the
        // race of two orders for the same name landing close together.
        $conflict = self::name_conflict($product, (int) $order['customer_id'], $config);
        if ($conflict !== null) {
            return self::fail($order, new ProviderError('invalid', $conflict), $credential);
        }

        try {
            $resource = Plugin::arvan($product)->create(
                $product,
                (string) $order['plan_id'],
                $config,
                'order:' . $order_id
            );
        } catch (ProviderError $e) {
            return self::fail($order, $e, $credential);
        }

        global $wpdb;
        $service_id = Services::create_for_order(
            $order,
            $resource->remote_id,
            $resource->connection,
            isset($credential['id']) ? (int) $credential['id'] : null
        );
        if ($service_id <= 0 || $wpdb->last_error !== '') {
            // The remote resource exists and is billing upstream. Marking the
            // order ACTIVE here would hand the customer a dashboard with an
            // active order and no service, invisible to usage sync and to
            // reconciliation. Fall back to PROVISION_FAILED so the retry path
            // re-runs and adopts the same remote resource by its name.
            $error = $wpdb->last_error ?: 'insert returned 0';
            OrderService::transition($order_id, StateMachine::PROVISIONING, StateMachine::PROVISION_FAILED, 'provisioner', 'service insert failed: ' . $error);
            Notifier::admin('provision_failed',
                __('ثبت سرویس ناموفق بود', 'arvan-reseller'),
                sprintf(
                    /* translators: 1: order id, 2: remote resource id */
                    __('منبع سفارش #%1$d روی آروان ساخته شد (شناسه %2$s) اما ثبت آن در پایگاه‌داده انجام نشد. سفارش برای تلاش دوباره علامت خورد.', 'arvan-reseller'),
                    $order_id, $resource->remote_id
                ));
            Audit::error('provision.service_insert_failed', ['order' => $order_id, 'remote' => $resource->remote_id, 'error' => $error]);
            return self::result(false, 'retryable', __('ثبت سرویس با خطا مواجه شد؛ تلاش دوباره انجام می‌شود.', 'arvan-reseller'));
        }

        OrderService::transition($order_id, StateMachine::PROVISIONING, StateMachine::ACTIVE, 'provisioner', 'remote:' . $resource->remote_id);

        // create() returns as soon as the upstream accepted the request; it no
        // longer blocks polling for an address (that sleep loop was inside the
        // payment callback). A follow-up job completes the connection details.
        if ((string) $resource->status !== 'active') {
            JobRunner::enqueue('poll_service', ['service_id' => $service_id, 'attempt' => 1], 30);
        }

        Notifier::customer((int) $order['customer_id'], 'provisioned',
            __('سرویس شما آماده است', 'arvan-reseller'),
            sprintf(
                /* translators: %s: product label */
                __('سرویس «%s» با موفقیت راه‌اندازی شد و هم‌اکنون در پیشخوان شما قابل مشاهده است.', 'arvan-reseller'),
                Catalog::product_label($product)
            ));
        Audit::log(0, 'provision.success', 'service', (string) $service_id, ['order' => $order_id, 'remote' => $resource->remote_id]);

        // Lifecycle event so accounting/CRM integrations do not have to fork.
        do_action('arvrs_service_provisioned', $service_id, $order_id, $resource->remote_id);

        return self::result(true, 'provisioned', __('سرویس با موفقیت راه‌اندازی شد.', 'arvan-reseller'), $service_id);
    }

    /**
     * Move orders abandoned in `provisioning` back to a claimable state, and
     * re-arm orders sitting in `paid` with no job behind them.
     *
     * `provisioning` is the only non-terminal order state with no timeout of
     * its own: the state machine allows PROVISIONING→PROVISION_FAILED but
     * nothing except this method ever performs it, so a worker killed inside
     * the provisioning window stranded the order permanently.
     *
     * `paid` has the same hole from the other side: `claim_paid()` succeeds
     * before the provisioning job is enqueued, so a fatal/OOM/timeout in that
     * gap leaves the order `paid` with no job — and `paid` is not
     * `provisioning`, so nothing above ever swept it either. `paid` with no
     * service is recoverable work, not a failure, so it is re-enqueued rather
     * than failed.
     *
     * @param int $minutes  age of the last status change; 0 reclaims at once
     *                      (the admin's per-order «بازیابی» action)
     * @param int $order_id restrict to one order; 0 sweeps every stale order
     * @return int orders moved
     */
    public static function reclaim_stale(int $minutes = self::STALE_MINUTES, int $order_id = 0): int
    {
        $minutes = max(0, $minutes);
        $cutoff  = gmdate('Y-m-d H:i:s', time() - ($minutes * 60));

        return self::reclaim_stuck_provisioning($cutoff, $minutes, $order_id)
             + self::reclaim_stuck_paid($cutoff, $minutes, $order_id);
    }

    private static function reclaim_stuck_provisioning(string $cutoff, int $minutes, int $order_id): int
    {
        global $wpdb;
        $sql  = 'SELECT id FROM ' . OrderService::table() . ' WHERE status = %s AND updated_at <= %s';
        $args = [StateMachine::PROVISIONING, $cutoff];
        if ($order_id > 0) {
            $sql   .= ' AND id = %d';
            $args[] = $order_id;
        }
        $sql   .= ' ORDER BY id ASC LIMIT %d';
        $args[] = self::SWEEP_LIMIT;

        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args)) ?: [];

        $moved = 0;
        foreach ($ids as $id) {
            $id = (int) $id;

            // The work may actually have landed and only the final transition
            // been lost. Completing it is the honest repair; failing it would
            // send a second create at a resource we already own.
            if (Services::by_order($id)) {
                if (OrderService::transition($id, StateMachine::PROVISIONING, StateMachine::ACTIVE, 'reaper', 'service exists; completed a stale provisioning claim')) {
                    $moved++;
                    Audit::log(0, 'provision.completed_stale', 'order', (string) $id, [], 'info');
                }
                continue;
            }

            if (!OrderService::transition($id, StateMachine::PROVISIONING, StateMachine::PROVISION_FAILED, 'reaper', sprintf('stale: no progress for %d minutes', $minutes))) {
                continue; // someone else won the row between SELECT and UPDATE
            }
            $moved++;
            Audit::error('provision.reclaimed', ['order' => $id, 'stale_minutes' => $minutes]);
            // Reclaiming only makes the order claimable again — something has
            // to claim it. The enqueue is idempotent: provision() short-
            // circuits on an existing service row.
            JobRunner::enqueue('provision_order', ['order_id' => $id], 5);
        }

        return $moved;
    }

    /**
     * A `paid` order past the threshold never got a provisioning job (or lost
     * it) — re-enqueue rather than fail: nothing has gone wrong with the
     * order itself, only with the job that was supposed to work it. `paid` is
     * not a claimable-from state provision() writes to, so this never races
     * a worker that is genuinely mid-flight in `provisioning`.
     */
    private static function reclaim_stuck_paid(string $cutoff, int $minutes, int $order_id): int
    {
        global $wpdb;
        $sql  = 'SELECT id FROM ' . OrderService::table() . ' WHERE status = %s AND updated_at <= %s';
        $args = [StateMachine::PAID, $cutoff];
        if ($order_id > 0) {
            $sql   .= ' AND id = %d';
            $args[] = $order_id;
        }
        $sql   .= ' ORDER BY id ASC LIMIT %d';
        $args[] = self::SWEEP_LIMIT;

        $ids = $wpdb->get_col($wpdb->prepare($sql, ...$args)) ?: [];

        $moved = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            JobRunner::enqueue('provision_order', ['order_id' => $id], 5);
            $moved++;
            Audit::log(0, 'provision.paid_reclaimed', 'order', (string) $id, ['stale_minutes' => $minutes], 'info');
        }

        return $moved;
    }

    /**
     * CDN/object storage only: is the domain/bucket already a live service
     * belonging to a DIFFERENT customer? Mirrors OrderService::name_conflict()
     * — kept here too because RealProvider's create adopts by name with no
     * ownership check of its own, and this is the last stop before that call.
     */
    private static function name_conflict(string $product, int $customer_id, array $config): ?string
    {
        if ($product === 'cdn') {
            $key = (string) ($config['domain'] ?? '');
        } elseif ($product === 'object_storage') {
            $key = (string) ($config['bucket'] ?? '');
        } else {
            return null;
        }
        if ($key === '') {
            return null;
        }
        $existing = Services::by_remote($product, $key);
        if ($existing && (int) $existing['customer_id'] !== $customer_id
            && in_array((string) $existing['status'], Services::LIVE_STATUSES, true)) {
            return $product === 'cdn'
                ? __('این دامنه قبلاً برای مشتری دیگری ثبت شده است.', 'arvan-reseller')
                : __('این نام باکت قبلاً برای مشتری دیگری ثبت شده است.', 'arvan-reseller');
        }
        return null;
    }

    /** Terminal-failure bookkeeping for a provider error. */
    private static function fail(array $order, ProviderError $e, ?array $credential): array
    {
        $order_id = (int) $order['id'];
        OrderService::transition($order_id, StateMachine::PROVISIONING, StateMachine::PROVISION_FAILED, 'provisioner', $e->kind . ': ' . $e->getMessage());
        Audit::error('provision.failed', ['order' => $order_id, 'kind' => $e->kind, 'cid' => $e->correlation_id]);

        // A revoked or expired token is the most common upstream incident, and
        // until now only a human clicking «آزمایش اتصال» could ever change the
        // credential's recorded health — so the health page kept reporting
        // «متصل» while every provisioning failed.
        if ($e->kind === 'auth' && $credential && !empty($credential['id'])) {
            Credentials::record_test((int) $credential['id'], false, $e->getMessage());
            Plugin::flush_mode_cache();
            Notifier::admin('credential_failed',
                __('توکن آروان پذیرفته نشد', 'arvan-reseller'),
                sprintf(
                    /* translators: 1: credential id, 2: order id */
                    __('اتصال #%1$d هنگام راه‌اندازی سفارش #%2$d رد شد. توکن را در صفحهٔ اتصال‌ها بررسی و به‌روزرسانی کنید.', 'arvan-reseller'),
                    (int) $credential['id'], $order_id
                ));
        }

        if ($e->retryable()) {
            // Transient. No customer email yet: most of these self-heal on the
            // next attempt, and the job runner alerts the admin if they do not.
            return self::result(false, 'retryable', $e->customer_message());
        }

        Notifier::admin('provision_failed',
            __('خطای راه‌اندازی سرویس', 'arvan-reseller'),
            sprintf(
                /* translators: 1: order id, 2: error kind */
                __('سفارش #%1$d راه‌اندازی نشد (نوع خطا: %2$s) و نیاز به رسیدگی دستی دارد.', 'arvan-reseller'),
                $order_id, $e->kind
            ));

        // The customer paid and gets nothing. Telling only the admin is what
        // left customers refreshing a dashboard that never changed.
        Notifier::customer((int) $order['customer_id'], 'provision_failed',
            __('راه‌اندازی سرویس شما ناموفق بود', 'arvan-reseller'),
            sprintf(
                /* translators: 1: order id, 2: customer-safe reason */
                __('متأسفانه راه‌اندازی سفارش #%1$d انجام نشد. %2$s مبلغ پرداختی شما محفوظ است و پشتیبانی در حال پیگیری است.', 'arvan-reseller'),
                $order_id, $e->customer_message()
            ));

        do_action('arvrs_provision_failed', $order_id, $e->kind);

        return self::result(false, 'failed', $e->customer_message());
    }

    /** @return array{ok:bool,kind:string,message:string,service_id:int} */
    private static function result(bool $ok, string $kind, string $message, int $service_id = 0): array
    {
        return ['ok' => $ok, 'kind' => $kind, 'message' => $message, 'service_id' => $service_id];
    }
}
