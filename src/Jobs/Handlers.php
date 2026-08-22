<?php
namespace ArvanReseller\Jobs;

use ArvanReseller\Arvan\Credentials;
use ArvanReseller\Arvan\RealProvider;
use ArvanReseller\Audit\Audit;
use ArvanReseller\Install\Schema;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Plugin;
use ArvanReseller\Provisioning\Provisioner;
use ArvanReseller\Services\Services;
use ArvanReseller\Support\Crypto;
use ArvanReseller\Support\Options;

defined('ABSPATH') || exit;

/**
 * The built-in job handlers: the one place where the durable queue meets the
 * application modules. JobRunner itself imports nothing from Provisioning,
 * Usage or Billing any more — it looks the type up in a filterable map — so
 * the queue stays the clean extraction seam ADR-0011 claims it is, and a
 * companion plugin can add a type through `arvrs_job_handlers` or
 * `JobRunner::handle()` without editing infrastructure.
 *
 * `register()` is called from Plugin::boot, the composition root, which is the
 * layer allowed to see both sides.
 *
 * Handler contract: return on success, throw on a failure worth retrying.
 * Attempts, backoff and dead-lettering belong to JobRunner.
 */
final class Handlers
{
    /** Give up filling in a service's address after this many polls (~27 min). */
    private const POLL_MAX = 10;

    public static function register(): void
    {
        JobRunner::handle('provision_order', [self::class, 'provision_order']);
        JobRunner::handle('poll_service', [self::class, 'poll_service']);
        JobRunner::handle('usage_sync', [self::class, 'usage_sync']);
        JobRunner::handle('renew_services', [self::class, 'renew_services']);
        JobRunner::handle('renewal_reminders', [self::class, 'renewal_reminders']);
        JobRunner::handle('credential_health', [self::class, 'credential_health']);
        JobRunner::handle('repair_ledger', [self::class, 'repair_ledger']);
        JobRunner::handle('prune', [self::class, 'prune']);
    }

    /**
     * Provision one paid order.
     *
     * The runner used to decide retry-vs-success by `strpos()` on the message,
     * which was English in two paths and Persian in the rest — so a
     * permanently misconfigured credential burned five attempts, and an order
     * frozen mid-provision was reported as a *success*. It branches on `kind`
     * now, and nothing here reads the message.
     */
    public static function provision_order(array $payload): void
    {
        $order_id = (int) ($payload['order_id'] ?? 0);
        $result   = Provisioner::provision($order_id);
        $kind     = (string) ($result['kind'] ?? '');

        // The only two kinds where trying again can still change the outcome.
        // `not_claimable` is one of them: the order is mid-flight or held by
        // another worker, and a later attempt reclaims it once it goes stale.
        if ($kind === 'retryable' || $kind === 'not_claimable') {
            throw new \RuntimeException($kind . ' (order ' . $order_id . '): ' . (string) ($result['message'] ?? ''));
        }
        // provisioned | already | not_found | failed are all terminal for this
        // job. `failed` already moved the order to provision_failed and told
        // both the admin and the customer; repeating it would only delay them.
    }

    /**
     * Finish the connection details of a freshly created resource.
     *
     * `create()` returns as soon as the upstream accepts, because the fifteen
     * seconds of `sleep()` it used to spend waiting for an IP happened inside
     * the payment callback. This job does that waiting out of band.
     */
    public static function poll_service(array $payload): void
    {
        $service_id = (int) ($payload['service_id'] ?? 0);
        $service    = $service_id > 0 ? Services::get($service_id) : null;
        if (!$service) {
            return;
        }
        $status = (string) $service['status'];
        $remote = (string) $service['remote_id'];
        // 'terminated' and 'failed' are not, and have never been, values
        // Services::STATUSES allows — terminate() writes 'cancelled', so those
        // two branches were dead strings this could never match.
        if ($remote === '' || $status === 'cancelled') {
            return; // nothing left to complete
        }

        $product  = (string) $service['product'];
        // A ProviderError here propagates: the runner backs off and retries,
        // which is exactly right for an upstream that is still booting.
        $resource = Plugin::arvan($product)->status($product, $remote);

        Services::update_connection($service_id, $resource->connection, (string) $resource->status);

        $attempt = max(1, (int) ($payload['attempt'] ?? 1));
        if ((string) $resource->status !== 'creating') {
            return;
        }
        if ($attempt < self::POLL_MAX) {
            // Linear backoff: most servers are addressable within a minute,
            // but a slow region must not cost us the whole retry budget.
            JobRunner::enqueue('poll_service', ['service_id' => $service_id, 'attempt' => $attempt + 1], 30 * $attempt);
            return;
        }
        Notifier::admin('provision_failed',
            __('سرویس روی آروان آماده نشد', 'arvan-reseller'),
            sprintf(
                /* translators: 1: service id, 2: remote resource id */
                __('سرویس #%1$d (شناسهٔ بیرونی %2$s) پس از چند بار بررسی هنوز در وضعیت «در حال ساخت» است و باید دستی بررسی شود.', 'arvan-reseller'),
                $service_id, $remote
            ));
        Audit::error('provision.poll_timeout', ['service' => $service_id, 'remote' => $remote]);
    }

