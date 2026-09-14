<?php

declare(strict_types=1);

namespace Wlsearch\Database;

use PDO;
use Wlsearch\Support\Database;

final class Migrator
{
    private string $migrationsPath;

    public function __construct(?string $migrationsPath = null)
    {
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    public function migrate(): int
    {
        $pdo = Database::pdo();
        $this->ensureMigrationsTable($pdo);

        $applied = $this->appliedVersions($pdo);
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        $count = 0;
        foreach ($files as $file) {
            $version = basename($file, '.php');
            if (isset($applied[$version])) {
                continue;
            }

            /** @var array{up: callable} $migration */
            $migration = require $file;
            if (!isset($migration['up']) || !is_callable($migration['up'])) {
                throw new \RuntimeException("Invalid migration: {$file}");
            }

            $pdo->beginTransaction();
            try {
                $migration['up']($pdo);
                $stmt = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())');
                $stmt->execute([$version]);
                $pdo->commit();
                fwrite(STDOUT, "Migrated: {$version}\n");
                $count++;
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }

        if ($count === 0) {
            fwrite(STDOUT, "Nothing to migrate.\n");
        }

        return 0;
    }

    private function ensureMigrationsTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return array<string, true> */
    private function appliedVersions(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $map = [];
        foreach ($rows as $v) {
            $map[(string) $v] = true;
        }
        return $map;
    }
}
