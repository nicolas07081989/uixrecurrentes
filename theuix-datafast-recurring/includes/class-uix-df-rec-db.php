<?php

if (!defined('ABSPATH')) {
    exit;
}

class UIX_DF_Rec_DB
{
    public static function activate()
    {
        self::migrate();
        self::schedule_events();
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('uix_df_recurring_charge_runner');
    }

    public static function schedule_events()
    {
        if (!wp_next_scheduled('uix_df_recurring_charge_runner')) {
            wp_schedule_event(time() + 300, 'hourly', 'uix_df_recurring_charge_runner');
        }
    }

    public static function migrate()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $subs = $wpdb->prefix . 'uix_subscriptions';
        $attempts = $wpdb->prefix . 'uix_charge_attempts';

        $sqlSubs = "CREATE TABLE $subs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            customer_wp_user_id BIGINT UNSIGNED NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NOT NULL,
            cedula_ruc VARCHAR(20) NOT NULL,
            plan_slug VARCHAR(80) NOT NULL,
            plan_title VARCHAR(150) NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            registration_id VARCHAR(120) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            checkout_id VARCHAR(120) NULL,
            checkout_resource_path VARCHAR(255) NULL,
            payment_brand VARCHAR(50) NULL,
            last_transaction_id VARCHAR(120) NULL,
            last_result_code VARCHAR(20) NULL,
            last_result_description VARCHAR(255) NULL,
            retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_retries SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            next_charge_at DATETIME NULL,
            last_charge_at DATETIME NULL,
            suspended_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid),
            KEY status_next (status, next_charge_at),
            KEY email (email),
            KEY registration_id (registration_id)
        ) $charset;";

        $sqlAttempts = "CREATE TABLE $attempts (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscription_id BIGINT UNSIGNED NOT NULL,
            idempotency_key VARCHAR(80) NOT NULL,
            kind VARCHAR(20) NOT NULL,
            requested_amount DECIMAL(10,2) NOT NULL,
            currency CHAR(3) NOT NULL DEFAULT 'USD',
            request_payload_redacted LONGTEXT NULL,
            response_payload_redacted LONGTEXT NULL,
            http_status SMALLINT NULL,
            result_code VARCHAR(20) NULL,
            result_description VARCHAR(255) NULL,
            transaction_id VARCHAR(120) NULL,
            decision VARCHAR(20) NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idempotency_key (idempotency_key),
            KEY sub_created (subscription_id, created_at),
            KEY result_code (result_code)
        ) $charset;";

        dbDelta($sqlSubs);
        dbDelta($sqlAttempts);
    }
}
