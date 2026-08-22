<?php
namespace ArvanReseller\Billing;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Jobs\JobRunner;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Orders\OrderService;
use ArvanReseller\Pricing\BaseCosts;
use ArvanReseller\Reports\Reports;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Helpers;
use ArvanReseller\Support\Options;
use ArvanReseller\Usage\UsageSync;
use ArvanReseller\Wallet\Ledger;

defined('ABSPATH') || exit;

/**
 * Recurring revenue (spec §5.6, ADR-0007).
 *
 * The reseller sells a TERM, and ArvanCloud bills their upstream account for
 * every hour that term runs. A single checkout payment therefore covers month
 * one and nothing after it. ArvanCloud publishes no metering API, so upstream
 * consumption cannot be re-billed as it happens — but the term is something
 * the plugin knows exactly. Each service carries its own clock
 * (`renews_at` / `term_days` / `renewal_price`) and this class charges it.
 *
 * That debit stream is also what makes the wallet real: without it a
 * customer's balance can only ever rise, so the whole credit ladder
 * (warning → critical → grace → restricted) is unreachable in production.
 *
 * Idempotency has three independent layers, because this is money and cron is
 * not exactly-once:
 *
 *   1. `usage_records` UNIQUE(service_id, period_start, period_end)
 *   2. `ledger` UNIQUE(ref_type, ref_id, type) on ('renewal', "{id}:{start}")
 *   3. the clock advance is `UPDATE … WHERE id = %d AND renews_at = <old>`
 *
 * Layer 3 is the one that matters for concurrency: two runners that both see
 * the same due service both attempt that UPDATE and exactly one matches a
 * row. A read-then-write there would be a double charge.
 */
final class Renewals
{
    /** Default days before renewal that the customer is reminded — mirrors Options::DEFAULTS['renewal_reminder_days']. */
    private const REMINDER_DAYS = 5;

    /** Reminders sent per daily run. */
    private const REMINDER_LIMIT = 200;

    /** Services fetched per cursor page (mirrors UsageSync::PAGE). */
    private const PAGE = 200;

    /** Wall-clock budget for one run, in seconds; the remainder is re-enqueued. */
    private const BUDGET = 20;

    /** Services due for renewal now (active | at_risk | suspended). */
    public static function due(int $limit = 50, int $after_id = 0): array
    {
        return Services::due_for_renewal($limit, $after_id);
    }

