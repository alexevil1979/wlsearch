<?php

declare(strict_types=1);

namespace Wlsearch\Run;

use PDO;
use Wlsearch\CheckedIp\CheckedIpService;
use Wlsearch\Inventory\InventoryService;
use Wlsearch\Notify\TelegramNotifier;
use Wlsearch\Probe\BsbordClient;
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
        string $bsMode = 'agent',
    ): array {
        $provider = strtolower($provider);
        if (!in_array($provider, ['timeweb', 'selectel'], true)) {
            throw new \InvalidArgumentException('provider must be timeweb|selectel');
        }
        if (!ProviderFactory::isConfigured($provider)) {
            throw new \RuntimeException('Провайдер не настроен (проверьте .env)');
        }

        $bsMode = strtolower($bsMode);
        if (!in_array($bsMode, ['agent', 'bsbord', 'both'], true)) {
            throw new \InvalidArgumentException('bs_mode: agent|bsbord|both');
        }
        if (in_array($bsMode, ['bsbord', 'both'], true)) {
            $token = \Wlsearch\Support\Settings::get('BSBORD_API_TOKEN', \Wlsearch\Support\Env::get('BSBORD_API_TOKEN', ''));
            if ($token === null || $token === '') {
                throw new \RuntimeException('Для bs_mode=' . $bsMode . ' нужен BSBORD_API_TOKEN в .env');
            }
        }

        $count = max(1, min(20, $count));
        $this->assertCanCreate($count, $provider);

        $ids = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (provider, region, state, keep_on_fail, bs_mode, comment, created_by, created_at, updated_at)
             VALUES (?, ?, \'ORDERING\', ?, ?, ?, ?, NOW(), NOW())'
        );

        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([
                $provider,
                $region !== null && $region !== '' ? $region : null,
                $keepOnFail ? 1 : 0,
                $bsMode,
                $comment !== null && $comment !== '' ? mb_substr($comment, 0, 255) : null,
                mb_substr($actor, 0, 64),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $ids[] = $id;
            Audit::log($actor, 'run.create', 'run', (string) $id, [
                'provider' => $provider,
                'region' => $region,
                'keep_on_fail' => $keepOnFail,
                'bs_mode' => $bsMode,
            ]);
        }

        $this->tg->send(sprintf(
            "wlsearch: создано run×%d provider=%s region=%s bs=%s ids=%s",
            count($ids),
            $provider,
            $region ?: '-',
            $bsMode,
            implode(',', $ids)
        ));

        return $ids;
    }

    public function assertCanCreate(int $count = 1, string $provider = 'timeweb'): void
    {
        $maxParallel = Settings::int('MAX_PARALLEL_VMS', 3);
        $maxDay = Settings::int('MAX_CREATES_PER_DAY', 20);
        $maxSpend = Settings::int('MAX_DAILY_SPEND_RUB', 500);
        $costKey = strtolower($provider) === 'selectel' ? 'SELECTEL_PRESET_COST_RUB' : 'TIMEWEB_PRESET_COST_RUB';
        $cost = Settings::int($costKey, 0);

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

    public function retryBs(int $runId, string $actor): string
    {
        $run = $this->get($runId);
        if ($run === null) {
            throw new \RuntimeException('Run not found');
        }
        $ipv4 = (string) ($run['ipv4'] ?? '');
        if ($ipv4 === '') {
            throw new \RuntimeException('Нет IP для BS-проверки');
        }
        if (in_array((string) $run['state'], ['DESTROYED', 'DESTROYING', 'ORDERING', 'PROVISIONING'], true)) {
            throw new \RuntimeException('Run в состоянии ' . $run['state'] . ' — retest недоступен');
        }

        $meta = [];
        if (!empty($run['provider_meta'])) {
            $decoded = json_decode((string) $run['provider_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        unset($meta['bsbord_checked'], $meta['bsbord_detail']);

        $this->pdo->prepare(
            'UPDATE runs SET state = ?, bs_ok = NULL, cellular_ok = NULL, bs_source = NULL,
                    verdict = NULL, error_message = NULL, provider_meta = ?, updated_at = NOW() WHERE id = ?'
        )->execute([
            'BS_CHECK',
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $runId,
        ]);
        Audit::log($actor, 'run.retry_bs', 'run', (string) $runId);

        $mode = (string) ($run['bs_mode'] ?? 'agent');
        if (!in_array($mode, ['bsbord', 'both'], true)) {
            return 'Сброшено в BS_CHECK — ждите phone agent / worker.';
        }

        $bsbord = new BsbordClient();
        if (!$bsbord->isConfigured()) {
            return 'Сброшено в BS_CHECK, но BSBORD_API_TOKEN не задан.';
        }

        @set_time_limit(300);

        try {
            $result = $bsbord->probeIpv4($ipv4);
        } catch (\Throwable $e) {
            $meta['bsbord_checked'] = 1;
            $meta['bsbord_detail'] = 'error: ' . $e->getMessage();
            $this->pdo->prepare(
                'UPDATE runs SET provider_meta = ?, state = ?, bs_ok = 0, bs_source = ?, verdict = ?,
                        error_message = ?, updated_at = NOW() WHERE id = ?'
            )->execute([
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'FAIL_BS',
                'bsbord',
                'FAIL_BS',
                mb_substr('bsbord error: ' . $e->getMessage(), 0, 2000),
                $runId,
            ]);
            (new CheckedIpService($this->pdo))->record(
                $ipv4,
                'fail_bs',
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $runId,
                'bsbord error: ' . $e->getMessage()
            );
            throw new \RuntimeException('bsbord error: ' . $e->getMessage());
        }

        $meta['bsbord_checked'] = 1;
        $meta['bsbord_detail'] = $result['detail'];
        $detail = mb_substr($result['detail'], 0, 2000);

        if ($result['ok']) {
            $ops = $result['operators'] !== [] ? implode(',', $result['operators']) : 'bsbord';
            $this->pdo->prepare(
                "UPDATE runs SET provider_meta = ?, bs_ok = 1, cellular_ok = 1, bs_source = 'bsbord',
                        state = 'PASS', verdict = 'PASS', error_message = ?, updated_at = NOW() WHERE id = ?"
            )->execute([
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $detail,
                $runId,
            ]);
            (new InventoryService($this->pdo))->upsertPass(
                $runId,
                $ipv4,
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $run['asn_org'] !== null ? (string) $run['asn_org'] : null,
                $ops,
                'bsbord retest: ' . $result['detail'],
            );
            (new CheckedIpService($this->pdo))->record(
                $ipv4,
                'pass',
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $runId,
                $result['detail']
            );
            $this->tg->send("wlsearch: PASS (retest bsbord) run #{$runId} ip={$ipv4} ops={$ops}");
            return 'PASS: ' . $result['detail'];
        }

        $this->pdo->prepare(
            "UPDATE runs SET provider_meta = ?, bs_ok = 0, bs_source = 'bsbord',
                    state = 'FAIL_BS', verdict = 'FAIL_BS', error_message = ?, updated_at = NOW() WHERE id = ?"
        )->execute([
            json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $detail,
            $runId,
        ]);
        (new InventoryService($this->pdo))->retireByIpv4(
            $ipv4,
            $actor,
            'retest FAIL_BS'
        );
        (new CheckedIpService($this->pdo))->record(
            $ipv4,
            'fail_bs',
            (string) $run['provider'],
            $run['asn'] !== null ? (int) $run['asn'] : null,
            $runId,
            $result['detail']
        );
        $this->tg->send("wlsearch: FAIL_BS (retest bsbord) run #{$runId} — {$detail}");
        return 'FAIL_BS: ' . $result['detail'];
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
