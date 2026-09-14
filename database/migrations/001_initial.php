<?php

declare(strict_types=1);

/**
 * Initial schema for wlsearch (MySQL 5.7 compatible).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS admin_users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                login VARCHAR(64) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_admin_users_login (login)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS devices (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(128) NOT NULL,
                operator VARCHAR(32) NOT NULL COMMENT 'mts|beeline|megafon|other',
                token_hash VARCHAR(64) NOT NULL,
                token_prefix VARCHAR(12) NOT NULL,
                last_seen_at DATETIME NULL,
                meta_json TEXT NULL,
                revoked_at DATETIME NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_devices_token_hash (token_hash),
                KEY idx_devices_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS runs (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                provider VARCHAR(32) NOT NULL COMMENT 'timeweb|selectel',
                region VARCHAR(64) NULL,
                state VARCHAR(32) NOT NULL DEFAULT 'ORDERING',
                keep_on_fail TINYINT(1) NOT NULL DEFAULT 0,
                comment VARCHAR(255) NULL,
                provider_server_id VARCHAR(128) NULL,
                ipv4 VARCHAR(45) NULL,
                asn INT NULL,
                asn_org VARCHAR(128) NULL,
                control_ok TINYINT(1) NULL,
                bs_ok TINYINT(1) NULL,
                cellular_ok TINYINT(1) NULL,
                verdict VARCHAR(32) NULL COMMENT 'PASS|FAIL_BS|FAIL_CONTROL|ERROR',
                error_message TEXT NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                destroyed_at DATETIME NULL,
                KEY idx_runs_state (state),
                KEY idx_runs_created (created_at),
                KEY idx_runs_provider (provider)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS tasks (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                run_id BIGINT UNSIGNED NOT NULL,
                device_id INT UNSIGNED NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'pending' COMMENT 'pending|assigned|done|expired|error',
                target_ipv4 VARCHAR(45) NOT NULL,
                probe_marker VARCHAR(64) NOT NULL DEFAULT 'WL_PROBE_OK',
                assigned_at DATETIME NULL,
                finished_at DATETIME NULL,
                result_json TEXT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_tasks_status (status),
                KEY idx_tasks_run (run_id),
                CONSTRAINT fk_tasks_run FOREIGN KEY (run_id) REFERENCES runs(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS inventory (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                run_id BIGINT UNSIGNED NULL,
                ipv4 VARCHAR(45) NOT NULL,
                provider VARCHAR(32) NOT NULL,
                asn INT NULL,
                asn_org VARCHAR(128) NULL,
                operators VARCHAR(128) NULL COMMENT 'comma-separated: mts,beeline,...',
                notes TEXT NULL,
                status VARCHAR(32) NOT NULL DEFAULT 'active' COMMENT 'active|retired',
                found_at DATETIME NOT NULL,
                retired_at DATETIME NULL,
                UNIQUE KEY uq_inventory_ipv4 (ipv4),
                KEY idx_inventory_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS prefix_blacklist (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                kind VARCHAR(16) NOT NULL COMMENT 'asn|prefix',
                value VARCHAR(64) NOT NULL,
                reason VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_blacklist_kind_value (kind, value)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                actor VARCHAR(64) NOT NULL,
                action VARCHAR(64) NOT NULL,
                entity_type VARCHAR(64) NULL,
                entity_id VARCHAR(64) NULL,
                details_json TEXT NULL,
                ip VARCHAR(45) NULL,
                created_at DATETIME NOT NULL,
                KEY idx_audit_created (created_at),
                KEY idx_audit_action (action)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value TEXT NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $defaults = [
            'MAX_PARALLEL_VMS' => '3',
            'MAX_CREATES_PER_DAY' => '20',
            'MAX_DAILY_SPEND_RUB' => '500',
            'TELEGRAM_CHAT_ID' => '',
        ];
        $stmt = $pdo->prepare(
            'INSERT IGNORE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())'
        );
        foreach ($defaults as $k => $v) {
            $stmt->execute([$k, $v]);
        }
    },
];
