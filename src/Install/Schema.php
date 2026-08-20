<?php
namespace ArvanReseller\Install;

defined('ABSPATH') || exit;

/**
 * Versioned, idempotent schema migrations for the plugin's custom tables.
 * dbDelta() is diff-based, so re-running is safe; the version option only
 * short-circuits the common path. (ADR-0003: custom tables over meta.)
 */
final class Schema
{
    public static function maybe_migrate(): void
    {
        if ((int) get_option('arvrs_schema_version', 0) < ARVRS_SCHEMA_VERSION) {
            self::migrate();
        }
    }

    public static function migrate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

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
                currency char(3) NOT NULL DEFAULT 'IRT',
                payment_ref varchar(64) NOT NULL,
                credential_id bigint(20) unsigned NULL,
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY payment_ref (payment_ref),
                KEY customer_id (customer_id),
                KEY status (status),
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
                is_demo tinyint(1) NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY order_id (order_id),
                KEY customer_id (customer_id),
                KEY status (status),
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
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_ref (ref_type,ref_id,type),
                KEY customer_id (customer_id),
                KEY created_at (created_at)
            ) $charset;",

            "CREATE TABLE {$p}usage_records (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                service_id bigint(20) unsigned NOT NULL,
                customer_id bigint(20) unsigned NOT NULL,
                period_start datetime NOT NULL,
                period_end datetime NOT NULL,
                quantity decimal(20,4) NOT NULL DEFAULT 0,
                unit varchar(16) NOT NULL DEFAULT '',
                cost bigint(20) NOT NULL DEFAULT 0,
                raw longtext NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY uniq_period (service_id,period_start,period_end),
                KEY customer_id (customer_id)
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
                KEY status_runat (status,run_at)
            ) $charset;",

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

        update_option('arvrs_schema_version', ARVRS_SCHEMA_VERSION);
    }
}
