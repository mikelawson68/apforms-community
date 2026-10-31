<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_DB {

    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Forms table
        dbDelta("CREATE TABLE {$wpdb->prefix}apf_forms (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            title       varchar(255) NOT NULL,
            description text,
            fields_json longtext     NULL,
            settings    longtext     NULL,
            status      varchar(20)  NOT NULL DEFAULT 'active',
            created_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by  bigint(20),
            PRIMARY KEY (id),
            KEY status (status)
        ) $charset;");

        // Entries table
        dbDelta("CREATE TABLE {$wpdb->prefix}apf_entries (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            form_id     bigint(20)   NOT NULL,
            status      varchar(20)  NOT NULL DEFAULT 'active',
            ip          varchar(45),
            user_agent  text,
            source_url  varchar(500),
            created_by  bigint(20),
            created_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset;");

        // Entry values table
        dbDelta("CREATE TABLE {$wpdb->prefix}apf_entry_meta (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            entry_id    bigint(20)   NOT NULL,
            form_id     bigint(20)   NOT NULL,
            field_id    varchar(50)  NOT NULL,
            field_label varchar(255),
            value       longtext,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY form_id (form_id),
            KEY field_id (field_id)
        ) $charset;");

        // Notifications table
        dbDelta("CREATE TABLE {$wpdb->prefix}apf_notifications (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            form_id     bigint(20)   NOT NULL,
            name        varchar(255) NOT NULL,
            to_type     varchar(20)  NOT NULL DEFAULT 'admin',
            to_email    varchar(500),
            to_field    varchar(50),
            from_name   varchar(255),
            from_email  varchar(255),
            reply_to    varchar(255),
            subject     varchar(500) NOT NULL,
            body        longtext     NOT NULL,
            is_active   tinyint(1)   NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            KEY form_id (form_id)
        ) $charset;");

        update_option( 'apforms_db_version', APFORMS_VERSION );
    }

    public static function get_prefix() {
        global $wpdb;
        return $wpdb->prefix;
    }
}
