<?php
namespace ArvanReseller\Provisioning;

use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Audit\Audit;
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
 */
final class Provisioner
{
    /**
     * @return array{ok:bool,message:string,service_id:int}
     * @throws \RuntimeException when retryable (job runner backs off)
     */
    public static function provision(int $order_id): array
    {
        $order = OrderService::get($order_id);
        if (!$order) {
            return ['ok' => false, 'message' => 'order not found', 'service_id' => 0];
        }

        // Idempotency layer 2: an existing service means work already done.
        $existing = Services::by_order($order_id);
        if ($existing) {
            OrderService::transition($order_id, (string) $order['status'], StateMachine::ACTIVE, 'provisioner', 'service already exists');
            return ['ok' => true, 'message' => 'already provisioned', 'service_id' => (int) $existing['id']];
        }

        // Idempotency layer 1: claim the order. Losing the claim = another
        // worker owns it right now.
        $claimed = OrderService::transition($order_id, StateMachine::PAID, StateMachine::PROVISIONING, 'provisioner')
            || OrderService::transition($order_id, StateMachine::PROVISION_FAILED, StateMachine::PROVISIONING, 'provisioner', 'retry');
        if (!$claimed) {
            return ['ok' => false, 'message' => 'order not claimable (state: ' . $order['status'] . ')', 'service_id' => 0];
        }

        $product    = (string) $order['product'];
        $config     = json_decode((string) $order['config'], true) ?: [];
        $credential = Plugin::demo_mode() ? null : Credentials::select_for($product);

        try {
            $resource = Plugin::arvan($product)->create(
                $product,
                (string) $order['plan_id'],
                $config,
                'order:' . $order_id
            );
        } catch (ProviderError $e) {
            OrderService::transition($order_id, StateMachine::PROVISIONING, StateMachine::PROVISION_FAILED, 'provisioner', $e->kind . ': ' . $e->getMessage());
            Notifier::admin('provision_failed',
                __('خطای راه‌اندازی سرویس', 'arvan-reseller'),
                sprintf(__('سفارش #%1$d با خطا مواجه شد: %2$s', 'arvan-reseller'), $order_id, $e->kind));
            Audit::error('provision.failed', ['order' => $order_id, 'kind' => $e->kind, 'cid' => $e->correlation_id]);
            // Retryable kinds bubble to the job runner for backoff.
            if (in_array($e->kind, ['timeout', 'unavailable', 'rate_limit', 'unknown'], true)) {
                throw new \RuntimeException('retryable: ' . $e->kind);
            }
            return ['ok' => false, 'message' => $e->customer_message(), 'service_id' => 0];
        }

        $service_id = Services::create_for_order($order, $resource->remote_id, $resource->connection, $credential['id'] ?? null);
        OrderService::transition($order_id, StateMachine::PROVISIONING, StateMachine::ACTIVE, 'provisioner', 'remote:' . $resource->remote_id);

        Notifier::customer((int) $order['customer_id'], 'provisioned',
            __('سرویس شما آماده است', 'arvan-reseller'),
            sprintf(__('سرویس «%s» با موفقیت راه‌اندازی شد و هم‌اکنون در پیشخوان شما قابل مشاهده است.', 'arvan-reseller'),
                \ArvanReseller\Arvan\Catalog::product_label($product)));
        Audit::log(0, 'provision.success', 'service', (string) $service_id, ['order' => $order_id, 'remote' => $resource->remote_id]);

        return ['ok' => true, 'message' => 'provisioned', 'service_id' => $service_id];
    }
}
