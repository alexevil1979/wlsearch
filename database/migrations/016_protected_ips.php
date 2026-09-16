<?php

declare(strict_types=1);

/**
 * Protected public IPs — never release/delete via provider destroy (Yandex static reserve).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS protected_ips (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                ipv4 VARCHAR(45) NOT NULL,
                source VARCHAR(64) NOT NULL DEFAULT 'manual',
                run_id BIGINT UNSIGNED NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_protected_ips_ipv4 (ipv4),
                KEY idx_protected_ips_run (run_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        // Уже пойманные KEEP — защитить IP (в т.ч. 84.201.131.208 после избранной подсети)
        $pdo->exec(
            "INSERT IGNORE INTO protected_ips (ipv4, source, run_id, note, created_at)
             SELECT r.ipv4,
                    CASE
                        WHEN r.error_message LIKE 'избранная подсеть%' THEN 'favorite_subnet'
                        ELSE 'keep'
                    END,
                    r.id,
                    LEFT(COALESCE(r.error_message, 'KEEP'), 255),
                    NOW()
             FROM runs r
             WHERE r.state = 'KEEP'
               AND r.ipv4 IS NOT NULL AND r.ipv4 != ''"
        );
    },
];
