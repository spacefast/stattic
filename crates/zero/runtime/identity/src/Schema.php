<?php

declare(strict_types=1);

namespace Zero\Identity;

final class Schema
{
    public const VERSION = 3;
    public static function migrate(DB $db): void
    {
        $database = $db->row("SELECT DATABASE() AS name");
        $lock = hash("sha256", ($database["name"] ?? "") . ":" . $db->prefix);
        $acquired = $db->row("SELECT GET_LOCK(%s, 10) AS acquired", $lock);
        if ((int) ($acquired["acquired"] ?? 0) !== 1) {
            throw new Failure(
                "storage_unavailable",
                "Identity storage is being initialized. Please retry.",
                503,
            );
        }
        try {
            self::migrateLocked($db);
        } finally {
            $db->row("SELECT RELEASE_LOCK(%s) AS released", $lock);
        }
    }

    private static function migrateLocked(DB $db): void
    {
        require_once ABSPATH . "wp-admin/includes/upgrade.php";
        $tables = [
            "accounts" =>
                'wp_user_id bigint unsigned NOT NULL, primary_email_id bigint unsigned NULL, status varchar(20) NOT NULL DEFAULT \'active\', created_at bigint NOT NULL, PRIMARY KEY  (wp_user_id)',
            "emails" =>
                "id bigint unsigned NOT NULL AUTO_INCREMENT, wp_user_id bigint unsigned NOT NULL, normalized varbinary(254) NOT NULL, verified_at bigint NOT NULL, method varchar(32) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY normalized (normalized), KEY account (wp_user_id)",
            "providers" =>
                "id bigint unsigned NOT NULL AUTO_INCREMENT, wp_user_id bigint unsigned NOT NULL, identity_key binary(64) NOT NULL, issuer varchar(255) NOT NULL, subject varchar(512) NOT NULL, created_at bigint NOT NULL, PRIMARY KEY  (id), UNIQUE KEY identity_key (identity_key), KEY account (wp_user_id)",
            "sessions" =>
                "id char(32) NOT NULL, wp_user_id bigint unsigned NOT NULL, secret_hash char(64) NOT NULL, csrf char(64) NOT NULL, created_at bigint NOT NULL, expires_at bigint NOT NULL, verified_at bigint NOT NULL, revoked_at bigint NULL, label varchar(200) NOT NULL, PRIMARY KEY  (id), UNIQUE KEY secret_hash (secret_hash), KEY account (wp_user_id)",
            "challenges" =>
                'id char(32) NOT NULL, purpose varchar(32) NOT NULL, wp_user_id bigint unsigned NOT NULL DEFAULT 0, session_id char(32) NOT NULL DEFAULT \'\', secret_hash char(64) NOT NULL, payload longtext NOT NULL, attempts int NOT NULL DEFAULT 0, expires_at bigint NOT NULL, consumed_at bigint NULL, PRIMARY KEY  (id), KEY expiry (expires_at)',
            "applications" =>
                "id varchar(64) NOT NULL, name varchar(120) NOT NULL, redirects text NOT NULL, origins text NOT NULL, enabled tinyint NOT NULL DEFAULT 1, PRIMARY KEY  (id)",
            "codes" =>
                "id varchar(100) NOT NULL, wp_user_id bigint unsigned NOT NULL, session_id char(32) NOT NULL, client_id varchar(64) NOT NULL, redirect_uri text NOT NULL, code_challenge char(43) NOT NULL, scope varchar(100) NOT NULL, nonce varchar(255) NOT NULL, auth_time bigint NOT NULL, expires_at bigint NOT NULL, revoked tinyint NOT NULL DEFAULT 0, family char(32) NULL, PRIMARY KEY  (id)",
            "tokens" =>
                "id varchar(100) NOT NULL, kind enum('access','refresh') NOT NULL DEFAULT 'access', family char(32) NOT NULL DEFAULT '', wp_user_id bigint unsigned NOT NULL, session_id char(32) NOT NULL, client_id varchar(64) NOT NULL, scope varchar(100) NOT NULL DEFAULT '', expires_at bigint NOT NULL, revoked tinyint NOT NULL DEFAULT 0, replaced_by varchar(100) NULL, used_at datetime NULL, PRIMARY KEY  (id), KEY account (wp_user_id), KEY family (family)",
            "events" =>
                "id bigint unsigned NOT NULL AUTO_INCREMENT, wp_user_id bigint unsigned NOT NULL DEFAULT 0, type varchar(64) NOT NULL, detail text NOT NULL, created_at bigint NOT NULL, PRIMARY KEY  (id), KEY account (wp_user_id)",
            "limits" =>
                "id char(64) NOT NULL, hits int NOT NULL, expires_at bigint NOT NULL, PRIMARY KEY  (id)",
            "organizations" =>
                "id char(32) NOT NULL, name varchar(120) NOT NULL, created_at bigint NOT NULL, PRIMARY KEY  (id)",
            "members" =>
                "organization_id char(32) NOT NULL, wp_user_id bigint unsigned NOT NULL, role varchar(16) NOT NULL, PRIMARY KEY  (organization_id,wp_user_id)",
            "passkeys" =>
                "id char(64) NOT NULL, wp_user_id bigint unsigned NOT NULL, record longtext NOT NULL, name varchar(120) NOT NULL, created_at bigint NOT NULL, PRIMARY KEY  (id), KEY account (wp_user_id)",
            "mfa" =>
                "wp_user_id bigint unsigned NOT NULL, secret text NOT NULL, enabled tinyint NOT NULL DEFAULT 0, last_step bigint NOT NULL DEFAULT 0, PRIMARY KEY  (wp_user_id)",
            "recovery_codes" =>
                "id char(64) NOT NULL, wp_user_id bigint unsigned NOT NULL, used_at bigint NULL, PRIMARY KEY  (id), KEY account (wp_user_id)",
            "deliveries" =>
                "id bigint unsigned NOT NULL AUTO_INCREMENT, event_id bigint unsigned NOT NULL, endpoint_id varchar(64) NOT NULL, attempts int NOT NULL DEFAULT 0, next_at bigint NOT NULL, delivered_at bigint NULL, last_status int NOT NULL DEFAULT 0, PRIMARY KEY  (id), UNIQUE KEY delivery (event_id,endpoint_id)",
        ];
        foreach ($tables as $name => $columns) {
            $columns = str_replace(", ", ",\n ", $columns);
            dbDelta(
                "CREATE TABLE {$db->prefix}{$name} (\n $columns\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
            );
        }
        $db->run(
            "INSERT INTO {$db->wp->options} (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)",
            "zero_identity_schema_version",
            (string) self::VERSION,
            "off",
        );
        wp_cache_delete("zero_identity_schema_version", "options");
        wp_cache_delete("alloptions", "options");
        wp_cache_delete("notoptions", "options");
    }
}
