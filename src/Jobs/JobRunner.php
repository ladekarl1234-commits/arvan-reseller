<?php
namespace ArvanReseller\Jobs;

use ArvanReseller\Audit\Audit;
use ArvanReseller\Notifications\Notifier;
use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Durable jobs table + WP-Cron runner (ADR-0004). WP-Cron is traffic-
 * triggered, so durability lives in the table, not the scheduler: a missed
 * tick only delays execution. Claiming is an optimistic single-row UPDATE —
 * two concurrent runners cannot both win the same job. Production scaling
 * path: point real server cron at wp-cron.php; the table and runner are
 * unchanged (docs/SCALABILITY.md).
 *
 * Durability also means every state must have an exit. `running` did not: a
 * PHP fatal, an OOM or a max_execution_time kill lands in neither branch of
 * the try/catch, so the row stayed claimed forever — invisible to the due
 * query, to the failed list and to the retry button. `reap_stale()` runs at
 * the head of every tick and is what makes the claim honest.
 *
 * Dispatch is a registry, not a switch: the queue knows nothing about
 * Provisioning, Usage or Billing. Jobs\Handlers registers the built-ins
 * from the composition root, and `arvrs_job_handlers` lets a companion plugin
 * add its own type without editing this file.
 */
final class JobRunner
{
    private const BATCH = 5;
    /** Backoff minutes by attempt number (1-based). */
    private const BACKOFF = [1, 2, 5, 15, 30];
    /** A claim older than this means the worker that took it is gone. */
    private const STALE_MINUTES = 15;

