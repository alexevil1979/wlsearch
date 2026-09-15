<?php

declare(strict_types=1);

/**
 * Cache of already probed IPv4 — skip re-check / destroy known-bad immediately.
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS checked_ips (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ipv4 VARCHAR(45) NOT NULL,
                verdict VARCHAR(32) NOT NULL COMMENT 'pass|fail_bs|fail_control|fail_seen|error',
                provider VARCHAR(32) NULL,
                asn INT NULL,
                run_id BIGINT UNSIGNED NULL,
                detail VARCHAR(512) NULL,
                checked_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                UNIQUE KEY uq_checked_ips_ipv4 (ipv4),
                KEY idx_checked_ips_verdict (verdict),
                KEY idx_checked_ips_checked (checked_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Backfill from past runs
        $pdo->exec(
            "INSERT IGNORE INTO checked_ips (ipv4, verdict, provider, asn, run_id, detail, checked_at, updated_at)
             SELECT r.ipv4,
                    CASE
                        WHEN r.verdict = 'PASS' OR r.state = 'PASS' THEN 'pass'
                        WHEN r.verdict = 'FAIL_BS' OR r.state = 'FAIL_BS' THEN 'fail_bs'
                        WHEN r.verdict = 'FAIL_CONTROL' OR r.state = 'FAIL_CONTROL' THEN 'fail_control'
                        ELSE 'error'
                    END,
                    r.provider,
                    r.asn,
                    r.id,
                    LEFT(COALESCE(r.error_message, r.verdict, r.state), 512),
                    COALESCE(r.updated_at, r.created_at),
                    NOW()
             FROM runs r
             WHERE r.ipv4 IS NOT NULL AND r.ipv4 != ''
               AND (
                    r.state IN ('PASS','FAIL_BS','FAIL_CONTROL','ERROR','KEEP','DESTROYED')
                    OR r.verdict IN ('PASS','FAIL_BS','FAIL_CONTROL','ERROR')
               )"
        );

        $pdo->exec(
            "INSERT INTO checked_ips (ipv4, verdict, provider, asn, run_id, detail, checked_at, updated_at)
             SELECT i.ipv4, 'pass', i.provider, i.asn, i.run_id, LEFT(COALESCE(i.notes, 'inventory'), 512), i.found_at, NOW()
             FROM inventory i
             WHERE i.status = 'active'
             ON DUPLICATE KEY UPDATE
                verdict = IF(checked_ips.verdict = 'pass', checked_ips.verdict, 'pass'),
                updated_at = NOW()"
        );
    },
];
