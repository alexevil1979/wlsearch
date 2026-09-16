<?php

declare(strict_types=1);

/**
 * Когда реально создан VPS у провайдера (не постановка run в очередь).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'server_created_at'")->fetch();
        if (!$cols) {
            $pdo->exec('ALTER TABLE runs ADD COLUMN server_created_at DATETIME NULL AFTER created_at');
        }
        // backfill: если сервер уже был — берём updated_at первого появления server id (грубо)
        $pdo->exec(
            "UPDATE runs SET server_created_at = COALESCE(server_created_at, updated_at)
             WHERE provider_server_id IS NOT NULL AND provider_server_id != ''
               AND server_created_at IS NULL"
        );
    },
];
