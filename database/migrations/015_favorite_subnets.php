<?php

declare(strict_types=1);

/**
 * Избранные подсети (CIDR): при попадании IP — полная остановка очереди + Telegram.
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS favorite_subnets (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                cidr VARCHAR(64) NOT NULL,
                note VARCHAR(255) NULL,
                created_at DATETIME NOT NULL,
                UNIQUE KEY uq_favorite_subnets_cidr (cidr)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    },
];
