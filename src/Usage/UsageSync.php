<?php
namespace ArvanReseller\Usage;

use ArvanReseller\Arvan\ProviderError;
use ArvanReseller\Arvan\UsageRow;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Plugin;
use ArvanReseller\Policies\PolicyEngine;
use ArvanReseller\Pricing\PricingEngine;
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
 *
 * Two things the first version got wrong and this one does not:
 *
 * 1. The debit is the CUSTOMER price, not the upstream cost. A usage row
 *    stores both, so metered margin is measurable in the same report as order
 *    margin instead of being structurally zero (EX-031/EX-099).
 * 2. A run is cursor-paged and time-boxed, and each service carries its own
 *    watermark. A missed cron tick is caught up on the next run instead of
 *    falling out of a fixed 48-hour window (EX-030/EX-123).
 */
final class UsageSync
{
    /** Services fetched per cursor page. */
    private const PAGE = 200;

    /** Wall-clock budget for one run, in seconds; the remainder is re-enqueued. */
    private const BUDGET = 20;

    /** Never ask a provider for more than this much history in one call. */
    private const MAX_LOOKBACK_DAYS = 30;

    /** How far back a service that has never synced starts. */
    private const FIRST_SYNC_HOURS = 48;

    /** @var float|null memoised usage markup percent for the current request */
    private static $markup;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_usage_records';
    }

    public static function register_hooks(): void
    {
        add_action('arvrs_usage_sync', [self::class, 'sync_all']);
    }

    /**
     * Meter one bounded slice of the service population.
     *
     * @param array $payload {@type int $after_id} cursor from the previous run
     * @return array{services:int,ingested:int,debited:int,errors:int,requeued:int,next_after_id:int}
     */
    public static function sync_all(array $payload = []): array
    {
        $stats = ['services' => 0, 'ingested' => 0, 'debited' => 0, 'errors' => 0, 'requeued' => 0, 'next_after_id' => 0];
        $batch = max(self::PAGE, (int) Options::get('sync_batch', 500));
        $after = max(0, (int) (isset($payload['after_id']) ? $payload['after_id'] : 0));
        $started = time();
        $touched = [];

        while ($stats['services'] < $batch) {
            $page = Services::active_for_sync(self::PAGE, $after);
            if (!$page) {
                $after = 0; // population exhausted — next scheduled run starts over
                break;
            }
            $last  = end($page);
            $after = (int) $last['id'];
            $stats['services'] += count($page);
            self::sync_page($page, $stats, $touched);

            if (count($page) < self::PAGE) {
                $after = 0;
                break;
            }
            if (time() - $started >= self::BUDGET) {
                break; // out of budget with rows left: the cursor carries the rest
            }
        }

        foreach (array_keys($touched) as $customer_id) {
            self::apply_policy((int) $customer_id);
        }

        if ($after > 0) {
            // A resume point, not a restart: idempotency makes a repeat safe,
            // it does not make it cheap.
            JobRunner::enqueue('usage_sync', ['after_id' => $after], 60);
            $stats['requeued']      = 1;
            $stats['next_after_id'] = $after;
        }

        // Counts, not just a timestamp: a run that touched 40 services and
        // ingested nothing is the signature of a broken usage endpoint, and
        // used to be indistinguishable from a healthy run (EX-131).
        update_option('arvrs_last_usage_sync', ['at' => gmdate('Y-m-d H:i:s'), 'stats' => $stats], false);
        Audit::log(0, 'usage.sync', 'system', '', $stats);
        return $stats;
    }

    /** Last scheduled or manual run. @return array{at:string,stats:array} */
    public static function last_run(): array
    {
        $raw = get_option('arvrs_last_usage_sync', '');
        if (is_array($raw)) {
            return [
                'at'    => (string) (isset($raw['at']) ? $raw['at'] : ''),
                'stats' => (array) (isset($raw['stats']) ? $raw['stats'] : []),
            ];
        }
        return ['at' => (string) $raw, 'stats' => []]; // pre-upgrade shape
    }

    /**
     * One cursor page: batch the services into as few provider calls as their
     * watermarks allow, then attribute every returned row back to its service.
     *
     * @param array $page    rows from Services::active_for_sync()
     * @param array $stats   accumulator, by reference
     * @param array $touched customer ids whose wallet moved, by reference
     */
    private static function sync_page(array $page, array &$stats, array &$touched): void
    {
        // Group by product AND by watermark, not by product alone: one service
        // that missed a week of ticks must not drag every other service's
        // window back with it, which would multiply the rows a provider
        // returns for the whole page.
        $groups = [];
        foreach ($page as $service) {
            $since = self::since_for_service($service);
            $key   = (string) $service['product'] . '|' . $since;
            if (!isset($groups[$key])) {
                $groups[$key] = ['product' => (string) $service['product'], 'since' => $since, 'map' => []];
            }
            $groups[$key]['map'][(string) $service['remote_id']] = $service;
        }

        foreach ($groups as $group) {
            $product = $group['product'];
            $since   = $group['since'];
            $map     = $group['map'];
            try {
                $rows = Plugin::arvan($product)->usage($product, array_keys($map), $since);
            } catch (ProviderError $e) {
                $stats['errors']++;
                Notifier::admin('usage_sync_failed', __('همگام‌سازی مصرف ناموفق بود', 'arvan-reseller'),
                    sprintf(__('دریافت مصرف «%1$s» با خطا مواجه شد (%2$s).', 'arvan-reseller'), $product, $e->kind));
                Audit::error('usage.sync_failed', ['product' => $product, 'kind' => $e->kind, 'cid' => $e->correlation_id]);
                continue; // no watermark stamp: this window must be retried
            }

            // Which services in THIS group had a row that failed to ingest —
            // those must not get their watermark advanced, or the failed
            // period slides behind `since` and the provider never returns it
            // again (silent revenue loss, EX-… the whole reason this is
            // per-service rather than per-page).
            $failed = [];
            foreach ($rows as $row) {
                $service = isset($map[$row->remote_id]) ? $map[$row->remote_id] : null;
                if (!$service) {
                    continue; // never attribute usage we cannot map (spec: no blind attribution)
                }
                try {
                    $result = self::ingest((int) $service['id'], (int) $service['customer_id'], $row, 'provider', (int) $service['is_demo']);
                } catch (\Throwable $e) {
                    // One bad debit (transient DB error) must not abort the
                    // whole run — record and move on. It also must not be
                    // stamped as synced: this service's watermark stays put so
                    // the same period is retried next run.
                    $stats['errors']++;
                    Audit::error('usage.ingest_failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
                    $failed[(int) $service['id']] = true;
                    continue;
                }
                $stats['ingested'] += $result['ingested'];
                $stats['debited']  += $result['debited'];
                // Re-stage whenever the wallet actually moved — an ingest OR a
                // crash-recovery back-fill can cross a policy threshold.
                if ($result['ingested'] || $result['debited']) {
                    $touched[(int) $service['customer_id']] = true;
                }
            }

            $clean = array_diff(wp_list_pluck($map, 'id'), array_keys($failed));
            Services::mark_synced_many($clean);
        }
    }

    /**
     * Lower bound for ONE service: its own watermark, so a missed cron tick is
     * caught up on the next run instead of falling out of a fixed 48-hour
     * window (EX-123). Clamped below so a service that has not synced in
     * months cannot ask a provider for unbounded history, and floored to the
     * hour so services on the same cadence share one provider call.
     */
    private static function since_for_service(array $service): string
    {
        $default = time() - self::FIRST_SYNC_HOURS * HOUR_IN_SECONDS;
        $floor   = time() - self::MAX_LOOKBACK_DAYS * DAY_IN_SECONDS;
        $mark    = (string) (isset($service['last_synced_at']) ? $service['last_synced_at'] : '');
        $ts      = $mark !== '' ? strtotime($mark . ' UTC') : false;
        $ts      = $ts ?: $default;
        return gmdate('Y-m-d H:00:00', max($floor, $ts));
    }

    /**
     * Idempotent single-record ingestion: INSERT IGNORE on the period key;
     * the ledger debit references the usage row ID, itself unique.
     *
     * `$is_demo` is the SERVICE's own flag, not the ambient plugin mode: a
     * demo service must stamp demo rows and debits forever, even after the
     * site leaves demo mode, or seeded demo inventory manufactures real
     * revenue and real customer debt the moment the reseller goes live.
     *
     * @return array{ingested:int,debited:int}
     */
    public static function ingest(int $service_id, int $customer_id, UsageRow $row, string $source = 'provider', int $is_demo = 0): array
    {
        $cost  = max(0, (int) $row->cost);
        $price = PricingEngine::apply_markup($cost, self::markup_percent());

        $written = self::record(
            $service_id, $customer_id, $row->period_start, $row->period_end,
            (float) $row->quantity, (string) $row->unit, $cost, $price, $source, $is_demo
        );
        if ($written['id'] === 0) {
            return ['ingested' => 0, 'debited' => 0];
        }

        // The debit is the customer price, not the upstream cost — reselling
        // at cost is 0% margin on the entire metered path (EX-031). On a
        // replay this is the crash-recovery back-fill: if a prior run died
        // between the usage INSERT and its ledger debit, the debit is missing
        // and usage would be lost. Ledger's UNIQUE(ref) makes it safe either way.
        $debit_id = Ledger::append(
            $customer_id, 'usage_debit', $price, 'usage', (string) $written['id'],
            sprintf(__('مصرف سرویس #%1$d (%2$s تا %3$s)', 'arvan-reseller'), $service_id, $row->period_start, $row->period_end),
            'system', (bool) $is_demo
        );
        return ['ingested' => $written['new'] ? 1 : 0, 'debited' => $debit_id ? 1 : 0];
    }

    /**
     * The one writer for `usage_records` — metered usage and renewal terms
     * both land here so the cost/price/is_demo/source columns can never drift
     * between the two paths.
     *
     * `$is_demo` defaults to the ambient plugin mode for callers that have no
     * originating row of their own to ask (none, currently — every caller
     * passes its service's own flag explicitly), but it is never derived here
     * from "is the SITE in demo mode right now": a usage row is a permanent
     * fact about the service it billed, not about the request that wrote it.
     *
     * @return array{id:int,new:bool} id 0 means the row could not be written
     */
    public static function record(
        int $service_id,
        int $customer_id,
        string $period_start,
        string $period_end,
        float $quantity,
        string $unit,
        int $cost,
        int $price,
        string $source,
        ?int $is_demo = null
    ): array {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            'INSERT IGNORE INTO ' . self::table() .
            ' (service_id, customer_id, period_start, period_end, quantity, unit, cost, price, currency, source, is_demo, created_at)
              VALUES (%d, %d, %s, %s, %f, %s, %d, %d, %s, %s, %d, %s)',
            $service_id, $customer_id, $period_start, $period_end,
            $quantity, substr($unit, 0, 16), max(0, $cost), max(0, $price), 'IRT',
            substr($source, 0, 24), $is_demo === null ? (Plugin::demo_mode() ? 1 : 0) : ($is_demo ? 1 : 0), Helpers::now()
        ));
        if ((int) $wpdb->rows_affected > 0) {
            return ['id' => (int) $wpdb->insert_id, 'new' => true];
        }
        // rows_affected, not insert_id, is the portable duplicate signal. Look
        // the row up rather than trusting the zero: INSERT IGNORE also
        // swallows data errors, and 0 there means the write never landed.
        $existing = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() . ' WHERE service_id = %d AND period_start = %s AND period_end = %s',
            $service_id, $period_start, $period_end
        ));
        return ['id' => $existing, 'new' => false];
    }

    /**
     * Markup applied to metered cost. Falls back to the global markup so an
     * unconfigured site still sells above cost rather than at it.
     */
    private static function markup_percent(): float
    {
        if (self::$markup === null) {
            $percent = Options::get('usage_markup_percent', null);
            if ($percent === null || $percent === '') {
                $percent = Options::get('global_markup', 20.0);
            }
            self::$markup = (float) $percent;
        }
        return self::$markup;
    }

    /** Drop the per-request markup memo after an admin edits pricing settings. */
    public static function flush_markup(): void
    {
        self::$markup = null;
    }

    // ------------------------------------------------------------- policy

    /**
     * Re-stage a customer and fire the configured actions (spec §5.5).
     * Thin composition of the decision and the effects so each half is
     * testable on its own (EX-128).
     */
    public static function apply_policy(int $customer_id): string
    {
        $stage = self::stage_for($customer_id);
        self::apply_actions($customer_id, $stage);
        return $stage;
    }

    /**
     * The decision, with no side effects: what stage is this customer in?
     * Reads the wallet through the indexed aggregate — deriving the balance
     * here (and again inside negative_since_days) is what made the hourly
     * sync scan the whole ledger twice per customer (EX-029).
     */
    public static function stage_for(int $customer_id): string
    {
        $balance = Ledger::balance($customer_id);
        // Per-customer grace period overrides the global default when set.
        $rule       = \ArvanReseller\Customers\Rules::get($customer_id);
        $grace_days = ($rule && $rule['grace_days'] !== null)
            ? (int) $rule['grace_days']
            : (int) Options::get('policy_grace_days', 3);

        return PolicyEngine::stage(
            $balance['available'],
            (int) Options::get('policy_warning', 500000),
            (int) Options::get('policy_critical', 100000),
            $grace_days,
            $balance['available'] > 0 ? null : Ledger::negative_since_days($customer_id)
        );
    }

    /** The effects: persist the stage, notify, and apply or lift service holds. */
    public static function apply_actions(int $customer_id, string $stage): void
    {
        $previous = (string) get_user_meta($customer_id, 'arvrs_policy_stage', true);
        update_user_meta($customer_id, 'arvrs_policy_stage', $stage);

        // Only act when the stage actually WORSENED — re-running apply_policy
        // hourly at a steady stage must not re-notify (admin flood fix).
        $worsened = self::rank($stage) > self::rank($previous);
        $actions  = PolicyEngine::actions_for($stage, (array) Options::get('policy_actions', []));

        self::notify_for_stage($customer_id, $stage, $worsened, $actions);
        self::apply_service_holds($customer_id, $stage, $actions);
        // 'block_purchases' is enforced at checkout via the stage lookup.
    }

    /** @param string[] $actions */
    private static function notify_for_stage(int $customer_id, string $stage, bool $worsened, array $actions): void
    {
        $messages = self::stage_messages();
        if (in_array('notify_customer', $actions, true) && isset($messages[$stage])) {
            list($type, $title, $body) = $messages[$stage];
            Notifier::customer($customer_id, $type, $title, $body);
        }
        if (in_array('notify_admin', $actions, true) && $worsened) {
            Notifier::admin('customer_at_risk', __('اعتبار مشتری در وضعیت هشدار', 'arvan-reseller'),
                sprintf(__('مشتری #%1$d به وضعیت «%2$s» رسید.', 'arvan-reseller'), $customer_id, $stage));
        }
    }

    /**
     * Local, reversible holds only: the service is flagged, never deleted or
     * powered off — the plugin does not destroy a remote resource on a local
     * balance calculation (spec §5.5). Every write goes through Services so
     * the status whitelist applies (EX-128).
     *
     * @param string[] $actions
     */
    private static function apply_service_holds(int $customer_id, string $stage, array $actions): void
    {
        if (in_array('suspend_service', $actions, true)) {
            Services::bulk_set_status($customer_id, ['active', 'at_risk'], 'suspended');
            return;
        }
        // Any stage whose actions no longer include suspend_service — anything
        // short of RESTRICTED, including a CRITICAL/GRACE reached by a partial
        // top-up — lifts a prior local hold. Gating on the action set rather
        // than a HEALTHY/WARNING whitelist is what makes a partial top-up
        // actually reactivate the customer.
        Services::bulk_set_status($customer_id, ['suspended'], 'active');

        if (in_array('mark_at_risk', $actions, true)) {
            Services::bulk_set_status($customer_id, ['active'], 'at_risk');
            return;
        }
        // Clear the at-risk flag only once genuinely recovered.
        if ($stage === PolicyEngine::HEALTHY || $stage === PolicyEngine::WARNING) {
            Services::bulk_set_status($customer_id, ['at_risk'], 'active');
        }
    }

    /** Severity order, so "worsened" is a comparison and not a special case. */
    private static function rank(string $stage): int
    {
        $rank = [
            PolicyEngine::HEALTHY    => 0,
            PolicyEngine::WARNING    => 1,
            PolicyEngine::CRITICAL   => 2,
            PolicyEngine::GRACE      => 3,
            PolicyEngine::RESTRICTED => 4,
        ];
        return isset($rank[$stage]) ? $rank[$stage] : 0;
    }

    /**
     * Customer-facing copy per stage. A method rather than a class constant
     * because every string must pass through __() and a constant expression
     * cannot call a function.
     *
     * @return array<string,array{0:string,1:string,2:string}>
     */
    private static function stage_messages(): array
    {
        return [
            PolicyEngine::WARNING    => ['low_balance', __('اعتبار شما رو به پایان است', 'arvan-reseller'), __('برای جلوگیری از وقفه در سرویس‌ها، کیف پول خود را شارژ کنید.', 'arvan-reseller')],
            PolicyEngine::CRITICAL   => ['critical_balance', __('اعتبار شما بحرانی است', 'arvan-reseller'), __('اعتبار باقی‌مانده بسیار کم است. هم‌اکنون کیف پول خود را شارژ کنید.', 'arvan-reseller')],
            PolicyEngine::GRACE      => ['suspension_warning', __('هشدار تعلیق سرویس', 'arvan-reseller'), __('اعتبار شما تمام شده است. در صورت عدم شارژ، سرویس‌ها محدود خواهند شد.', 'arvan-reseller')],
            PolicyEngine::RESTRICTED => ['suspension_warning', __('سرویس‌های شما محدود شد', 'arvan-reseller'), __('به دلیل اتمام اعتبار، خرید جدید و برخی امکانات غیرفعال شده است.', 'arvan-reseller')],
        ];
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

    /**
     * Metered usage rows with no matching ledger debit. Per-service watermark
     * stamping (see `sync_page()`) is what stops a failed debit from being
     * silently skipped over on the next run, but this is the finder for a row
     * that already got lost before that fix — an older run, or the narrower
     * crash window between the usage INSERT and the ledger append inside a
     * single `ingest()` call. Renewal-sourced rows are excluded: they debit
     * through a different business key (`ref_type = 'renewal'`), not this one.
     *
     * @return array usage_records rows with no corresponding usage_debit entry
     */
    public static function orphaned_debits(int $limit = 100): array
    {
        global $wpdb;
        $limit = max(1, $limit);

        // A JOIN keyed on `l.ref_id = u.id` would lean on cross-engine
        // int/varchar coercion; safer to compare ref_id as the string it is
        // stored as everywhere else in this codebase — so the candidates are
        // fetched bounded, then checked against the ledger in one batched
        // lookup rather than one query per row.
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE source <> 'renewal' ORDER BY id DESC LIMIT %d",
            min(5000, $limit * 20)
        ), ARRAY_A) ?: [];
        if (!$candidates) {
            return [];
        }

        $ids   = array_map(static function ($row) { return (string) $row['id']; }, $candidates);
        $place = implode(',', array_fill(0, count($ids), '%s'));
        $debited = $wpdb->get_col($wpdb->prepare(
            "SELECT ref_id FROM " . Ledger::table() . " WHERE ref_type = 'usage' AND type = 'usage_debit' AND ref_id IN ($place)",
            ...$ids
        )) ?: [];
        $debited = array_flip($debited);

        $orphans = [];
        foreach ($candidates as $row) {
            if (!isset($debited[(string) $row['id']])) {
                $orphans[] = $row;
                if (count($orphans) >= $limit) {
                    break;
                }
            }
        }
        return $orphans;
    }
}