    /**
     * Charge ONE service's next term.
     *
     * @return array{ok:bool,kind:string,charged:int,stage:string}
     *   kind: 'charged'|'replay'|'not_due'|'cancelled'|'no_price'|'error'
     */
    public static function charge(int $service_id): array
    {
        $service = Services::get($service_id);
        if (!$service) {
            return self::result(false, 'error', 0);
        }
        // A cancelled service, or one whose clock was stopped, never renews.
        // A SUSPENDED one does: the hold is a collection action, not a
        // termination — the resource is still running and still costing the
        // reseller upstream.
        if ((string) $service['status'] === 'cancelled' || empty($service['renews_at'])) {
            return self::result(false, 'cancelled', 0);
        }

        $period_start = (string) $service['renews_at'];
        $due_ts       = strtotime($period_start . ' UTC');
        if (!$due_ts || $due_ts > time()) {
            return self::result(false, 'not_due', 0);
        }

        $term         = max(1, (int) $service['term_days']);
        $period_end   = gmdate('Y-m-d H:i:s', $due_ts + $term * DAY_IN_SECONDS);
        $customer_id  = (int) $service['customer_id'];

        $price = self::price_for($service);
        if ($price <= 0) {
            // Renewing at zero would silently give the service away forever.
            // Stop the clock's illusion of health and tell the admin.
            Notifier::admin('renewal_no_price', __('تمدید بدون قیمت متوقف شد', 'arvan-reseller'),
                sprintf(__('سرویس #%1$d قیمت تمدید ندارد و تمدید نشد. قیمت تمدید را در بخش سرویس‌ها تعیین کنید.', 'arvan-reseller'), $service_id));
            Audit::error('renewal.no_price', ['service' => $service_id, 'customer' => $customer_id]);
            return self::result(false, 'no_price', 0);
        }

        // The upstream figure, recorded rather than assumed, so recurring
        // margin lands in the same report as order margin (Reports::period).
        $cost = max(0, BaseCosts::get((string) $service['product'], (string) $service['plan_id']));

        // The service's own flag, not the ambient mode: a demo service must
        // keep stamping demo rows even after the site has gone live, or
        // seeded demo inventory manufactures real revenue.
        $is_demo = (int) $service['is_demo'];

        $usage = UsageSync::record(
            $service_id, $customer_id, $period_start, $period_end,
            1.0, 'term', $cost, $price, 'renewal', $is_demo
        );
        if ($usage['id'] === 0) {
            Audit::error('renewal.record_failed', ['service' => $service_id, 'period' => $period_start]);
            return self::result(false, 'error', 0);
        }

        // The business key is the service AND the period: a replayed run for
        // the same term is absorbed here, a genuinely new term is not.
        $ref = $service_id . ':' . $period_start;
        try {
            $entry = Ledger::append(
                $customer_id, 'service_charge', $price, 'renewal', $ref,
                sprintf(__('تمدید سرویس #%1$d تا %2$s', 'arvan-reseller'), $service_id, $period_end),
                'system', (bool) $is_demo
            );
        } catch (\Throwable $e) {
            Audit::error('renewal.ledger_failed', ['service' => $service_id, 'ref' => $ref, 'error' => $e->getMessage()]);
            return self::result(false, 'error', 0);
        }

        // 0 from append() is a replay, which is a SUCCESS: the term was
        // already billed. The clock may still be un-advanced if the previous
        // run died between the ledger write and the UPDATE — the WHERE guard
        // makes advancing it now safe, and a no-op if it already moved.
        $replay   = ($entry === 0);
        $advanced = Services::advance_renewal($service_id, $period_start, $period_end);

        if (!$advanced && !$replay) {
            // Lost the clock race after writing the ledger entry: the entry is
            // keyed on this exact period, so the winner wrote the same one and
            // one of us got the insert. Nothing is double-charged, but say so.
            Audit::log(0, 'renewal.race', 'service', (string) $service_id, ['period' => $period_start]);
        }

        $stage = UsageSync::apply_policy($customer_id);

        if (!$replay) {
            Audit::log(0, 'renewal.charged', 'service', (string) $service_id, [
                'customer' => $customer_id,
                'price'    => $price,
                'cost'     => $cost,
                'period'   => $period_start . '..' . $period_end,
            ]);
            self::notify_charged($customer_id, $service_id, $price, $period_end, $stage);
        }

        return [
            'ok'      => true,
            'kind'    => $replay ? 'replay' : 'charged',
            'charged' => $replay ? 0 : $price,
            'stage'   => $stage,
        ];
    }

