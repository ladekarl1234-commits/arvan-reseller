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
 */
final class JobRunner
{
    private const BATCH = 5;
    /** Backoff minutes by attempt number (1-based). */
    private const BACKOFF = [1, 2, 5, 15, 30];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_jobs';
    }

    public static function register_hooks(): void
    {
        add_filter('cron_schedules', static function (array $schedules) {
            $schedules['arvrs_minutely'] = ['interval' => 60, 'display' => 'Every minute (Arvan Reseller jobs)'];
            return $schedules;
        });
        add_action('arvrs_run_jobs', [self::class, 'run_due']);
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
        $payload = json_decode((string) $job['payload'], true) ?: [];

        try {
            self::execute((string) $job['type'], $payload);
            $wpdb->update(self::table(), ['status' => 'done', 'updated_at' => Helpers::now()], ['id' => $job_id]);
            return true;
        } catch (\Throwable $e) {
            $attempts = (int) $job['attempts'];
            $dead     = $attempts >= (int) $job['max_attempts'];
            $backoff  = self::BACKOFF[min($attempts, count(self::BACKOFF)) - 1] * 60;
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

    /** Job-type dispatch map. Adding a job type = adding one case. */
    private static function execute(string $type, array $payload): void
    {
        switch ($type) {
            case 'provision_order':
                $result = \ArvanReseller\Provisioning\Provisioner::provision((int) ($payload['order_id'] ?? 0));
                // "already provisioned"/"not claimable" are success for the job.
                if (!$result['ok'] && strpos($result['message'], 'not claimable') === false && strpos($result['message'], 'not found') === false) {
                    throw new \RuntimeException($result['message']);
                }
                break;
            case 'usage_sync':
                \ArvanReseller\Usage\UsageSync::sync_all();
                break;
            default:
                throw new \RuntimeException('Unknown job type: ' . $type);
        }
    }

    public static function stats(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) c FROM ' . self::table() . ' GROUP BY status', ARRAY_A) ?: [];
        $out  = ['pending' => 0, 'running' => 0, 'done' => 0, 'dead' => 0];
        foreach ($rows as $r) {
            $out[$r['status']] = (int) $r['c'];
        }
        return $out;
    }

    public static function failed(int $limit = 20): array
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            'SELECT id, type, attempts, last_error, run_at, updated_at, status FROM ' . self::table() .
            " WHERE status = 'dead' OR (status = 'pending' AND attempts > 0) ORDER BY updated_at DESC LIMIT %d",
            $limit
        ), ARRAY_A) ?: [];
    }

    /** Admin action: requeue a dead job. */
    public static function retry(int $job_id): bool
    {
        global $wpdb;
        return (bool) $wpdb->query($wpdb->prepare(
            'UPDATE ' . self::table() . " SET status = 'pending', attempts = 0, run_at = %s, updated_at = %s
             WHERE id = %d AND status = 'dead'",
            gmdate('Y-m-d H:i:s'), Helpers::now(), $job_id
        ));
    }
}
