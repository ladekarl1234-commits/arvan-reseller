<?php
namespace ArvanReseller\Usage;

use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Plugin;
use ArvanReseller\Policies\PolicyEngine;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Usage engine (spec §5.6): pulls closed usage periods per service from the
 * active provider, ingests them idempotently (UNIQUE(service, period)), and
 * debits the ledger once per record (UNIQUE(ref) there). After ingestion the
 * policy engine re-stages every affected customer.
 */
final class UsageSync
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_usage_records';
    }

    public static function register_hooks(): void
    {
        add_action('arvrs_usage_sync', [self::class, 'sync_all']);
    }

    /** @return array{services:int,ingested:int,debited:int,errors:int} */
    public static function sync_all(): array
    {
        $stats    = ['services' => 0, 'ingested' => 0, 'debited' => 0, 'errors' => 0];
        $services = Services::active_for_sync();
        $touched  = [];

        // Group by product so one provider call covers many services.
        $by_product = [];
        foreach ($services as $s) {
            $by_product[$s['product']][$s['remote_id']] = $s;
        }

        foreach ($by_product as $product => $map) {
            $stats['services'] += count($map);
            try {
                $rows = Plugin::arvan($product)->usage($product, array_keys($map), gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS));
            } catch (ProviderError $e) {
                $stats['errors']++;
                Notifier::admin('usage_sync_failed', __('همگام‌سازی مصرف ناموفق بود', 'arvan-reseller'),
                    sprintf(__('دریافت مصرف «%1$s» با خطا مواجه شد (%2$s).', 'arvan-reseller'), $product, $e->kind));
                Audit::error('usage.sync_failed', ['product' => $product, 'kind' => $e->kind, 'cid' => $e->correlation_id]);
                continue;
            }
            foreach ($rows as $row) {
                $service = $map[$row->remote_id] ?? null;
                if (!$service) {
                    continue; // never attribute usage we cannot map (spec: no blind attribution)
                }
                $result = self::ingest((int) $service['id'], (int) $service['customer_id'], $row);
                $stats['ingested'] += $result['ingested'];
                $stats['debited']  += $result['debited'];
                if ($result['ingested']) {
                    $touched[(int) $service['customer_id']] = true;
                }
            }
        }

        foreach (array_keys($touched) as $customer_id) {
            self::apply_policy($customer_id);
        }

        update_option('arvrs_last_usage_sync', gmdate('Y-m-d H:i:s'), false);
        return $stats;
    }

    /**
     * Idempotent single-record ingestion: INSERT IGNORE on the period key;
     * the ledger debit references the usage row ID, itself unique.
     * @return array{ingested:int,debited:int}
     */
    public static function ingest(int $service_id, int $customer_id, \ArvanReseller\Arvan\UsageRow $row): array
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (service_id, customer_id, period_start, period_end, quantity, unit, cost, created_at)
              VALUES (%d, %d, %s, %s, %f, %s, %d, %s)',
            $service_id, $customer_id, $row->period_start, $row->period_end,
            $row->quantity, $row->unit, $row->cost, Helpers::now()
        ));
        if ((int) $wpdb->rows_affected === 0) {
            return ['ingested' => 0, 'debited' => 0]; // period already ingested (portable duplicate signal)
        }
        $usage_id = (int) $wpdb->insert_id;
        $debit_id = Ledger::append($customer_id, 'usage_debit', max(0, $row->cost), 'usage', (string) $usage_id,
            sprintf(__('مصرف سرویس #%1$d (%2$s تا %3$s)', 'arvan-reseller'), $service_id, $row->period_start, $row->period_end));
        return ['ingested' => 1, 'debited' => $debit_id ? 1 : 0];
    }

    /** Re-stage a customer and fire configured actions (spec §5.5). */
    public static function apply_policy(int $customer_id): string
    {
        $balance = Ledger::balance($customer_id);
        $stage   = PolicyEngine::stage(
            $balance['available'],
            (int) Options::get('policy_warning', 500000),
            (int) Options::get('policy_critical', 100000),
            (int) Options::get('policy_grace_days', 3),
            Ledger::negative_since_days($customer_id)
        );
        update_user_meta($customer_id, 'arvrs_policy_stage', $stage);

        $actions = PolicyEngine::actions_for($stage, (array) Options::get('policy_actions', []));
        if (in_array('notify_customer', $actions, true)) {
            $messages = [
                PolicyEngine::WARNING    => ['low_balance', __('اعتبار شما رو به پایان است', 'arvan-reseller'), __('برای جلوگیری از وقفه در سرویس‌ها، کیف پول خود را شارژ کنید.', 'arvan-reseller')],
                PolicyEngine::CRITICAL   => ['critical_balance', __('اعتبار شما بحرانی است', 'arvan-reseller'), __('اعتبار باقی‌مانده بسیار کم است. هم‌اکنون کیف پول خود را شارژ کنید.', 'arvan-reseller')],
                PolicyEngine::GRACE      => ['suspension_warning', __('هشدار تعلیق سرویس', 'arvan-reseller'), __('اعتبار شما تمام شده است. در صورت عدم شارژ، سرویس‌ها محدود خواهند شد.', 'arvan-reseller')],
                PolicyEngine::RESTRICTED => ['suspension_warning', __('سرویس‌های شما محدود شد', 'arvan-reseller'), __('به دلیل اتمام اعتبار، خرید جدید و برخی امکانات غیرفعال شده است.', 'arvan-reseller')],
            ];
            if (isset($messages[$stage])) {
                [$type, $title, $body] = $messages[$stage];
                Notifier::customer($customer_id, $type, $title, $body);
            }
        }
        if (in_array('notify_admin', $actions, true)) {
            Notifier::admin('customer_at_risk', __('اعتبار مشتری در وضعیت هشدار', 'arvan-reseller'),
                sprintf(__('مشتری #%1$d در وضعیت «%2$s» قرار گرفت.', 'arvan-reseller'), $customer_id, $stage));
        }
        if (in_array('mark_at_risk', $actions, true)) {
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                'UPDATE ' . Services::table() . " SET status = 'at_risk', updated_at = %s WHERE customer_id = %d AND status = 'active'",
                Helpers::now(), $customer_id
            ));
        }
        // 'block_purchases' is enforced at checkout via stage lookup;
        // 'suspend_service' only ever runs through a documented API operation
        // and is left to explicit admin action (spec: never auto-destroy).
        return $stage;
    }

    /** Purchase gate used by checkout (policy block_purchases). */
    public static function purchases_blocked(int $customer_id): bool
    {
        $stage   = (string) get_user_meta($customer_id, 'arvrs_policy_stage', true);
        $actions = PolicyEngine::actions_for($stage ?: PolicyEngine::HEALTHY, (array) Options::get('policy_actions', []));
        return in_array('block_purchases', $actions, true);
    }

    public static function customer_usage(int $customer_id, int $limit = 30): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT u.*, s.product, s.plan_id FROM ' . self::table() . ' u
             JOIN ' . Services::table() . ' s ON s.id = u.service_id
             WHERE u.customer_id = %d ORDER BY u.id DESC LIMIT %d',
            $customer_id, $limit
        ), ARRAY_A) ?: [];
    }
}
