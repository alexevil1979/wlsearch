<?php

declare(strict_types=1);

namespace Wlsearch\Run;

use PDO;
use Wlsearch\Notify\TelegramNotifier;
use Wlsearch\Provider\ProviderFactory;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;
use Wlsearch\Support\Settings;

final class RunService
{
    public const ACTIVE_STATES = [
        'ORDERING', 'PROVISIONING', 'BOOTSTRAPPING', 'CONTROL_CHECK', 'BS_CHECK', 'DESTROYING',
    ];

    public const TERMINAL_STATES = [
        'PASS', 'FAIL_BS', 'FAIL_CONTROL', 'ERROR', 'DESTROYED', 'KEEP',
    ];

    private PDO $pdo;
    private TelegramNotifier $tg;

    public function __construct(?PDO $pdo = null, ?TelegramNotifier $tg = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
        $this->tg = $tg ?? new TelegramNotifier();
    }

    /**
     * @return list<int> created run ids
     */
    public function createRuns(
        string $provider,
        ?string $region,
        int $count,
        bool $keepOnFail,
        ?string $comment,
        string $actor,
    ): array {
        $provider = strtolower($provider);
        if (!in_array($provider, ['timeweb', 'selectel'], true)) {
            throw new \InvalidArgumentException('provider must be timeweb|selectel');
        }
        if ($provider === 'selectel') {
            throw new \RuntimeException('Selectel — Phase 3');
        }
        if (!ProviderFactory::isConfigured($provider)) {
            throw new \RuntimeException('Провайдер не настроен (проверьте .env токены)');
        }

        $count = max(1, min(20, $count));
        $this->assertCanCreate($count);

        $ids = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (provider, region, state, keep_on_fail, comment, created_by, created_at, updated_at)
             VALUES (?, ?, \'ORDERING\', ?, ?, ?, NOW(), NOW())'
        );

        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                $provider,
                $region !== null && $region !== '' ? $region : null,
                $keepOnFail ? 1 : 0,
                $comment !== null && $comment !== '' ? mb_substr($comment, 0, 255) : null,
                mb_substr($actor, 0, 64),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $ids[] = $id;
            Audit::log($actor, 'run.create', 'run', (string) $id, [
                'provider' => $provider,
                'region' => $region,
                'keep_on_fail' => $keepOnFail,
            ]);
        }

        $this->tg->send(sprintf(
            "wlsearch: создано run×%d provider=%s region=%s ids=%s",
            count($ids),
            $provider,
            $region ?: '-',
            implode(',', $ids)
        ));

        return $ids;
    }

    public function assertCanCreate(int $count = 1): void
    {
        $maxParallel = Settings::int('MAX_PARALLEL_VMS', 3);
        $maxDay = Settings::int('MAX_CREATES_PER_DAY', 20);
        $maxSpend = Settings::int('MAX_DAILY_SPEND_RUB', 500);
        $cost = Settings::int('TIMEWEB_PRESET_COST_RUB', 0);

        $active = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM runs WHERE state IN ('ORDERING','PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')"
        )->fetchColumn();

        if ($active + $count > $maxParallel) {
            throw new \RuntimeException("Лимит MAX_PARALLEL_VMS={$maxParallel} (активных={$active})");
        }

        $today = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM runs WHERE DATE(created_at) = CURDATE()'
        )->fetchColumn();
        if ($today + $count > $maxDay) {
            throw new \RuntimeException("Лимит MAX_CREATES_PER_DAY={$maxDay} (сегодня={$today})");
        }

        if ($cost > 0 && $maxSpend > 0) {
            $estimated = ($today + $count) * $cost;
            if ($estimated > $maxSpend) {
                throw new \RuntimeException("Лимит MAX_DAILY_SPEND_RUB={$maxSpend} (оценка={$estimated})");
            }
        }
    }

    public function requestDestroy(int $runId, string $actor): void
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Run not found');
        }
        if (in_array($run['state'], ['DESTROYED', 'DESTROYING'], true)) {
            return;
        }
        $this->updateState($runId, 'DESTROYING', null, null);
        Audit::log($actor, 'run.destroy_request', 'run', (string) $runId);
        $this->tg->send("wlsearch: destroy requested run #{$runId} ip=" . ($run['ipv4'] ?? '-'));
    }

    public function requestKeep(int $runId, string $actor): void
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Run not found');
        }
        $stmt = $this->pdo->prepare('UPDATE runs SET keep_on_fail = 1, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$runId]);

        if (in_array($run['state'], ['FAIL_BS', 'FAIL_CONTROL', 'ERROR'], true)) {
            $this->updateState($runId, 'KEEP', $run['verdict'], null);
        }

        Audit::log($actor, 'run.keep', 'run', (string) $runId);
        $this->tg->send("wlsearch: KEEP run #{$runId} ip=" . ($run['ipv4'] ?? '-'));
    }

    public function retryControl(int $runId, string $actor): void
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Run not found');
        }
        if ($run['ipv4'] === null || $run['ipv4'] === '') {
            throw new \RuntimeException('Нет IP для control check');
        }
        $this->pdo->prepare(
            'UPDATE runs SET state = ?, control_ok = NULL, verdict = NULL, error_message = NULL, updated_at = NOW() WHERE id = ?'
        )->execute(['CONTROL_CHECK', $runId]);
        Audit::log($actor, 'run.retry_control', 'run', (string) $runId);
    }

    public function retryBs(int $runId, string $actor): void
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Run not found');
        }
        if (!(int) ($run['control_ok'] ?? 0)) {
            throw new \RuntimeException('Сначала нужен control_ok (Phase 1)');
        }
        $this->pdo->prepare(
            'UPDATE runs SET state = ?, bs_ok = NULL, cellular_ok = NULL, verdict = NULL, error_message = NULL, updated_at = NOW() WHERE id = ?'
        )->execute(['BS_CHECK', $runId]);
        Audit::log($actor, 'run.retry_bs', 'run', (string) $runId);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM runs WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->pdo->query(
            "SELECT * FROM runs ORDER BY id DESC LIMIT {$limit}"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function updateState(int $id, string $state, ?string $verdict, ?string $error): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE runs SET state = ?, verdict = COALESCE(?, verdict), error_message = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$state, $verdict, $error, $id]);
    }

    public function destroyFailed(string $actor = 'cli'): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM runs
             WHERE keep_on_fail = 0
               AND state IN ('FAIL_BS','FAIL_CONTROL','ERROR')
               AND provider_server_id IS NOT NULL
               AND destroyed_at IS NULL"
        );
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) {
            $this->requestDestroy((int) $id, $actor);
            $n++;
        }
        return $n;
    }
}