    /**
     * Run the due batch. Cursor-paged and time-boxed like
     * `UsageSync::sync_all()` — a fixed 50/day cap on `due()` alone meant the
     * backlog above it grew without bound and invisibly (30-day terms × 50/day
     * = 1,500 services, far under the documented capacity). Whatever does not
     * fit in the wall-clock budget is re-enqueued with a resume cursor instead
     * of silently dropped until tomorrow.
     *
     * @param array $payload {@type int $after_id} cursor from the previous run
     * @return array{due:int,charged:int,replayed:int,errors:int,amount:int,requeued:int,next_after_id:int}
     */
    public static function run_due(array $payload = []): array
    {
        $stats   = ['due' => 0, 'charged' => 0, 'replayed' => 0, 'errors' => 0, 'amount' => 0, 'requeued' => 0, 'next_after_id' => 0];
        $after   = max(0, (int) (isset($payload['after_id']) ? $payload['after_id'] : 0));
        $started = time();

        while (true) {
            $due = self::due(self::PAGE, $after);
            if (!$due) {
                $after = 0; // population exhausted — next scheduled run starts over
                break;
            }
            $last = end($due);
            $after = (int) $last['id'];
            $stats['due'] += count($due);

            foreach ($due as $service) {
                try {
                    $result = self::charge((int) $service['id']);
                } catch (\Throwable $e) {
                    // One bad service must not abort the batch — the rest are
                    // still owed money.
                    $stats['errors']++;
                    Audit::error('renewal.failed', ['service' => (int) $service['id'], 'error' => $e->getMessage()]);
                    continue;
                }
                if ($result['kind'] === 'charged') {
                    $stats['charged']++;
                    $stats['amount'] += $result['charged'];
                } elseif ($result['kind'] === 'replay') {
                    $stats['replayed']++;
                } elseif (!$result['ok'] && $result['kind'] !== 'not_due' && $result['kind'] !== 'cancelled') {
                    $stats['errors']++;
                }
            }

            if (count($due) < self::PAGE) {
                $after = 0;
                break;
            }
            if (time() - $started >= self::BUDGET) {
                break; // out of budget with rows left: the cursor carries the rest
            }
        }

        if ($after > 0) {
            // A resume point, not a restart: charge()'s idempotency layers
            // make a repeat safe, they do not make it cheap.
            JobRunner::enqueue('renew_services', ['after_id' => $after], 60);
            $stats['requeued']      = 1;
            $stats['next_after_id'] = $after;
        }

        update_option('arvrs_last_renewal_run', ['at' => gmdate('Y-m-d H:i:s'), 'stats' => $stats], false);
        Audit::log(0, 'renewal.run', 'system', '', $stats);
        return $stats;
    }

    /** Last renewal batch, counts included. @return array{at:string,stats:array} */
    public static function last_run(): array
    {
        $raw = get_option('arvrs_last_renewal_run', []);
        if (!is_array($raw)) {
            return ['at' => '', 'stats' => []];
        }
        return [
            'at'    => (string) (isset($raw['at']) ? $raw['at'] : ''),
            'stats' => (array) (isset($raw['stats']) ? $raw['stats'] : []),
        ];
    }

    /**
     * Warn customers whose renewal lands inside the reminder window, so a
     * charge against an empty wallet is never the first they hear of it.
     *
     * @return array{window_days:int,found:int,notified:int}
     */
    public static function remind(): array
    {
        $days  = max(1, (int) Options::get('renewal_reminder_days', self::REMINDER_DAYS));
        $from  = Helpers::now();
        $to    = gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS);
        $rows  = Services::renewing_between($from, $to, self::REMINDER_LIMIT);
        $stats = ['window_days' => $days, 'found' => count($rows), 'notified' => 0];

        foreach ($rows as $service) {
            $service_id = (int) $service['id'];
            // ponytail: once-per-term de-duplication via a transient keyed on
            // the service and the exact renewal date. A lost transient costs
            // one extra reminder, never a missed one; a per-service
            // `reminded_at` column would be the durable upgrade.
            $key = 'arvrs_renew_rem_' . $service_id . '_' . md5((string) $service['renews_at']);
            if (get_transient($key)) {
                continue;
            }
            $price = self::price_for($service);
            Notifier::customer(
                (int) $service['customer_id'],
                'renewal_reminder',
                __('تمدید سرویس شما نزدیک است', 'arvan-reseller'),
                sprintf(
                    __('سرویس #%1$d در تاریخ %2$s تمدید می‌شود و مبلغ %3$s از کیف پول شما کسر خواهد شد. لطفاً از کافی بودن اعتبار مطمئن شوید.', 'arvan-reseller'),
                    $service_id,
                    self::date_label((string) $service['renews_at']),
                    Helpers::money($price)
                )
            );
            set_transient($key, 1, max(2, $days) * DAY_IN_SECONDS);
            $stats['notified']++;
        }

