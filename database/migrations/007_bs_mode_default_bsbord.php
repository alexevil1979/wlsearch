<?php

declare(strict_types=1);

/**
 * Default BS check mode = bsbord.
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (\'BS_MODE_DEFAULT\', \'bsbord\', NOW())
             ON DUPLICATE KEY UPDATE setting_value = \'bsbord\', updated_at = NOW()'
        )->execute();
    },
];
