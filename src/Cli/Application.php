<?php

declare(strict_types=1);

namespace Wlsearch\Cli;

use Wlsearch\Database\Migrator;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;

final class Application
{
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $args = array_slice($argv, 2);

        try {
            return match ($command) {
                'migrate' => $this->migrate(),
                'worker' => $this->worker(),
                'run' => $this->runCreate($args),
                'destroy-failed' => $this->notReady('destroy-failed'),
                'inventory' => $this->inventory(),
                'agent-token:create' => $this->notReady('agent-token:create'),
                'health' => $this->health(),
                'help', '--help', '-h' => $this->help(),
                default => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function help(): int
    {
        $text = <<<TXT
wlsearch CLI (Phase 0)

Usage:
  php bin/wlsearch migrate
  php bin/wlsearch health
  php bin/wlsearch worker
  php bin/wlsearch run --provider=timeweb --region=... --count=1
  php bin/wlsearch destroy-failed
  php bin/wlsearch inventory
  php bin/wlsearch agent-token:create --name=phone-mts --operator=mts

TXT;
        fwrite(STDOUT, $text);
        return 0;
    }

    private function migrate(): int
    {
        return (new Migrator())->migrate();
    }

    private function health(): int
    {
        $pdo = Database::tryPdo();
        $db = $pdo ? 'up' : 'down';
        fwrite(STDOUT, "app=" . (Env::get('APP_NAME', 'wlsearch') ?? 'wlsearch') . " db={$db} phase=0\n");
        return $pdo ? 0 : 1;
    }

    private function worker(): int
    {
        fwrite(STDOUT, '[' . date('c') . "] worker tick: no-op (Phase 0 stub)\n");
        return 0;
    }

    /** @param list<string> $args */
    private function runCreate(array $args): int
    {
        fwrite(STDERR, "run: not implemented until Phase 1 (Timeweb adapter)\n");
        fwrite(STDERR, 'args: ' . implode(' ', $args) . "\n");
        return 1;
    }

    private function inventory(): int
    {
        $pdo = Database::tryPdo();
        if ($pdo === null) {
            fwrite(STDERR, "DB unavailable\n");
            return 1;
        }
        try {
            $rows = $pdo->query(
                "SELECT ipv4, provider, operators, status, found_at FROM inventory WHERE status = 'active' ORDER BY found_at DESC LIMIT 100"
            )->fetchAll();
        } catch (\Throwable $e) {
            fwrite(STDERR, 'inventory query failed (migrate first?): ' . $e->getMessage() . "\n");
            return 1;
        }

        if ($rows === []) {
            fwrite(STDOUT, "Inventory empty.\n");
            return 0;
        }
        foreach ($rows as $row) {
            fwrite(
                STDOUT,
                sprintf(
                    "%-15s  %-10s  %-20s  %s  %s\n",
                    $row['ipv4'],
                    $row['provider'],
                    (string) $row['operators'],
                    $row['status'],
                    $row['found_at']
                )
            );
        }
        return 0;
    }

    private function notReady(string $cmd): int
    {
        fwrite(STDERR, "{$cmd}: not implemented in Phase 0\n");
        return 1;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}\n");
        $this->help();
        return 1;
    }
}
