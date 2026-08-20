<?php
namespace ArvanReseller\Payments;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Plugin;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Payment callback handling (spec §5.3, HC-7). Verified → atomically claimed
 * → ledgered (INSERT IGNORE on unique business key) → provisioned. Replays
 * take the idempotent path at every layer and answer with current state.
 */
final class PaymentService
{
    /**
     * Order payment callback.
     * @param array $payload whitelisted provider fields
     * @return array{ok:bool,replay:bool,message:string,order:?array}
     */
    public static function handle_order_callback(string $payment_ref, array $payload): array
    {
        $order = OrderService::by_ref($payment_ref);
        if (!$order) {
            return ['ok' => false, 'replay' => false, 'message' => __('سفارش یافت نشد.', 'arvan-reseller'), 'order' => null];
        }

        // Terminal/processed states answer idempotently BEFORE any side effect.
        if (!in_array($order['status'], StateMachine::payable(), true)) {
            return [
                'ok'      => in_array($order['status'], [StateMachine::PAID, StateMachine::PROVISIONING, StateMachine::ACTIVE, StateMachine::PROVISION_FAILED], true),
                'replay'  => true,
                'message' => __('این پرداخت قبلاً پردازش شده است.', 'arvan-reseller'),
                'order'   => OrderService::get((int) $order['id']),
            ];
        }

        $verify = Plugin::payments()->verify($payment_ref, (int) $order['amount'], $payload);
        if (empty($verify['ok'])) {
            Audit::log(0, 'payment.verify_failed', 'order', (string) $order['id'], ['ref' => $payment_ref]);
            return ['ok' => false, 'replay' => false, 'message' => $verify['message'] ?? __('تأیید پرداخت ناموفق بود.', 'arvan-reseller'), 'order' => $order];
        }

        $claimed = OrderService::claim_paid($payment_ref, (int) $order['amount'], (string) $verify['transaction_id']);
        if (!$claimed) {
            // Raced replay: someone else claimed between our read and update.
            return ['ok' => true, 'replay' => true, 'message' => __('این پرداخت قبلاً پردازش شده است.', 'arvan-reseller'), 'order' => OrderService::get((int) $order['id'])];
        }

        $customer_id = (int) $claimed['customer_id'];
        // Double-entry-inspired pair on the unique business key (payment_ref):
        // the money that came in, and the purchase it settled.
        Ledger::append($customer_id, 'payment', (int) $claimed['amount'], 'order', $payment_ref,
            sprintf(__('پرداخت سفارش #%d', 'arvan-reseller'), (int) $claimed['id']), 'gateway:' . Plugin::payments()->id());
        Ledger::append($customer_id, 'purchase', (int) $claimed['amount'], 'order', $payment_ref,
            sprintf(__('خرید سرویس — سفارش #%d', 'arvan-reseller'), (int) $claimed['id']));

        Notifier::customer($customer_id, 'payment_success',
            __('پرداخت موفق', 'arvan-reseller'),
            sprintf(__('پرداخت سفارش #%d با موفقیت تأیید شد. سرویس شما در حال راه‌اندازی است.', 'arvan-reseller'), (int) $claimed['id']));
        Audit::log($customer_id, 'payment.confirmed', 'order', (string) $claimed['id'], ['ref' => $payment_ref, 'tx' => $verify['transaction_id']]);

        // Durable job first (crash-safe), then an inline attempt for the
        // "payment → service ready" instant UX (spec §5.4).
        JobRunner::enqueue('provision_order', ['order_id' => (int) $claimed['id']]);
        try {
            Provisioner::provision((int) $claimed['id']);
        } catch (\Throwable $e) {
            // Retryable failure — the queued job takes over with backoff.
            Audit::error('provision.inline_deferred', ['order' => (int) $claimed['id'], 'error' => $e->getMessage()]);
        }

        return ['ok' => true, 'replay' => false, 'message' => __('پرداخت تأیید شد.', 'arvan-reseller'), 'order' => OrderService::get((int) $claimed['id'])];
    }

    /**
     * Wallet top-up start: persist the expected amount server-side, hand the
     * customer to the gateway.
     * @return string redirect URL
     */
    public static function start_topup(int $customer_id, int $amount): string
    {
        $ref = 'TOP-' . strtoupper(bin2hex(random_bytes(6)));
        // ponytail: topup intents live in wp_options (autoload=no) — fine at
        // reseller scale; move to a table if top-up volume ever matters.
        add_option('arvrs_topup_' . $ref, ['customer_id' => $customer_id, 'amount' => $amount, 'at' => time()], '', false);
        Audit::log($customer_id, 'topup.started', 'topup', $ref, ['amount' => $amount]);
        return Plugin::payments()->start($ref, $amount, 'topup');
    }

    /** @return array{ok:bool,replay:bool,message:string} */
    public static function handle_topup_callback(string $ref, array $payload): array
    {
        $intent = get_option('arvrs_topup_' . $ref);
        if (!is_array($intent)) {
            return ['ok' => false, 'replay' => false, 'message' => __('تراکنش یافت نشد.', 'arvan-reseller')];
        }
        $verify = Plugin::payments()->verify($ref, (int) $intent['amount'], $payload);
        if (empty($verify['ok'])) {
            return ['ok' => false, 'replay' => false, 'message' => $verify['message'] ?? __('تأیید پرداخت ناموفق بود.', 'arvan-reseller')];
        }
        $row_id = Ledger::append((int) $intent['customer_id'], 'topup', (int) $intent['amount'], 'topup', $ref,
            __('افزایش اعتبار کیف پول', 'arvan-reseller'), 'gateway:' . Plugin::payments()->id());
        if ($row_id === 0) {
            return ['ok' => true, 'replay' => true, 'message' => __('این تراکنش قبلاً ثبت شده است.', 'arvan-reseller')];
        }
        Notifier::customer((int) $intent['customer_id'], 'topup_success',
            __('افزایش اعتبار موفق', 'arvan-reseller'),
            __('اعتبار کیف پول شما با موفقیت افزایش یافت.', 'arvan-reseller'));
        Audit::log((int) $intent['customer_id'], 'topup.confirmed', 'topup', $ref, ['amount' => (int) $intent['amount']]);
        return ['ok' => true, 'replay' => false, 'message' => __('اعتبار شما افزایش یافت.', 'arvan-reseller')];
    }
}
