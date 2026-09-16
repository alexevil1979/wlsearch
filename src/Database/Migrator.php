<?php

declare(strict_types=1);

namespace Wlsearch\Database;

use PDO;
use Wlsearch\Support\Database;

/**
 * MySQL 5.7: DDL (CREATE/ALTER) implicitly commits, so wrapping migrations
 * in PDO transactions causes "There is no active transaction" on commit/rollBack.
 */
final class Migrator
{
    private string $migrationsPath;

    public function __construct(?string $migrationsPath = null)
    {
        $this->migrationsPath = $migrationsPath ?? dirname(__DIR__, 2) . '/database/migrations';
    }

    public function migrate(): int
    {
        return $this->apply(true);
    }

    /** Apply pending migrations without stdout (safe for web/worker). */
    public function migrateQuiet(): int
    {
        return $this->apply(false);
    }

    private function apply(bool $verbose): int
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

            try {
                $migration['up']($pdo);
                $stmt = $pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (?, NOW())'
                );
                $stmt->execute([$version]);
                if ($verbose) {
                    fwrite(STDOUT, "Migrated: {$version}\n");
                }
                $count++;
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "Migration {$version} failed: " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        if ($verbose) {
            if ($count === 0) {
                fwrite(STDOUT, "Nothing to migrate.\n");
            } else {
                fwrite(STDOUT, "Done. Applied {$count} migration(s).\n");
            }
        }

        return $count;
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
