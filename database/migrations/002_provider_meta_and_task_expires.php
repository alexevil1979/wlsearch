<?php

declare(strict_types=1);

/**
 * Extra columns for provider metadata (Selectel floating IP etc).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'provider_meta'")->fetch();
        if (!$cols) {
            $pdo->exec('ALTER TABLE runs ADD COLUMN provider_meta TEXT NULL AFTER provider_server_id');
        }

        $cols = $pdo->query("SHOW COLUMNS FROM tasks LIKE 'expires_at'")->fetch();
        if (!$cols) {
            $pdo->exec('ALTER TABLE tasks ADD COLUMN expires_at DATETIME NULL AFTER updated_at');
        }
    },
];
