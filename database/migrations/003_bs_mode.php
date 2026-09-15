<?php

declare(strict_types=1);

/**
 * BS check mode: agent | bsbord | both
 *
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'bs_mode'")->fetch();
        if (!$cols) {
            $pdo->exec(
                "ALTER TABLE runs ADD COLUMN bs_mode VARCHAR(16) NOT NULL DEFAULT 'agent'
                 COMMENT 'agent|bsbord|both' AFTER keep_on_fail"
            );
        }

        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'bs_source'")->fetch();
        if (!$cols) {
            $pdo->exec(
                "ALTER TABLE runs ADD COLUMN bs_source VARCHAR(32) NULL
                 COMMENT 'agent|bsbord' AFTER bs_ok"
            );
        }
    },
];
