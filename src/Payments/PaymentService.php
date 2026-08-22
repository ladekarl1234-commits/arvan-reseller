<?php
namespace ArvanReseller\Payments;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Orders\StateMachine;
use ArvanReseller\Plugin;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Payment callback handling (spec §5.3, HC-7). Verified → atomically claimed
 * → ledgered (INSERT IGNORE on unique business key) → provisioned. Replays
 * take the idempotent path at every layer and answer with current state.
 */
final class PaymentService
{
    /** Top-up intents die after this long — an intent is a checkout session, not a coupon. */
    private const TOPUP_TTL = 2 * HOUR_IN_SECONDS;

    public static function topups_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_topups';
    }

    /**
     * The sandbox gateway hands a self-verifiable proof to the buyer, so it
     * must never settle a real transaction. True when the active provider is
     * the sandbox but the store is NOT in demo mode — every settlement path
     * (checkout, callback, top-up, payment page) checks this.
     */
    public static function sandbox_blocked(): bool
    {
        return Plugin::payments()->id() === 'sandbox' && !Plugin::demo_mode();
    }

    /**
     * Machine-readable gateway readiness, so the block stops being an invisible
     * side effect of leaving Demo Mode. The wizard's validation list and the
     * System Health page render this; `ok=false` means the storefront cannot
     * take money and every purchase path is returning 503.
     *
     * @return array{ok:bool,level:string,provider:string,message:string}
     */
    public static function gateway_status(): array
    {
        if (!self::sandbox_blocked()) {
            return [
                'ok'       => true,
                'level'    => 'success',
                'provider' => Plugin::payments()->label(),
                'message'  => Plugin::demo_mode()
                    ? __('درگاه آزمایشی فعال است — مناسب نمایش و آزمون، نه فروش واقعی.', 'arvan-reseller')
                    : __('درگاه پرداخت آماده است.', 'arvan-reseller'),
            ];
        }
        return [
            'ok'       => false,
            'level'    => 'danger',
            'provider' => Plugin::payments()->label(),
            'message'  => __('فروش متوقف است: حالت دمو خاموش شده اما هیچ درگاه پرداخت واقعی ثبت نشده است. تا نصب یک درگاه واقعی، خرید و افزایش اعتبار برای مشتریان کار نمی‌کند.', 'arvan-reseller'),
        ];
    }

    /**
     * Tell the admin — once a day at most — that a customer just hit the dead
     * gateway. Called from the purchase paths, not from the predicate, so a
     * page render never writes a notification.
     */
    public static function alert_gateway_blocked(): void
    {
        if (!Helpers::rate_limit('gateway_blocked_alert', 1, DAY_IN_SECONDS)) {
            return;
        }
        $status = self::gateway_status();
        Notifier::admin('gateway_blocked', __('فروشگاه نمی‌تواند پرداخت بپذیرد', 'arvan-reseller'), $status['message']);
        Audit::error('payment.gateway_blocked', ['provider' => Plugin::payments()->id()]);
    }

    /**
     * Order payment callback.
     *
     * @param string $payment_ref order payment reference
     * @param array  $payload     whitelisted provider fields
     * @return array{ok:bool,replay:bool,message:string,order:?array,
     *               provision:array{state:string,message:string}}
     *         provision.state ∈ 'active'|'pending'|'failed' — the payment page
     *         renders from it, so it must never claim a service exists that
     *         does not.
     */
    public static function handle_order_callback(string $payment_ref, array $payload): array
    {
        $order = OrderService::by_ref($payment_ref);
        if (!$order) {
            return self::result(false, false, __('سفارش یافت نشد.', 'arvan-reseller'), null);
        }
        if (self::sandbox_blocked()) {
            self::alert_gateway_blocked();
            return self::result(false, false, __('درگاه پرداخت واقعی پیکربندی نشده است.', 'arvan-reseller'), $order);
        }

        // Terminal/processed states answer idempotently BEFORE any side effect.
        if (!in_array($order['status'], StateMachine::payable(), true)) {
            $current = OrderService::get((int) $order['id']);
            return self::result(
                in_array($order['status'], [StateMachine::PAID, StateMachine::PROVISIONING, StateMachine::ACTIVE, StateMachine::PROVISION_FAILED], true),
                true,
                __('این پرداخت قبلاً پردازش شده است.', 'arvan-reseller'),
                $current
            );
        }

        $verify = Plugin::payments()->verify($payment_ref, (int) $order['amount'], $payload);
        if (empty($verify['ok'])) {
            Audit::log(0, 'payment.verify_failed', 'order', (string) $order['id'], ['ref' => $payment_ref]);
            return self::result(false, false, $verify['message'] ?? __('تأیید پرداخت ناموفق بود.', 'arvan-reseller'), $order);
        }

        $claim = OrderService::claim_paid($payment_ref, (int) $order['amount'], (string) $verify['transaction_id']);
        if ($claim['kind'] === 'amount_mismatch') {
            // The gateway confirmed a different figure than the order carries.
            // Reporting that as a cheerful replay is how a partial settlement
            // disappears: it is a failure, and a human has to look at it.
            Audit::error('payment.amount_mismatch', [
                'order'    => (int) $claim['order']['id'],
                'ref'      => $payment_ref,
                'expected' => (int) $claim['order']['amount'],
                'verified' => (int) $order['amount'],
                'tx'       => (string) $verify['transaction_id'],
            ]);
            Notifier::admin(
                'payment_amount_mismatch',
                __('مغایرت مبلغ پرداخت', 'arvan-reseller'),
                sprintf(
                    /* translators: 1: order id, 2: payment reference */
                    __('مبلغ تأییدشده توسط درگاه با مبلغ سفارش #%1$d (کد پیگیری %2$s) یکسان نیست. سفارش پرداخت‌نشده باقی ماند و نیاز به بررسی دستی دارد.', 'arvan-reseller'),
                    (int) $claim['order']['id'],
                    $payment_ref
                )
            );
            return self::result(false, false, __('مبلغ تأییدشده با مبلغ سفارش مغایرت دارد. با پشتیبانی تماس بگیرید.', 'arvan-reseller'), $claim['order']);
        }
        if ($claim['kind'] === 'not_found') {
            return self::result(false, false, __('سفارش یافت نشد.', 'arvan-reseller'), null);
        }
        if ($claim['kind'] === 'replay') {
            // Raced replay: someone else claimed between our read and update.
            return self::result(true, true, __('این پرداخت قبلاً پردازش شده است.', 'arvan-reseller'), $claim['order']);
        }

        $claimed     = $claim['order'];
        $customer_id = (int) $claimed['customer_id'];
        // One id ties this settlement to its audit rows, its order timeline and
        // the upstream provisioning call that follows.
        $cid = Helpers::correlation_id();

        // Durable job FIRST, before any notification or audit work: the order
        // is already `paid` the instant claim_paid() above succeeded, and a
        // fatal/OOM/timeout anywhere after that point must not leave it with
        // no job standing between it and reclaim_stale(). This enqueue is the
        // only thing that guarantees a paid order eventually provisions.
        JobRunner::enqueue('provision_order', ['order_id' => (int) $claimed['id'], 'cid' => $cid]);

        // Double-entry-inspired pair on the unique business key (payment_ref):
        // the money that came in, and the purchase it settled.
        try {
            Ledger::append($customer_id, 'payment', (int) $claimed['amount'], 'order', $payment_ref,
                sprintf(__('پرداخت سفارش #%d', 'arvan-reseller'), (int) $claimed['id']), 'gateway:' . Plugin::payments()->id());
            Ledger::append($customer_id, 'purchase', (int) $claimed['amount'], 'order', $payment_ref,
                sprintf(__('خرید سرویس — سفارش #%d', 'arvan-reseller'), (int) $claimed['id']));
        } catch (\Throwable $e) {
            // A transient DB failure must not strand a paid order — but it also
            // must not vanish. Money has changed hands and the ledger does not
            // know: queue the durable repair (idempotent on the unique key) and
            // put it in front of a human, then carry on to provisioning.
            Audit::error('ledger.payment_append_failed', [
                'order' => (int) $claimed['id'], 'ref' => $payment_ref, 'cid' => $cid, 'error' => $e->getMessage(),
            ]);
            JobRunner::enqueue('repair_ledger', [
                'customer_id' => $customer_id,
                'payment_ref' => $payment_ref,
                'amount'      => (int) $claimed['amount'],
                'order_id'    => (int) $claimed['id'],
            ]);
            Notifier::admin(
                'ledger_repair_queued',
                __('ثبت مالی یک پرداخت ناموفق بود', 'arvan-reseller'),
                sprintf(
                    /* translators: 1: order id, 2: payment reference */
                    __('پرداخت سفارش #%1$d (کد پیگیری %2$s) تأیید شد اما ثبت آن در دفتر مالی ناموفق بود. کار ترمیم در صف قرار گرفت؛ تا تکمیل آن، گزارش‌های مالی این سفارش را نشان نمی‌دهند.', 'arvan-reseller'),
                    (int) $claimed['id'],
                    $payment_ref
                )
            );
        }

        Notifier::customer($customer_id, 'payment_success',
            __('پرداخت موفق', 'arvan-reseller'),
            sprintf(__('پرداخت سفارش #%d با موفقیت تأیید شد. سرویس شما در حال راه‌اندازی است.', 'arvan-reseller'), (int) $claimed['id']));
        Audit::log($customer_id, 'payment.confirmed', 'order', (string) $claimed['id'], ['ref' => $payment_ref, 'cid' => $cid, 'tx' => $verify['transaction_id']]);
        OrderService::note((int) $claimed['id'], 'payment', 'cid:' . $cid . ' tx:' . (string) $verify['transaction_id']);

        // The durable job is already queued (above); this inline attempt is
        // only so the "payment → service ready" instant UX (spec §5.4) does
        // not have to wait for the next cron tick.
        try {
            // The outcome is deliberately not echoed to the customer: the
            // truthful signal is the order's own status, which provision_state()
            // reads back below. Provisioner's message is operator-facing text.
            Provisioner::provision((int) $claimed['id']);
        } catch (\Throwable $e) {
            // Retryable failure — the queued job takes over with backoff.
            Audit::error('provision.inline_deferred', ['order' => (int) $claimed['id'], 'cid' => $cid, 'error' => $e->getMessage()]);
        }

        return self::result(true, false, __('پرداخت تأیید شد.', 'arvan-reseller'), OrderService::get((int) $claimed['id']));
    }

    /**
     * Owner-scoped provisioning state for the payment page's poll and for the
     * callback response. Reading the order row is what makes it truthful: the
     * page can only claim "سرویس شما آماده است" when the order actually
     * reached `active`.
     *
     * @return array{state:string,message:string}
     */
    public static function provision_state(?array $order): array
    {
        $status = $order ? (string) $order['status'] : '';
        if ($status === StateMachine::ACTIVE) {
            return ['state' => 'active', 'message' => __('سرویس شما آماده است.', 'arvan-reseller')];
        }
        // 'pending' is claimed ONLY for the two states where provisioning is
        // genuinely still in flight. Everything else — including an unknown
        // order — is a failure, so no branch can leave the customer watching a
        // spinner that will never resolve.
        if ($status === StateMachine::PAID || $status === StateMachine::PROVISIONING) {
            return ['state' => 'pending', 'message' => __('در حال راه‌اندازی سرویس؛ چند لحظه دیگر بررسی می‌کنیم.', 'arvan-reseller')];
        }
        if ($status === StateMachine::PROVISION_FAILED) {
            return [
                'state'   => 'failed',
                'message' => __('راه‌اندازی سرویس ناموفق بود. تیم پشتیبانی در جریان است و پیگیری می‌کند؛ مبلغ پرداختی شما محفوظ است.', 'arvan-reseller'),
            ];
        }
        if (in_array($status, [StateMachine::CANCELLED, StateMachine::REFUNDED], true)) {
            return ['state' => 'failed', 'message' => __('این سفارش دیگر فعال نیست.', 'arvan-reseller')];
        }
        return ['state' => 'failed', 'message' => __('هنوز پرداختی برای این سفارش ثبت نشده است.', 'arvan-reseller')];
    }

    /** @return array{ok:bool,replay:bool,message:string,order:?array,provision:array} */
    private static function result(bool $ok, bool $replay, string $message, ?array $order): array
    {
        return [
            'ok'        => $ok,
            'replay'    => $replay,
            'message'   => $message,
            'order'     => $order,
            'provision' => self::provision_state($order),
        ];
    }

    /**
     * Refund an order to the customer's wallet.
     *
     * Guarded because the payment/purchase pair nets to zero: a refund is a net
     * `+amount` credit that ASSUMES the pair was written. If the settlement
     * write was dropped (the failure `repair_ledger` exists for), refunding
     * mints wallet credit backed by no debit. So require the `purchase` row
     * first — the same row the repair job restores.
     *
     * Caller supplies capability + nonce and performs the state transition.
     *
     * @return array{ok:bool,replay:bool,message:string}
     */
    public static function refund_order(array $order, string $actor): array
    {
        $payment_ref = (string) $order['payment_ref'];
        $order_id    = (int) $order['id'];

        if (!self::order_is_settled($payment_ref)) {
            Audit::error('refund.blocked_unsettled', ['order' => $order_id, 'ref' => $payment_ref]);
            Notifier::admin(
                'refund_blocked',
                __('بازپرداخت متوقف شد', 'arvan-reseller'),
                sprintf(
                    /* translators: %d: order id */
                    __('سفارش #%d ردیف مالی «خرید» ندارد، بنابراین بازپرداخت آن اعتبار بدون پشتوانه می‌سازد. ابتدا ترمیم دفتر مالی را اجرا کنید.', 'arvan-reseller'),
                    $order_id
                )
            );
            return [
                'ok'      => false,
                'replay'  => false,
                'message' => __('این سفارش در دفتر مالی ثبت نشده است؛ تا ترمیم آن، بازپرداخت انجام نمی‌شود.', 'arvan-reseller'),
            ];
        }

        try {
            // append() returns 0 on the unique-key replay — a real "already
            // refunded" — which is not the same fact as a fresh write, so the
            // return value is captured rather than discarded (a discarded
            // replay used to answer ok:true for a repeat that wrote nothing).
            $entry_id = Ledger::append((int) $order['customer_id'], 'refund', (int) $order['amount'], 'order_refund', $payment_ref,
                sprintf(__('بازپرداخت سفارش #%d', 'arvan-reseller'), $order_id), $actor);
        } catch (\Throwable $e) {
            Audit::error('order.refund_failed', ['order' => $order_id, 'error' => $e->getMessage()]);
            return [
                'ok'      => false,
                'replay'  => false,
                'message' => __('خطای موقت در ثبت بازپرداخت. سفارش تغییری نکرد؛ دوباره تلاش کنید.', 'arvan-reseller'),
            ];
        }
        if ($entry_id === 0) {
            return ['ok' => true, 'replay' => true, 'message' => __('این سفارش قبلاً بازپرداخت شده است.', 'arvan-reseller')];
        }
        return ['ok' => true, 'replay' => false, 'message' => __('مبلغ به کیف پول مشتری برگشت.', 'arvan-reseller')];
    }

    /** Does the settlement debit for this reference actually exist in the ledger? */
    private static function order_is_settled(string $payment_ref): bool
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . Ledger::table() . ' WHERE ref_type = %s AND ref_id = %s AND type = %s',
            'order', $payment_ref, 'purchase'
        )) > 0;
    }

    /**
     * Wallet top-up start: persist the expected amount server-side, hand the
     * customer to the gateway.
     *
     * Intents live in their own table with an expiry rather than in
     * `wp_options`: an options row per attempt was permanent, autoloaded-table
     * bloat that any registered customer could generate in a loop, and an
     * intent that never expires stays redeemable forever.
     *
     * @return string redirect URL ('' when the intent could not be persisted)
     */
    public static function start_topup(int $customer_id, int $amount): string
    {
        global $wpdb;
        $ref = 'TOP-' . strtoupper(bin2hex(random_bytes(6)));
        $ok  = $wpdb->insert(self::topups_table(), [
            'ref'         => $ref,
            'customer_id' => $customer_id,
            'amount'      => $amount,
            'status'      => 'pending',
            'created_at'  => Helpers::now(),
            'expires_at'  => gmdate('Y-m-d H:i:s', time() + self::TOPUP_TTL),
        ]);
        if (!$ok) {
            Audit::error('topup.intent_failed', ['customer' => $customer_id, 'amount' => $amount]);
            return '';
        }
        Audit::log($customer_id, 'topup.started', 'topup', $ref, ['amount' => $amount]);
        return Plugin::payments()->start($ref, $amount, 'topup');
    }

    /**
     * Read a top-up intent by reference. Public because the payment page needs
     * the expected amount to build its gateway hand-off.
     *
     * @return array|null null when unknown, already settled, or expired
     */
    public static function topup_intent(string $ref): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::topups_table() . ' WHERE ref = %s', $ref
        ), ARRAY_A);
        if (!$row || $row['status'] !== 'pending') {
            return null;
        }
        return strtotime((string) $row['expires_at']) < time() ? null : $row;
    }

    /** @return array{ok:bool,replay:bool,message:string} */
    public static function handle_topup_callback(string $ref, array $payload): array
    {
        global $wpdb;
        $intent = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::topups_table() . ' WHERE ref = %s', $ref
        ), ARRAY_A);
        if (!$intent) {
            return ['ok' => false, 'replay' => false, 'message' => __('تراکنش یافت نشد.', 'arvan-reseller')];
        }
        if ((string) $intent['status'] === 'settled') {
            return ['ok' => true, 'replay' => true, 'message' => __('این تراکنش قبلاً ثبت شده است.', 'arvan-reseller')];
        }
        if ((string) $intent['status'] !== 'pending' || strtotime((string) $intent['expires_at']) < time()) {
            Audit::log(0, 'topup.expired', 'topup', $ref, []);
            return ['ok' => false, 'replay' => false, 'message' => __('مهلت این تراکنش به پایان رسیده است. لطفاً افزایش اعتبار را دوباره شروع کنید.', 'arvan-reseller')];
        }
        if (self::sandbox_blocked()) {
            self::alert_gateway_blocked();
            return ['ok' => false, 'replay' => false, 'message' => __('درگاه پرداخت واقعی پیکربندی نشده است.', 'arvan-reseller')];
        }
        $verify = Plugin::payments()->verify($ref, (int) $intent['amount'], $payload);
        if (empty($verify['ok'])) {
            return ['ok' => false, 'replay' => false, 'message' => $verify['message'] ?? __('تأیید پرداخت ناموفق بود.', 'arvan-reseller')];
        }
        try {
            $row_id = Ledger::append((int) $intent['customer_id'], 'topup', (int) $intent['amount'], 'topup', $ref,
                __('افزایش اعتبار کیف پول', 'arvan-reseller'), 'gateway:' . Plugin::payments()->id());
        } catch (\Throwable $e) {
            // Real DB failure — report failure so the gateway retries (the
            // unique key makes the retry idempotent). The intent stays pending
            // on purpose: it is the retry's only record of the expected amount.
            Audit::error('ledger.topup_append_failed', ['ref' => $ref, 'error' => $e->getMessage()]);
            return ['ok' => false, 'replay' => false, 'message' => __('خطای موقت در ثبت تراکنش. لطفاً دوباره تلاش کنید.', 'arvan-reseller')];
        }
        self::settle_intent($ref);
        if ($row_id === 0) {
            return ['ok' => true, 'replay' => true, 'message' => __('این تراکنش قبلاً ثبت شده است.', 'arvan-reseller')];
        }
        // Crediting the wallet can lift a suspension / clear a purchase block.
        \ArvanReseller\Usage\UsageSync::apply_policy((int) $intent['customer_id']);
        Notifier::customer((int) $intent['customer_id'], 'topup_success',
            __('افزایش اعتبار موفق', 'arvan-reseller'),
            __('اعتبار کیف پول شما با موفقیت افزایش یافت.', 'arvan-reseller'));
        Audit::log((int) $intent['customer_id'], 'topup.confirmed', 'topup', $ref, ['amount' => (int) $intent['amount']]);
        return ['ok' => true, 'replay' => false, 'message' => __('اعتبار شما افزایش یافت.', 'arvan-reseller')];
    }

    private static function settle_intent(string $ref): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::topups_table() . ' SET status = %s WHERE ref = %s AND status = %s',
            'settled', $ref, 'pending'
        ));
    }

    /**
     * Retention for abandoned intents: expired-and-unpaid rows carry no money
     * and no history worth keeping. Settled rows are kept for $keep_days as the
     * operator's record of what a reference was for.
     *
     * @return int rows removed
     */
    public static function purge_expired_topups(int $keep_days = 30): int
    {
        global $wpdb;
        $now    = Helpers::now();
        $cutoff = gmdate('Y-m-d H:i:s', time() - max(1, $keep_days) * DAY_IN_SECONDS);
        $gone   = (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::topups_table() . ' WHERE status = %s AND expires_at < %s',
            'pending', $now
        ));
        $gone += (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . self::topups_table() . ' WHERE status = %s AND created_at < %s',
            'settled', $cutoff
        ));
        return $gone;
    }
}