    /** Metered usage ingestion. The cursor rides in the payload when chunked. */
    public static function usage_sync(array $payload): void
    {
        // sync_all() takes the whole payload, not a bare cursor: passing the
        // int was an unconditional TypeError that dead-lettered every chunked
        // run, so any install large enough to page never metered past the
        // first budget slice.
        $after = max(0, (int) (isset($payload['after_id']) ? $payload['after_id'] : 0));
        \ArvanReseller\Usage\UsageSync::sync_all(['after_id' => $after]);
    }

    /** Term-based recurring charges (Billing\Renewals is the revenue engine). The cursor rides in the payload when chunked. */
    public static function renew_services(array $payload): void
    {
        \ArvanReseller\Billing\Renewals::run_due($payload);
    }

    public static function renewal_reminders(array $payload): void
    {
        \ArvanReseller\Billing\Renewals::remind();
    }

    /**
     * Daily connection test for every enabled credential.
     *
     * Without this the recorded health only ever changed when a human clicked
     * «آزمایش اتصال», so a token revoked upstream kept rendering as «متصل»
     * while every provisioning failed — the operator's first-look diagnostic
     * actively misleading them during the most common incident there is.
     */
    public static function credential_health(array $payload): void
    {
        global $wpdb;
        $checked = 0;
        $failed  = 0;

        foreach (Credentials::all() as $row) {
            if (empty($row['enabled'])) {
                continue;
            }
            $id      = (int) $row['id'];
            $checked++;

            // Credentials:: deliberately never hands plaintext to callers; this
            // is the one runtime reader that needs it, and it dies with scope.
            $token = Crypto::decrypt((string) $wpdb->get_var($wpdb->prepare(
                'SELECT token_enc FROM ' . Credentials::table() . ' WHERE id = %d',
                $id
            )));
            if ($token === null) {
                $failed++;
                Credentials::record_test($id, false, 'decryption failed (site salts rotated?)');
                self::alert_credential($id, (string) $row['name'], __('رمزگشایی توکن ممکن نیست؛ کلیدهای امنیتی سایت تغییر کرده‌اند.', 'arvan-reseller'));
                continue;
            }

            $provider = new RealProvider(['id' => $id, 'token' => $token]);
            try {
                $result = $provider->test_connection();
            } catch (\Throwable $e) {
                $result = ['ok' => false, 'message' => $e->getMessage()];
            }
            $ok = !empty($result['ok']);
            Credentials::record_test($id, $ok, $ok ? '' : (string) ($result['message'] ?? ''));
            if (!$ok) {
                $failed++;
                self::alert_credential($id, (string) $row['name'], (string) ($result['message'] ?? ''));
            }
        }

        // A credential flipping to unverified can flip the whole plugin into
        // forced demo mode, and that answer is memoised per request.
        Plugin::flush_mode_cache();
        Audit::log(0, 'credential.health', 'credential', '', ['checked' => $checked, 'failed' => $failed], 'info');
    }

    /**
     * Rewrite the two ledger entries of a settled payment whose write was
     * swallowed. Idempotent: Ledger reports how many rows were actually needed.
     */
    public static function repair_ledger(array $payload): void
    {
        $written = \ArvanReseller\Wallet\Ledger::repair_order_entries(
            (int) ($payload['customer_id'] ?? 0),
            (string) ($payload['payment_ref'] ?? ''),
            (int) ($payload['amount'] ?? 0),
            (int) ($payload['order_id'] ?? 0)
        );
        if ($written > 0) {
            Audit::log(0, 'ledger.repaired', 'order', (string) ($payload['order_id'] ?? 0), ['entries' => $written]);
        }
    }

    /**
     * Retention. The ledger is append-only forever by design; diagnostics,
     * finished job rows and raw provider payloads are not.
     */
    public static function prune(array $payload): void
    {
        $days   = max(7, (int) Options::get('data_retention_days', 90));
        $counts = Schema::prune($days);
        $counts['jobs'] = JobRunner::prune($days);
        Audit::log(0, 'retention.pruned', '', '', $counts, 'info');
    }

    private static function alert_credential(int $id, string $name, string $message): void
    {
        // Notifier cools `credential_failed` down, so a daily job cannot turn
        // one broken token into a daily inbox.
        Notifier::admin('credential_failed',
            __('اتصال آروان کار نمی‌کند', 'arvan-reseller'),
            sprintf(
                /* translators: 1: credential name, 2: credential id, 3: error message */
                __('آزمایش خودکار اتصال «%1$s» (#%2$d) ناموفق بود: %3$s', 'arvan-reseller'),
                $name !== '' ? $name : (string) $id, $id, $message
            ));
    }
}
