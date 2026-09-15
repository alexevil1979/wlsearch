<?php

declare(strict_types=1);

/**
 * Sequential lottery: batch_id, stop_on_pass; parallel default 1.
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'batch_id'")->fetch();
        if (!$cols) {
            $pdo->exec(
                'ALTER TABLE runs
                 ADD COLUMN batch_id VARCHAR(32) NULL AFTER id,
                 ADD COLUMN stop_on_pass TINYINT(1) NOT NULL DEFAULT 0 AFTER keep_on_fail,
                 ADD KEY idx_runs_batch (batch_id)'
            );
        }

        $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
        )->execute(['MAX_PARALLEL_VMS', '1']);
    },
];
