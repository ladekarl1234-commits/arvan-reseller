<?php
namespace ArvanReseller\Install;

use ArvanReseller\Audit\Audit;

defined('ABSPATH') || exit;

/**
 * Versioned, idempotent schema migrations for the plugin's custom tables.
 * dbDelta() is diff-based, so re-running is safe; the version option only
 * short-circuits the common path. (ADR-0003: custom tables over meta.)
 *
 * The whole replay-safety model rests on four UNIQUE keys that dbDelta will
 * silently decline to create if the table already holds duplicates — so
 * `verify()` proves they exist and `migrate()` refuses to stamp the version
 * when they do not.
 */
final class Schema
{
    /** Unique keys the idempotency model depends on: table (unprefixed) => key name. */
    private const REQUIRED_UNIQUE = [
        'orders'        => 'payment_ref',
        'services'      => 'order_id',
        'ledger'        => 'uniq_ref',
        'usage_records' => 'uniq_period',
        'base_costs'    => 'product_plan',
        'topups'        => 'ref',
    ];

    /** Batch size for every migration/prune loop — keeps locks short. */
    private const BATCH = 500;

    public static function maybe_migrate(): void
    {
        if ((int) get_option('arvrs_schema_version', 0) >= ARVRS_SCHEMA_VERSION) {
            return;
        }
        // A migration that cannot verify its unique keys leaves the version
        // un-stamped on purpose, so this path retries. Throttle it: dbDelta on
        // every page load would be worse than the schema fault it is chasing.
        if (get_transient('arvrs_schema_retry')) {
            return;
        }
        set_transient('arvrs_schema_retry', 1, HOUR_IN_SECONDS);
        self::migrate();
    }

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $from_version = (int) get_option('arvrs_schema_version', 0);
        $p       = $wpdb->prefix . 'arvrs_';
        $charset = $wpdb->get_charset_collate();

        $tables = [
            "CREATE TABLE {$p}credentials (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                name varchar(100) NOT NULL,
                token_enc text NOT NULL,
                token_last4 varchar(8) NOT NULL DEFAULT '',
                enabled tinyint(1) NOT NULL DEFAULT 1,
                products varchar(255) NOT NULL DEFAULT '',
                priority int(11) NOT NULL DEFAULT 10,
                is_default tinyint(1) NOT NULL DEFAULT 0,
                notes text NULL,
                last_ok_at datetime NULL,
                last_error text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY enabled (enabled)
            ) $charset;",