        Audit::log(0, 'renewal.reminders', 'system', '', $stats);
        return $stats;
    }

    /** Sum of live services' renewal_price normalised to 30 days. */
    public static function mrr(bool $include_demo = false): int
    {
        return Reports::mrr($include_demo);
    }

    /**
     * Stop future charges. The remote resource is untouched and the service
     * keeps running to the end of the term already paid for — terminating it
     * is a separate, explicit admin action.
     */
    public static function cancel(int $service_id, string $actor = 'admin'): bool
    {
        $service = Services::get($service_id);
        if (!$service) {
            return false;
        }
        if (!Services::cancel_renewal($service_id)) {
            return false; // already cancelled — not an error, but nothing changed
        }
        Audit::log(0, 'renewal.cancelled', 'service', (string) $service_id, [
            'customer' => (int) $service['customer_id'],
            'actor'    => $actor,
            'until'    => (string) $service['renews_at'],
        ]);
        Notifier::customer(
            (int) $service['customer_id'],
            'renewal_cancelled',
            __('تمدید خودکار لغو شد', 'arvan-reseller'),
            sprintf(
                __('تمدید خودکار سرویس #%1$d لغو شد. سرویس تا پایان دوره فعلی (%2$s) فعال می‌ماند.', 'arvan-reseller'),
                $service_id,
                self::date_label((string) $service['renews_at'])
            )
        );
        return true;
    }

    /**
     * JobRunner entry point for both daily jobs — a job handler receives the
     * job payload, so the contract's typed batch methods keep their shape.
     */
    public static function handle_job(array $payload = []): void
    {
        if (isset($payload['reminders']) && $payload['reminders']) {
            self::remind();
            return;
        }
        self::run_due($payload);
    }

    /** Reminder job handler, for a registry that maps one type to one callable. */
    public static function handle_reminder_job(array $payload = []): void
    {
        self::remind();
    }

    // ---------------------------------------------------------------- internals

    /**
     * What this term costs the customer: the service's own renewal price,
     * falling back to what the original order was actually paid at — never a
     * fresh quote, because a stored price is a promise.
     */
    private static function price_for(array $service): int
    {
        $price = (int) (isset($service['renewal_price']) ? $service['renewal_price'] : 0);
        if ($price > 0) {
            return $price;
        }
        $order = OrderService::get((int) $service['order_id']);
        return $order ? max(0, (int) $order['amount']) : 0;
    }

    private static function notify_charged(int $customer_id, int $service_id, int $price, string $until, string $stage): void
    {
        $body = sprintf(
            __('مبلغ %1$s بابت تمدید سرویس #%2$d از کیف پول شما کسر شد. سرویس تا %3$s فعال است.', 'arvan-reseller'),
            Helpers::money($price),
            $service_id,
            self::date_label($until)
        );
        if ($stage === \ArvanReseller\Policies\PolicyEngine::GRACE || $stage === \ArvanReseller\Policies\PolicyEngine::RESTRICTED) {
            $body .= ' ' . __('اعتبار کیف پول شما منفی شده است؛ لطفاً هرچه سریع‌تر شارژ کنید.', 'arvan-reseller');
        }
        Notifier::customer($customer_id, 'renewal_charged', __('سرویس شما تمدید شد', 'arvan-reseller'), $body);
    }

    /**
     * A date a Persian-first customer can read. `Helpers::jdate()` is the
     * Jalali converter every customer-facing date is meant to go through; it
     * is owned by another module and may not be present yet, so degrade to
     * Persian digits rather than fatal on a notification.
     */
    private static function date_label(string $utc_datetime): string
    {
        if (method_exists('ArvanReseller\\Support\\Helpers', 'jdate')) {
            return Helpers::jdate($utc_datetime);
        }
        return Helpers::fa_digits(substr($utc_datetime, 0, 10));
    }

    /** @return array{ok:bool,kind:string,charged:int,stage:string} */
    private static function result(bool $ok, string $kind, int $charged): array
    {
        return ['ok' => $ok, 'kind' => $kind, 'charged' => $charged, 'stage' => ''];
    }
}
