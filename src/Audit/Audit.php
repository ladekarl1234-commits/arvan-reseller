<?php
namespace ArvanReseller\Audit;

use ArvanReseller\Support\Helpers;

defined('ABSPATH') || exit;

/**
 * Append-only audit log for security-sensitive actions (SEC-10) plus a
 * redacted application log channel for observability. One table, `level`
 * distinguishes audit ('audit') from diagnostics ('info'/'error').
 *
 * Redaction happens on two axes because secrets arrive on two axes: by KEY
 * (a `token` field in a structured detail array) and by VALUE SHAPE (an
 * upstream error body that echoed back `Authorization: Bearer …`, a signed URL,
 * a bare UUID credential). Key-only redaction let all of the second kind
 * through, while the health screen claimed the list was محرمانه‌زدایی‌شده.
 */
final class Audit
{
    private const REDACT_KEYS = ['token', 'api_token', 'password', 'secret', 'authorization', 'pat'];

    /** Levels that may be pruned; 'audit' is the compliance channel and is kept. */
    public const PRUNABLE_LEVELS = ['info', 'error', 'debug'];

    /** Hard ceiling on a CSV export so an operator cannot page the whole table into memory. */
    public const EXPORT_MAX = 5000;

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'arvrs_audit_log';
    }

    /** @param array<string,mixed> $detail redacted before storage */
    public static function log(int $user_id, string $action, string $object_type = '', string $object_id = '', array $detail = [], string $level = 'audit'): void
    {
        global $wpdb;
        $wpdb->insert(self::table(), [
            'user_id'     => $user_id ?: get_current_user_id(),
            'action'      => substr($action, 0, 64),
            'object_type' => substr($object_type, 0, 32),
            'object_id'   => substr($object_id, 0, 64),
            'detail'      => wp_json_encode(self::redact($detail)),
            'ip'          => Helpers::client_ip(),
            'level'       => $level,
            'created_at'  => Helpers::now(),
        ]);
    }

    public static function error(string $action, array $detail = []): void
    {
        self::log(0, $action, '', '', $detail, 'error');
    }

    /** Recursively strip secret-looking keys AND secret-shaped values (SEC-5). */
    public static function redact(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $lk = strtolower((string) $k);
            foreach (self::REDACT_KEYS as $needle) {
                if (strpos($lk, $needle) !== false) {
                    $out[$k] = '[REDACTED]';
                    continue 2;
                }
            }
            if (is_array($v)) {
                $out[$k] = self::redact($v);
            } elseif (is_string($v)) {
                $out[$k] = self::redact_text($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    /**
     * Value-shaped redaction for free text: upstream error bodies, job errors,
     * anything a provider echoed back at us. Public because the callers that
     * store free text outside this table (job `last_error`, provider messages)
     * must be able to reuse exactly this pass.
     */
    public static function redact_text(string $text): string
    {
        // Scheme-prefixed credentials: `Bearer eyJ…`, `Apikey abc…`, `Basic …`.
        $text = (string) preg_replace(
            '/\b(Bearer|Apikey|Api-Key|Token|Basic)([\s:=]+)[A-Za-z0-9._\-\+\/=]{8,}/i',
            '$1$2[REDACTED]',
            $text
        );
        // Bare UUIDs — ArvanCloud resource *and* credential ids share this shape,
        // so anything UUID-ish in free text is treated as sensitive.
        $text = (string) preg_replace(
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i',
            '[REDACTED-ID]',
            $text
        );
        // Long unbroken high-entropy runs: signed-URL signatures, JWTs, hex keys.
        // 40+ characters with no separator is never prose.
        $text = (string) preg_replace('/[A-Za-z0-9_\-]{40,}/', '[REDACTED]', $text);
        return $text;
    }

    /** Newest-first slice for the dashboard widgets — no COUNT, no filters. */
    public static function recent(int $limit = 50, string $level = ''): array
    {
        global $wpdb;
        if ($level !== '') {
            return $wpdb->get_results($wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE level = %s ORDER BY id DESC LIMIT %d',
                $level, $limit
            ), ARRAY_A) ?: [];
        }
        return $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d', $limit
        ), ARRAY_A) ?: [];
    }

    /**
     * Investigation query: "what happened to order #4127 last Tuesday".
     *
     * Every filter maps onto an index the schema now carries
     * (`level_created`, `object`, `user_id`, `action`), so adding filters makes
     * the query cheaper, not more expensive.
     *
     * @param array $args {
     *   @type string $action      exact action name
     *   @type string $object_type e.g. 'order'
     *   @type string $object_id
     *   @type int    $user_id
     *   @type string $level       'audit'|'info'|'error'
     *   @type string $from        UTC 'Y-m-d' or 'Y-m-d H:i:s' (inclusive)
     *   @type string $to          UTC 'Y-m-d' or 'Y-m-d H:i:s' (inclusive)
     *   @type int    $page        1-based
     *   @type int    $per_page    capped at 200
     * }
     * @return array{rows:array,total:int,page:int,per_page:int,pages:int}
     */
    public static function query(array $args = []): array
    {
        global $wpdb;

        $page     = max(1, (int) ($args['page'] ?? 1));
        $per_page = min(200, max(1, (int) ($args['per_page'] ?? 50)));

        [$where, $params] = self::filters($args);
        $sql = 'SELECT * FROM ' . self::table()
             . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT %d OFFSET %d';

        $rows_params   = $params;
        $rows_params[] = $per_page;
        $rows_params[] = ($page - 1) * $per_page;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$rows_params), ARRAY_A) ?: [];

        $count_sql = 'SELECT COUNT(*) FROM ' . self::table()
                   . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
        $total = $params
            ? (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params))
            : (int) $wpdb->get_var($count_sql);

        return [
            'rows'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil($total / $per_page),
        ];
    }

    /**
     * Bounded CSV of the same filtered set, for handing an incident to someone
     * who does not have wp-admin. Caller supplies capability + nonce.
     */
    public static function export_csv(array $args = [], int $max = self::EXPORT_MAX): string
    {
        $max  = min(self::EXPORT_MAX, max(1, $max));
        $args = array_merge($args, ['page' => 1, 'per_page' => 200]);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['id', 'created_at', 'level', 'action', 'object_type', 'object_id', 'user_id', 'ip', 'detail']);

        $written = 0;
        for ($page = 1; $written < $max; $page++) {
            $args['page'] = $page;
            $batch = self::query($args);
            if (!$batch['rows']) {
                break;
            }
            foreach ($batch['rows'] as $row) {
                if ($written >= $max) {
                    break;
                }
                fputcsv($handle, [
                    (int) $row['id'], (string) $row['created_at'], (string) $row['level'],
                    (string) $row['action'], (string) $row['object_type'], (string) $row['object_id'],
                    (int) $row['user_id'], (string) $row['ip'], (string) $row['detail'],
                ]);
                $written++;
            }
            if ($page >= $batch['pages']) {
                break;
            }
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /** Distinct action names, for populating the filter dropdown. */
    public static function actions(int $limit = 100): array
    {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare(
            'SELECT DISTINCT action FROM ' . self::table() . ' ORDER BY action ASC LIMIT %d',
            $limit
        )) ?: [];
    }

    /**
     * Retention: diagnostics age out, the `audit` channel does not. Batched so
     * a year of backlog cannot lock the table in one statement.
     *
     * @return int rows deleted
     */
    public static function prune(int $days = 90, int $batch = 500): int
    {
        global $wpdb;
        $days   = max(7, $days);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $in     = implode(',', array_fill(0, count(self::PRUNABLE_LEVELS), '%s'));

        $deleted = 0;
        for ($i = 0; $i < 20; $i++) { // bounded: 10k rows a run, the rest next run
            $params  = array_merge(self::PRUNABLE_LEVELS, [$cutoff, $batch]);
            $removed = (int) $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . self::table() . " WHERE level IN ($in) AND created_at < %s LIMIT %d",
                ...$params
            ));
            $deleted += $removed;
            if ($removed < $batch) {
                break;
            }
        }
        return $deleted;
    }

    /**
     * @param array $args
     * @return array{0:string[],1:array} [where fragments, prepare params]
     */
    private static function filters(array $args): array
    {
        $where  = [];
        $params = [];

        if (!empty($args['level'])) {
            $where[]  = 'level = %s';
            $params[] = (string) $args['level'];
        }
        if (!empty($args['action'])) {
            $where[]  = 'action = %s';
            $params[] = (string) $args['action'];
        }
        if (!empty($args['object_type'])) {
            $where[]  = 'object_type = %s';
            $params[] = (string) $args['object_type'];
        }
        if (isset($args['object_id']) && $args['object_id'] !== '') {
            $where[]  = 'object_id = %s';
            $params[] = (string) $args['object_id'];
        }
        if (!empty($args['user_id'])) {
            $where[]  = 'user_id = %d';
            $params[] = (int) $args['user_id'];
        }
        if (!empty($args['from'])) {
            $where[]  = 'created_at >= %s';
            $params[] = self::boundary((string) $args['from'], '00:00:00');
        }
        if (!empty($args['to'])) {
            $where[]  = 'created_at <= %s';
            $params[] = self::boundary((string) $args['to'], '23:59:59');
        }
        return [$where, $params];
    }

    /** A bare 'Y-m-d' from a date input becomes a full-day boundary. */
    private static function boundary(string $value, string $time): string
    {
        $value = trim($value);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value . ' ' . $time : $value;
    }
}
