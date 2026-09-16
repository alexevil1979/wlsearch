<?php

declare(strict_types=1);

/**
 * @return array{up: callable}
 */
return [
    'up' => static function (PDO $pdo): void {
        $cols = $pdo->query("SHOW COLUMNS FROM runs LIKE 'tested_at'")->fetch();
        if (!$cols) {
            $pdo->exec('ALTER TABLE runs ADD COLUMN tested_at DATETIME NULL AFTER updated_at');
        }
        // best-effort backfill for finished checks
        $pdo->exec(
            "UPDATE runs SET tested_at = updated_at
             WHERE tested_at IS NULL
               AND (bs_ok IS NOT NULL OR control_ok IS NOT NULL)
               AND state IN ('PASS','FAIL_BS','FAIL_CONTROL','KEEP','DESTROYED','ERROR')"
        );
    },
];
