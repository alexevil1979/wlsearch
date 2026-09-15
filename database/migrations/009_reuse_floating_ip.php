<?php

declare(strict_types=1);

/**
 * Reuse floating IPs by default — Timeweb daily create limit (~10).
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY', '0']);
    },
];
