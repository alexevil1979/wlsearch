<?php

declare(strict_types=1);

namespace Wlsearch\Cli;

use Wlsearch\Database\Migrator;
use Wlsearch\Device\DeviceService;
use Wlsearch\Run\RunService;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;
use Wlsearch\Worker\Worker;

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
                'destroy-failed' => $this->destroyFailed(),
                'inventory' => $this->inventory(),
                'agent-token:create' => $this->agentTokenCreate($args),
                'timeweb:finances' => $this->timewebFinances(),
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
wlsearch CLI (Phase 2–4)

Usage:
  php bin/wlsearch migrate
  php bin/wlsearch health
  php bin/wlsearch worker
  php bin/wlsearch run --provider=timeweb|selectel|yandex --region=... --count=1 [--bs-mode=agent|bsbord|both] [--keep-on-fail]
  php bin/wlsearch destroy-failed
  php bin/wlsearch inventory
  php bin/wlsearch agent-token:create --name=phone-mts --operator=mts
  php bin/wlsearch timeweb:finances

TXT;
        fwrite(STDOUT, $text);
        return 0;
    }

    private function migrate(): int
    {
        (new Migrator())->migrate();
        return 0;
    }

    private function health(): int
    {
        $pdo = Database::tryPdo();
        $db = $pdo ? 'up' : 'down';
        fwrite(STDOUT, "app=" . (Env::get('APP_NAME', 'wlsearch') ?? 'wlsearch') . " db={$db} phase=4\n");
        return $pdo ? 0 : 1;
    }

    private function worker(): int
    {
        fwrite(STDOUT, '[' . date('c') . "] worker tick start\n");
        $n = (new Worker())->tick();
        fwrite(STDOUT, '[' . date('c') . "] worker tick done, processed={$n}\n");
        return 0;
    }

    /** @param list<string> $args */
    private function runCreate(array $args): int
    {
        $opts = $this->parseOpts($args);
        $provider = (string) ($opts['provider'] ?? 'timeweb');
        $region = isset($opts['region']) ? (string) $opts['region'] : null;
        $count = isset($opts['count']) ? (int) $opts['count'] : 1;
        $keep = array_key_exists('keep-on-fail', $opts) || array_key_exists('keep_on_fail', $opts);
        $comment = isset($opts['comment']) ? (string) $opts['comment'] : null;
        $bsMode = isset($opts['bs-mode']) ? (string) $opts['bs-mode'] : (isset($opts['bs_mode']) ? (string) $opts['bs_mode'] : (Env::get('BS_MODE_DEFAULT', 'bsbord') ?? 'bsbord'));

        $ids = (new RunService())->createRuns($provider, $region, $count, $keep, $comment, 'cli', $bsMode);
        fwrite(STDOUT, 'Created runs: #' . implode(', #', $ids) . "\n");
        return 0;
    }

    private function destroyFailed(): int
    {
        $n = (new RunService())->destroyFailed('cli');
        fwrite(STDOUT, "Queued destroy for {$n} failed run(s)\n");
        return 0;
    }

    private function timewebFinances(): int
    {
        $svc = new \Wlsearch\Provider\ProviderAccountService();
        $rows = $svc->listAll('timeweb');
        if ($rows === []) {
            $rows = [['id' => null, 'name' => 'legacy .env']];
        }
        foreach ($rows as $row) {
            $id = isset($row['id']) ? (int) $row['id'] : 0;
            fwrite(STDOUT, "=== account #" . ($id ?: '-') . ' ' . ($row['name'] ?? '') . " ===\n");
            try {
                $p = $id > 0
                    ? \Wlsearch\Provider\ProviderFactory::make('timeweb', $id)
                    : new \Wlsearch\Provider\TimewebProvider();
                /** @var \Wlsearch\Provider\TimewebProvider $p */
                $f = $p->fetchFinances();
                \Wlsearch\Support\FileLog::write('timeweb', 'finances:cli', ['account' => $id ?: 'legacy', 'finances' => $f]);
                fwrite(STDOUT, json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
            } catch (\Throwable $e) {
                fwrite(STDOUT, 'ERROR: ' . $e->getMessage() . "\n");
            }
        }
        $log = dirname(__DIR__, 2) . '/storage/logs/timeweb.log';
        fwrite(STDOUT, "create log: {$log}\n");
        return 0;
    }

    private function inventory(): int
    {
        $pdo = Database::tryPdo();
        if ($pdo === null) {
            fwrite(STDERR, "DB unavailable\n");
            return 1;
        }
        $rows = $pdo->query(
            "SELECT ipv4, provider, operators, status, found_at FROM inventory WHERE status = 'active' ORDER BY found_at DESC LIMIT 100"
        )->fetchAll();

        if ($rows === []) {
            fwrite(STDOUT, "Inventory empty.\n");
            return 0;
        }
        foreach ($rows as $row) {
            fwrite(STDOUT, sprintf(
                "%-15s  %-10s  %-20s  %s  %s\n",
                $row['ipv4'],
                $row['provider'],
                (string) $row['operators'],
                $row['status'],
                $row['found_at']
            ));
        }
        return 0;
    }

    /** @param list<string> $args */
    private function agentTokenCreate(array $args): int
    {
        $opts = $this->parseOpts($args);
        $name = (string) ($opts['name'] ?? '');
        $operator = (string) ($opts['operator'] ?? 'other');
        if ($name === '') {
            fwrite(STDERR, "--name required\n");
            return 1;
        }
        $result = (new DeviceService())->create($name, $operator, 'cli');
        fwrite(STDOUT, "device_id={$result['device']['id']} operator={$operator}\n");
        fwrite(STDOUT, "TOKEN (save once): {$result['token']}\n");
        return 0;
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, "Unknown command: {$command}\n");
        $this->help();
        return 1;
    }

    /**
     * @param list<string> $args
     * @return array<string, string|true>
     */
    private function parseOpts(array $args): array
    {
        $out = [];
        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }
            $arg = substr($arg, 2);
            if (str_contains($arg, '=')) {
                [$k, $v] = explode('=', $arg, 2);
                $out[$k] = $v;
            } else {
                $out[$arg] = true;
            }
        }
        return $out;
    }
}