            "CREATE TABLE {$p}orders (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL,
                product varchar(32) NOT NULL,
                plan_id varchar(64) NOT NULL,
                config longtext NULL,
                status varchar(32) NOT NULL DEFAULT 'draft',
                pricing longtext NULL,
                amount bigint(20) NOT NULL DEFAULT 0,
                base_cost bigint(20) NOT NULL DEFAULT 0,
                margin bigint(20) NOT NULL DEFAULT 0,
                currency char(3) NOT NULL DEFAULT 'IRT',
                payment_ref varchar(64) NOT NULL,
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY payment_ref (payment_ref),
                KEY customer_id (customer_id),
                KEY status (status),
                KEY customer_status (customer_id,status,id),
                KEY created_at (created_at)
            ) $charset;",

            "CREATE TABLE {$p}order_events (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint(20) unsigned NOT NULL,
                from_status varchar(32) NOT NULL,
                to_status varchar(32) NOT NULL,
                actor varchar(64) NOT NULL DEFAULT 'system',
                note text NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY order_id (order_id)
            ) $charset;",

            "CREATE TABLE {$p}services (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                order_id bigint(20) unsigned NOT NULL,
                customer_id bigint(20) unsigned NOT NULL,
                credential_id bigint(20) unsigned NULL,
                product varchar(32) NOT NULL,
                plan_id varchar(64) NOT NULL,
                remote_id varchar(128) NOT NULL DEFAULT '',
                status varchar(32) NOT NULL DEFAULT 'active',
                config longtext NULL,
                connection longtext NULL,
                renews_at datetime NULL,
                term_days int(11) NOT NULL DEFAULT 30,
                renewal_price bigint(20) NOT NULL DEFAULT 0,
                renewal_count int(11) NOT NULL DEFAULT 0,
                last_synced_at datetime NULL,
                cancelled_at datetime NULL,
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY customer_id (customer_id),
                KEY status (status),
                KEY renew_due (status,renews_at),
                KEY remote_id (remote_id)
            ) $charset;",

            "CREATE TABLE {$p}ledger (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL,
                type varchar(32) NOT NULL,
                direction varchar(6) NOT NULL,
                amount bigint(20) NOT NULL,
                currency char(3) NOT NULL DEFAULT 'IRT',
                ref_type varchar(32) NOT NULL DEFAULT '',
                ref_id varchar(64) NOT NULL DEFAULT '',
                description text NULL,
                actor varchar(64) NOT NULL DEFAULT 'system',
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_ref (ref_type,ref_id,type),
                KEY customer_id_id (customer_id,id)
            ) $charset;",
            // No standalone KEY created_at here: every ledger read is scoped
            // to a customer and ordered by id, so that tree was maintained on
            // every insert for no reader. Existing installs keep theirs —
            // dropping an index on a year-old ledger is a lock not worth the
            // page it would save.

            "CREATE TABLE {$p}usage_records (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                service_id bigint(20) unsigned NOT NULL,
                customer_id bigint(20) unsigned NOT NULL,
                period_start datetime NOT NULL,
                period_end datetime NOT NULL,
                quantity decimal(20,4) NOT NULL DEFAULT 0,
                unit varchar(16) NOT NULL DEFAULT '',
                cost bigint(20) NOT NULL DEFAULT 0,
                price bigint(20) NOT NULL DEFAULT 0,
                currency char(3) NOT NULL DEFAULT 'IRT',
                source varchar(24) NOT NULL DEFAULT 'provider',
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                raw longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_period (service_id,period_start,period_end),
                KEY customer_recent (customer_id,id),
                KEY customer_period (customer_id,period_start)
            ) $charset;",

            "CREATE TABLE {$p}topups (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ref varchar(64) NOT NULL,
                customer_id bigint(20) unsigned NOT NULL,
                amount bigint(20) NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY ref (ref),
                KEY expires (status,expires_at)
            ) $charset;",

            "CREATE TABLE {$p}jobs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                type varchar(48) NOT NULL,
                payload longtext NULL,
                status varchar(16) NOT NULL DEFAULT 'pending',
                attempts int(11) NOT NULL DEFAULT 0,
                max_attempts int(11) NOT NULL DEFAULT 5,
                run_at datetime NOT NULL,
                claimed_at datetime NULL,
                last_error text NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY status_runat (status,run_at),
                KEY claimed (status,claimed_at)
            ) $charset;",

            // level_id serves Audit::recent()'s `WHERE level = ? ORDER BY id
            // DESC` (level_created cannot: the sort column is id). audit_log
            // is the fastest-growing table, so that read must not be a scan.
            "CREATE TABLE {$p}audit_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL DEFAULT 0,
                action varchar(64) NOT NULL,
                object_type varchar(32) NOT NULL DEFAULT '',
                object_id varchar(64) NOT NULL DEFAULT '',
                detail longtext NULL,
                ip varchar(45) NOT NULL DEFAULT '',
                level varchar(10) NOT NULL DEFAULT 'info',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY created_at (created_at),
                KEY level_created (level,created_at),
                KEY level_id (level,id),
                KEY object (object_type,object_id),
                KEY user_id (user_id),
                KEY action (action)
            ) $charset;",

            "CREATE TABLE {$p}notifications (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
                type varchar(48) NOT NULL,
                title varchar(191) NOT NULL,
                body text NULL,
                is_read tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY customer_read (customer_id,is_read),
                KEY read_created (is_read,created_at),
                KEY type_created (type,created_at)
            ) $charset;",

            "CREATE TABLE {$p}customer_rules (
                customer_id bigint(20) unsigned NOT NULL,
                markup_percent decimal(7,3) NULL,
                discount_percent decimal(7,3) NULL,
                fixed_adjustment bigint(20) NULL,
                credit_limit bigint(20) NULL,
                allowed_products varchar(255) NULL,
                spending_limit bigint(20) NULL,
                status varchar(16) NOT NULL DEFAULT 'active',
                grace_days int(11) NULL,
                notes text NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (customer_id)
            ) $charset;",

            "CREATE TABLE {$p}base_costs (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                product varchar(32) NOT NULL,
                plan_id varchar(64) NOT NULL,
                base_cost bigint(20) NOT NULL,
                currency char(3) NOT NULL DEFAULT 'IRT',
                source varchar(191) NOT NULL DEFAULT '',
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY product_plan (product,plan_id)
            ) $charset;",
        ];

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        if ($from_version > 0 && $from_version < 4) {
            self::migrate_to_4($p);
        }
        if ($from_version > 0 && $from_version < 5) {
            self::migrate_to_5($p);
        }

        // dbDelta cannot add a UNIQUE key to a table that already holds
        // duplicates: it fails and moves on. Stamping the version anyway would
        // mean maybe_migrate() never retries, and INSERT IGNORE would quietly
        // degrade to plain INSERT — duplicate credits, double-debited usage.
        // So the version only advances once the keys are provably there.
        $check = self::verify();
        if (!$check['ok']) {
            Audit::log(0, 'schema.verify_failed', 'schema', (string) ARVRS_SCHEMA_VERSION,
                ['missing' => $check['missing'], 'from' => $from_version], 'error');
            return;
        }

        delete_transient('arvrs_schema_retry');
        update_option('arvrs_schema_version', ARVRS_SCHEMA_VERSION);
    }

    /**
     * v3→v4 added ledger.is_demo (DEFAULT 0). New rows are stamped at write
     * time, but historical rows all default to 0. If the site is still in demo
     * mode when it crosses into v4, its entire ledger history is demo —
     * back-stamp it so demo money never counts as real once the reseller goes
     * live. A site already live keeps 0 (correct).
     */
    private static function migrate_to_4(string $p): void
    {
        global $wpdb;
        $settings = get_option('arvrs_settings', []);
        $in_demo  = !is_array($settings) || !array_key_exists('demo_mode', $settings) || !empty($settings['demo_mode']);
        if (!$in_demo) {
            return;
        }
        // Bounded: an unbounded UPDATE over a year-old ledger is a lock the
        // request will not survive, and the version would be stamped anyway.
        $total = 0;
        for ($i = 0; $i < 2000; $i++) {
            $rows = (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . $p . 'ledger SET is_demo = 1 WHERE is_demo = 0 LIMIT %d', self::BATCH
            ));
            $total += $rows;
            if ($rows < self::BATCH) {
                break;
            }
        }
        Audit::log(0, 'schema.backfill', 'ledger', 'is_demo', ['rows' => $total, 'to_version' => 4]);
    }

    /** v4→v5: renewal storage, usage cost/price split, top-up intents into a table. */
    private static function migrate_to_5(string $p): void
    {
        global $wpdb;
        $stats = ['services' => 0, 'usage' => 0, 'topups' => 0];

        // Renewal clock for services that predate it. DATE_ADD is not portable
        // (the test harness runs SQLite), so the date is computed in PHP and
        // written back per batch.
        for ($i = 0; $i < 200; $i++) {
            $rows = $wpdb->get_results($wpdb->prepare(
                'SELECT s.id, s.created_at, o.amount FROM ' . $p . 'services s
                 LEFT JOIN ' . $p . 'orders o ON o.id = s.order_id
                 WHERE s.renews_at IS NULL ORDER BY s.id ASC LIMIT %d', self::BATCH
            ), ARRAY_A);
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                // The clock starts from NOW, not from `created_at`: an existing
                // service has already been paid for the term it is currently
                // running, so its next charge is one term from the upgrade —
                // backdating it to creation time billed one retroactive term
                // per cron tick until the clock caught up (release blocker).
                $wpdb->query($wpdb->prepare(
                    'UPDATE ' . $p . 'services SET renews_at = %s, term_days = 30, renewal_price = %d WHERE id = %d',
                    gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS),
                    max(0, (int) $row['amount']),
                    (int) $row['id']
                ));
                $stats['services']++;
            }
            if (count($rows) < self::BATCH) {
                break;
            }
        }

        // Before the cost/price split every usage row was billed at cost.
        for ($i = 0; $i < 2000; $i++) {
            $rows = (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . $p . 'usage_records SET price = cost WHERE price = 0 AND cost <> 0 LIMIT %d', self::BATCH
            ));
            $stats['usage'] += $rows;
            if ($rows < self::BATCH) {
                break;
            }
        }

        $stats['topups'] = self::migrate_topup_options($p);

        // Routing is a service-level fact; orders.credential_id was declared
        // but written by nothing. dbDelta cannot drop columns, so do it here.
        if (self::has_column($p . 'orders', 'credential_id')) {
            $wpdb->query('ALTER TABLE ' . $p . 'orders DROP COLUMN credential_id'); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        }

        Audit::log(0, 'schema.backfill', 'schema', '5', $stats);
    }

    /**
     * Top-up intents used to be one autoloaded-off wp_options row per call,
     * with no expiry and no sweep. Move the survivors into {p}topups so they
     * can expire like the rest of the payment state.
     */
    private static function migrate_topup_options(string $p): int
    {
        global $wpdb;
        $moved = 0;
        $rows  = $wpdb->get_results(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'arvrs\\_topup\\_%' LIMIT 5000",
            ARRAY_A
        ) ?: [];
        foreach ($rows as $row) {
            $intent = maybe_unserialize($row['option_value']);
            $ref    = substr((string) $row['option_name'], strlen('arvrs_topup_'));
            if (is_array($intent) && $ref !== '' && !empty($intent['customer_id'])) {
                $at = isset($intent['at']) ? (int) $intent['at'] : time();
                $wpdb->query($wpdb->prepare(
                    'INSERT IGNORE INTO ' . $p . 'topups (ref, customer_id, amount, status, created_at, expires_at)
                     VALUES (%s, %d, %d, %s, %s, %s)',
                    $ref, (int) $intent['customer_id'], (int) ($intent['amount'] ?? 0), 'pending',
                    gmdate('Y-m-d H:i:s', $at), gmdate('Y-m-d H:i:s', $at + 2 * HOUR_IN_SECONDS)
                ));
                $moved++;
            }
            delete_option($row['option_name']);
        }
        return $moved;
    }

    /**
     * Prove the tables and the UNIQUE keys the idempotency model rests on are
     * actually present. Surfaced on the admin health page.
     *
     * @return array{ok:bool,missing:string[],tables:string[],note:string}
     */
    public static function verify(): array
    {
        global $wpdb;
        $p          = $wpdb->prefix . 'arvrs_';
        $missing    = [];
        $present    = [];
        $unreadable = [];

        $suppress = $wpdb->suppress_errors(true);

        foreach (self::REQUIRED_UNIQUE as $table => $key) {
            // SHOW INDEX doubles as the existence check: a missing table
            // errors, and a DB layer that cannot introspect returns nothing.
            $indexes = $wpdb->get_results('SHOW INDEX FROM ' . $p . $table, ARRAY_A);
            if (!is_array($indexes) || !$indexes) {
                $unreadable[] = $table;
                continue;
            }
            $present[] = $table;
            $found = false;
            foreach ($indexes as $index) {
                if (isset($index['Key_name']) && $index['Key_name'] === $key && (int) ($index['Non_unique'] ?? 1) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $table . '.' . $key;
            }
        }
        $wpdb->suppress_errors($suppress);

        // Nothing readable anywhere = the DB layer has no SHOW INDEX (the
        // SQLite harness). That is not evidence of a broken schema, so degrade
        // to "ok, but unverified" rather than blocking the migration.
        if (!$present) {
            return [
                'ok'      => true,
                'missing' => [],
                'tables'  => [],
                'note'    => __('بازبینی ایندکس‌ها روی این پایگاه داده در دسترس نیست.', 'arvan-reseller'),
            ];
        }

        // Some tables read and some did not: the ones that did not are absent.
        $missing = array_merge($missing, $unreadable);

        return [
            'ok'      => !$missing,
            'missing' => $missing,
            'tables'  => $present,
            'note'    => '',
        ];
    }

    /**
     * Retention. The ledger is append-only forever by design — it is the
     * financial record. Everything here is operational exhaust: audit noise
     * and read notifications. Deleted in bounded batches so a big table
     * cannot stall the cron request.
     *
     * @return array{audit:int,notifications:int,usage_raw:int}
     */
    public static function prune(int $days): array
    {
        global $wpdb;
        $days   = max(7, $days); // never let a misconfiguration erase this week
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS);
        $p      = $wpdb->prefix . 'arvrs_';
        $counts = ['audit' => 0, 'notifications' => 0, 'usage_raw' => 0];

        // level='audit' rows are the compliance trail and are never pruned;
        // 'info'/'debug' are diagnostics with a shelf life.
        for ($i = 0; $i < 200; $i++) {
            $rows = (int) $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $p . "audit_log WHERE level IN ('info','debug') AND created_at < %s LIMIT %d",
                $cutoff, self::BATCH
            ));
            $counts['audit'] += $rows;
            if ($rows < self::BATCH) {
                break;
            }
        }

        for ($i = 0; $i < 200; $i++) {
            $rows = (int) $wpdb->query($wpdb->prepare(
                'DELETE FROM ' . $p . 'notifications WHERE is_read = 1 AND created_at < %s LIMIT %d',
                $cutoff, self::BATCH
            ));
            $counts['notifications'] += $rows;
            if ($rows < self::BATCH) {
                break;
            }
        }

        // usage_records rows are the billing evidence and stay; `raw` is the
        // provider's verbatim payload — a longtext per service per period,
        // which is the actual disk cost. Once the period is closed and aged
        // out, the aggregate (quantity/cost/price) is the record. The row's
        // money columns are never touched.
        for ($i = 0; $i < 200; $i++) {
            $rows = (int) $wpdb->query($wpdb->prepare(
                'UPDATE ' . $p . 'usage_records SET raw = NULL
                 WHERE raw IS NOT NULL AND period_end < %s LIMIT %d',
                $cutoff, self::BATCH
            ));
            $counts['usage_raw'] += $rows;
            if ($rows < self::BATCH) {
                break;
            }
        }

        return $counts;
    }

    private static function has_column(string $table, string $column): bool
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column), ARRAY_A);
        return is_array($rows) && $rows !== [];
    }
}