    /** @var array<string,callable> type => handler(array $payload): void */
    private static $handlers = [];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_jobs';
    }

    public static function register_hooks(): void
    {
        // The arvrs_minutely interval itself is registered at plugin-file
        // scope (arvan-reseller.php) so the activation request sees it too.
        add_action('arvrs_run_jobs', [self::class, 'run_due']);
    }

    /**
     * Register a job type. A handler receives the decoded payload, returns on
     * success and throws on a failure worth retrying — attempts, backoff and
     * dead-lettering stay here.
     */
    public static function handle(string $type, callable $handler): void
    {
        self::$handlers[$type] = $handler;
    }

    /** @return array<string,callable> the dispatch map, filterable. */
    public static function handlers(): array
    {
        return apply_filters('arvrs_job_handlers', self::$handlers);
    }

    public static function enqueue(string $type, array $payload, int $delay_seconds = 0): int
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'type'       => $type,
            'payload'    => wp_json_encode($payload),
            'status'     => 'pending',
            'run_at'     => gmdate('Y-m-d H:i:s', time() + $delay_seconds),
            'created_at' => Helpers::now(),
            'updated_at' => Helpers::now(),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Process due jobs; called by cron and by the admin "Run now" button. */
    public static function run_due(): int
    {
        global $wpdb;

        // Recovery first: every tick is also a reaper tick, so a crashed
        // worker costs at most STALE_MINUTES rather than forever.
        self::reap_stale();

        $due = $wpdb->get_results($wpdb->prepare(
            'SELECT id FROM ' . self::table() . " WHERE status = 'pending' AND run_at <= %s ORDER BY id ASC LIMIT %d",
            gmdate('Y-m-d H:i:s'), self::BATCH
        ), ARRAY_A) ?: [];

        $ran = 0;
        foreach ($due as $row) {
            if (self::run_one((int) $row['id'])) {
                $ran++;
            }
        }
        return $ran;
    }

    /**
     * Return jobs whose claim was abandoned to a runnable (or dead) state.
     *
     * `attempts` is already incremented at claim time, so a reaped job keeps
     * its place in the backoff ladder and still hits the dead-letter cap —
     * a job that crashes the worker every time cannot loop forever.
     *
     * @return int rows reclaimed (requeued + dead-lettered)
     */
    public static function reap_stale(int $minutes = self::STALE_MINUTES): int
    {
        global $wpdb;
        $cutoff = self::stale_cutoff($minutes);
        $note   = sprintf('reclaimed: worker did not finish; claim older than %d minutes', max(1, $minutes));

        // Past the attempt cap it is dead, not pending — otherwise a job that
        // reliably kills its worker would be retried without limit.
        $dead = (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'dead', last_error = %s, updated_at = %s
             WHERE status = 'running' AND claimed_at IS NOT NULL AND claimed_at < %s AND attempts >= max_attempts",
            $note, Helpers::now(), $cutoff
        ));

        $requeued = (int) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'pending', run_at = %s, last_error = %s, updated_at = %s
             WHERE status = 'running' AND claimed_at IS NOT NULL AND claimed_at < %s",
            gmdate('Y-m-d H:i:s'), $note, Helpers::now(), $cutoff
        ));

        if ($dead > 0) {
            Notifier::admin('job_dead', __('وظیفه‌های سیستمی متوقف شدند', 'arvan-reseller'),
                sprintf(
                    /* translators: %d: number of jobs */
                    __('%d وظیفه پس از قطع شدن پردازش و رسیدن به سقف تلاش، متوقف شد و نیاز به بررسی دارد.', 'arvan-reseller'),
                    $dead
                ));
        }
        if ($dead + $requeued > 0) {
            Audit::error('job.reaped', ['requeued' => $requeued, 'dead' => $dead, 'cutoff' => $cutoff]);
        }
        return $dead + $requeued;
    }

    private static function run_one(int $job_id): bool
    {
        global $wpdb;
        // Atomic claim: only one runner flips pending→running.
        $claimed = $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'running', claimed_at = %s, attempts = attempts + 1, updated_at = %s
             WHERE id = %d AND status = 'pending'",
            gmdate('Y-m-d H:i:s'), Helpers::now(), $job_id
        ));
        if ($claimed !== 1) {
            return false;
        }
        $job = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $job_id), ARRAY_A);
        if (!$job) {
            // The claim UPDATE matched a row that is gone by the time we read
            // it back — e.g. a concurrent prune() deleted it. Nothing to run.
            return false;
        }
        $payload = json_decode((string) $job['payload'], true) ?: [];

        try {
            self::execute((string) $job['type'], $payload);
            $wpdb->update(self::table(), ['status' => 'done', 'last_error' => null, 'updated_at' => Helpers::now()], ['id' => $job_id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int) $job['attempts'];
            $dead     = $attempts >= (int) $job['max_attempts'];
            // Clamped to a valid index even if $attempts is somehow 0 here
            // (the claim UPDATE always increments it first, but nothing
            // upstream promises >= 1 forever) — min(0, N) - 1 = -1 previously
            // read BACKOFF[-1], an undefined key.
            $backoff  = self::BACKOFF[max(0, min($attempts, count(self::BACKOFF)) - 1)] * 60;
            $wpdb->update(self::table(), [
                'status'     => $dead ? 'dead' : 'pending',
                'run_at'     => gmdate('Y-m-d H:i:s', time() + $backoff),
                'last_error' => substr($e->getMessage(), 0, 500),
                'updated_at' => Helpers::now(),
            ], ['id' => $job_id]);
            if ($dead) {
                Notifier::admin('job_dead', __('یک وظیفه سیستمی متوقف شد', 'arvan-reseller'),
                    sprintf(__('وظیفه «%1$s» پس از %2$d تلاش متوقف شد و نیاز به بررسی دارد.', 'arvan-reseller'), $job['type'], $attempts));
                Audit::error('job.dead', ['id' => $job_id, 'type' => $job['type'], 'error' => $e->getMessage()]);
            }
            return false;
        }
    }

    /** Look the type up in the registry. Adding a job type = one handle() call. */
    private static function execute(string $type, array $payload): void
    {
        $handlers = self::handlers();
        if (!isset($handlers[$type]) || !is_callable($handlers[$type])) {
            throw new \RuntimeException('Unknown job type: ' . $type);
        }
        call_user_func($handlers[$type], $payload);
    }

    /** @return array{pending:int,running:int,done:int,dead:int,stale_running:int} */
    public static function stats(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', ARRAY_A) ?: [];
        $out  = ['pending' => 0, 'running' => 0, 'done' => 0, 'dead' => 0, 'stale_running' => 0];
        foreach ($rows as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = (int) $r['c'];
            }
        }
        // A healthy in-flight job and an abandoned one look identical in the
        // `running` count; the operator needs the second number to act on.
        $out['stale_running'] = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::table() . " WHERE status = 'running' AND claimed_at IS NOT NULL AND claimed_at < %s",
            self::stale_cutoff(self::STALE_MINUTES)
        ));
        return $out;
    }

    /**
     * Jobs needing an operator: dead, retried-and-waiting, or abandoned.
     * `payload` rides along so the admin can see which order a dead
     * provisioning job belongs to, and `last_error` is returned whole — it was
     * trimmed to twelve words with no way to read the rest.
     */
    public static function failed(int $limit = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, payload, attempts, max_attempts, last_error, run_at, claimed_at, updated_at, status FROM ' . self::table() .
            " WHERE status = 'dead'
                 OR (status = 'pending' AND attempts > 0)
                 OR (status = 'running' AND claimed_at IS NOT NULL AND claimed_at < %s)
             ORDER BY updated_at DESC LIMIT %d",
            self::stale_cutoff(self::STALE_MINUTES), $limit
        ), ARRAY_A) ?: [];
    }

    /** Full row for the admin detail view — payload and error untruncated. */
    public static function detail(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id = %d', $job_id), ARRAY_A);
        return $row ?: null;
    }

    /** Admin action: requeue a dead job, or one whose worker never came back. */
    public static function retry(int $job_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, last_error = NULL, run_at = %s, updated_at = %s
             WHERE id = %d AND (status = 'dead' OR (status = 'running' AND claimed_at IS NOT NULL AND claimed_at < %s))",
            gmdate('Y-m-d H:i:s'), Helpers::now(), $job_id, self::stale_cutoff(self::STALE_MINUTES)
        ));
    }

    /**
     * Admin action: stop a job for good. Dead-lettered rather than deleted so
     * the decision stays auditable and the payload stays readable.
     */
    public static function kill(int $job_id): bool
    {
        global $wpdb;
        $killed = (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'dead', last_error = %s, updated_at = %s
             WHERE id = %d AND status <> 'done'",
            'killed by administrator', Helpers::now(), $job_id
        ));
        if ($killed) {
            Audit::log(get_current_user_id(), 'job.killed', 'job', (string) $job_id);
        }
        return $killed;
    }

    /**
     * Retention for finished jobs. The ledger is append-only forever; a `done`
     * job row is a receipt with a shelf life, and this table is otherwise the
     * fastest-growing one in the plugin.
     *
     * @return int rows deleted
     */
    public static function prune(int $days): int
    {
        global $wpdb;
        $cutoff  = gmdate('Y-m-d H:i:s', time() - (max(1, $days) * DAY_IN_SECONDS));
        $deleted = 0;
        // Bounded batches: a big table must not stall the cron request.
        for ($i = 0; $i < 50; $i++) {
            $n = (int) $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::table() . " WHERE status = 'done' AND updated_at < %s LIMIT 500",
                $cutoff
            ));
            $deleted += $n;
            if ($n < 500) {
                break;
            }
        }
        return $deleted;
    }

    private static function stale_cutoff(int $minutes): string
    {
        return gmdate('Y-m-d H:i:s', time() - (max(1, $minutes) * 60));
    }
}
