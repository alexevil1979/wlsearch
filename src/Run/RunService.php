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
    /** States that actually hold a live cloud VM (ORDERING = очередь, не считается). */
    public const LIVE_STATES = [
        'PROVISIONING', 'BOOTSTRAPPING', 'CONTROL_CHECK', 'BS_CHECK', 'DESTROYING',
    ];

    public const ACTIVE_STATES = [
        'ORDERING', 'PROVISIONING', 'BOOTSTRAPPING', 'CONTROL_CHECK', 'BS_CHECK', 'DESTROYING',
    ];

    public const TERMINAL_STATES = [
        'PASS', 'FAIL_BS', 'FAIL_CONTROL', 'ERROR', 'DESTROYED', 'KEEP', 'SKIPPED',
    ];

    /** Timeweb daily floating-IP create limit per account (and soft create budget). */
    public const CREATES_PER_ACCOUNT_DAY = 10;

    private PDO $pdo;
    private TelegramNotifier $tg;

    public function __construct(?PDO $pdo = null, ?TelegramNotifier $tg = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
        $this->tg = $tg ?? new TelegramNotifier();
    }

    /**
     * @param list<int>|null $accountIds selected accounts; null/empty = all enabled
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
        ?array $accountIds = null,
        bool $stopOnPass = true,
    ): array {
        $provider = strtolower($provider);
        if (!in_array($provider, ['timeweb', 'selectel'], true)) {
            throw new \InvalidArgumentException('provider must be timeweb|selectel');
        }
        if (!ProviderFactory::isConfigured($provider)) {
            throw new \RuntimeException('Провайдер не настроен — добавьте аккаунт в /accounts (или .env)');
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

        $accounts = new \Wlsearch\Provider\ProviderAccountService($this->pdo);
        $pool = $accounts->listEnabled($provider);
        if ($accountIds !== null && $accountIds !== []) {
            $allow = array_fill_keys(array_map('intval', $accountIds), true);
            $pool = array_values(array_filter($pool, static fn (array $r): bool => isset($allow[(int) $r['id']])));
        }
        if ($pool === [] && !ProviderFactory::isConfiguredLegacy($provider)) {
            throw new \RuntimeException('Нет включённых аккаунтов для ' . $provider . ' — отметьте галочки в /accounts');
        }

        $budget = $this->accountCreateBudget($pool);
        $maxByAccounts = array_sum($budget);
        if ($maxByAccounts <= 0 && $pool !== []) {
            throw new \RuntimeException(
                'Дневной лимит create исчерпан: ' . self::CREATES_PER_ACCOUNT_DAY . ' на аккаунт (сегодня уже использовано)'
            );
        }

        $count = max(1, min(100, $count));
        if ($pool !== [] && $count > $maxByAccounts) {
            $count = $maxByAccounts;
        }

        $this->assertCanCreate($count, $provider, $pool);

        $batchId = bin2hex(random_bytes(8));
        $ids = [];
        $stmt = $this->pdo->prepare(
            'INSERT INTO runs (batch_id, provider, provider_account_id, region, state, keep_on_fail, stop_on_pass, bs_mode, comment, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, \'ORDERING\', ?, ?, ?, ?, ?, NOW(), NOW())'
        );

        $rr = 0;
        $accountIdsOrdered = array_keys(array_filter($budget, static fn (int $n): bool => $n > 0));
        if ($accountIdsOrdered === [] && $pool === []) {
            $accountIdsOrdered = [0]; // legacy
            $budget[0] = $count;
        }

        for ($i = 0; $i < $count; $i++) {
            $accountId = null;
            if ($accountIdsOrdered !== [] && !($accountIdsOrdered === [0] && $pool === [])) {
                // round-robin among accounts that still have budget
                $tries = count($accountIdsOrdered);
                for ($t = 0; $t < $tries; $t++) {
                    $cand = $accountIdsOrdered[$rr % count($accountIdsOrdered)];
                    $rr++;
                    if (($budget[$cand] ?? 0) > 0) {
                        $accountId = $cand > 0 ? $cand : null;
                        $budget[$cand]--;
                        break;
                    }
                }
                if ($accountId === null && $pool !== []) {
                    break;
                }
            }

            $stmt->execute([
                $batchId,
                $provider,
                $accountId,
                $region !== null && $region !== '' ? $region : null,
                $keepOnFail ? 1 : 0,
                $stopOnPass ? 1 : 0,
                $bsMode,
                $comment !== null && $comment !== '' ? mb_substr($comment, 0, 255) : null,
                mb_substr($actor, 0, 64),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $ids[] = $id;
            if ($accountId !== null) {
                $accounts->markUsed($accountId);
            }
            Audit::log($actor, 'run.create', 'run', (string) $id, [
                'provider' => $provider,
                'provider_account_id' => $accountId,
                'batch_id' => $batchId,
                'stop_on_pass' => $stopOnPass,
                'region' => $region,
                'bs_mode' => $bsMode,
            ]);
        }

        if ($ids === []) {
            throw new \RuntimeException('Не удалось поставить run в очередь (бюджет аккаунтов = 0)');
        }

        $this->tg->send(sprintf(
            "wlsearch: очередь run×%d provider=%s batch=%s stop_on_pass=%s ids=%s",
            count($ids),
            $provider,
            $batchId,
            $stopOnPass ? '1' : '0',
            implode(',', $ids)
        ));

        return $ids;
    }

    /**
     * @param list<array<string,mixed>> $pool
     * @return array<int, int> accountId => remaining creates today
     */
    public function accountCreateBudget(array $pool): array
    {
        $budget = [];
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM runs WHERE provider_account_id = ? AND DATE(created_at) = CURDATE()'
        );
        foreach ($pool as $row) {
            $id = (int) $row['id'];
            $stmt->execute([$id]);
            $used = (int) $stmt->fetchColumn();
            $budget[$id] = max(0, self::CREATES_PER_ACCOUNT_DAY - $used);
        }
        return $budget;
    }

    /** How many creates left today across enabled/selected accounts. */
    public function dailyCreateCapacity(string $provider, ?array $accountIds = null): int
    {
        $accounts = new \Wlsearch\Provider\ProviderAccountService($this->pdo);
        $pool = $accounts->listEnabled($provider);
        if ($accountIds !== null && $accountIds !== []) {
            $allow = array_fill_keys(array_map('intval', $accountIds), true);
            $pool = array_values(array_filter($pool, static fn (array $r): bool => isset($allow[(int) $r['id']])));
        }
        if ($pool === []) {
            $fallback = Settings::int('MAX_CREATES_PER_DAY', 20);
            $today = (int) $this->pdo->query('SELECT COUNT(*) FROM runs WHERE DATE(created_at) = CURDATE()')->fetchColumn();
            return max(0, $fallback - $today);
        }
        return array_sum($this->accountCreateBudget($pool));
    }

    /** @param list<array<string,mixed>> $pool */
    public function assertCanCreate(int $count = 1, string $provider = 'timeweb', array $pool = []): void
    {
        $maxParallel = max(1, Settings::int('MAX_PARALLEL_VMS', 1));

        $live = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM runs WHERE state IN ('PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')"
        )->fetchColumn();

        // Очередь ORDERING можно копить; параллельно живых VM — не больше maxParallel
        if ($live >= $maxParallel) {
            throw new \RuntimeException(
                "Сейчас уже {$live} живых VM (лимит параллели {$maxParallel}). Дождитесь destroy — очередь ORDERING можно копить."
            );
        }

        if ($pool !== []) {
            $capacity = array_sum($this->accountCreateBudget($pool));
            if ($count > $capacity) {
                throw new \RuntimeException(
                    "Дневной лимит: {$capacity} create осталось (по "
                    . self::CREATES_PER_ACCOUNT_DAY . " на аккаунт × " . count($pool) . ')'
                );
            }
            return;
        }

        $maxDay = Settings::int('MAX_CREATES_PER_DAY', 20);
        $today = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM runs WHERE DATE(created_at) = CURDATE()'
        )->fetchColumn();
        if ($today + $count > $maxDay) {
            throw new \RuntimeException("Лимит MAX_CREATES_PER_DAY={$maxDay} (сегодня={$today})");
        }
    }

    public function skipBatchRemainder(string $batchId, int $exceptRunId, string $reason): int
    {
        if ($batchId === '') {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            "UPDATE runs SET state = 'SKIPPED', verdict = 'SKIPPED', error_message = ?, updated_at = NOW()
             WHERE batch_id = ? AND id != ? AND state = 'ORDERING'"
        );
        $stmt->execute([mb_substr($reason, 0, 2000), $batchId, $exceptRunId]);
        return $stmt->rowCount();
    }

    /**
     * Остановить всё в очереди: ORDERING→SKIPPED; живые (кроме PASS/KEEP)→DESTROYING.
     * @return array{skipped:int,destroying:int}
     */
    public function stopAllQueued(string $actor): array
    {
        $skip = $this->pdo->prepare(
            "UPDATE runs SET state = 'SKIPPED', verdict = 'SKIPPED',
                    error_message = 'остановлено вручную', updated_at = NOW()
             WHERE state = 'ORDERING'"
        );
        $skip->execute();
        $skipped = $skip->rowCount();

        $idsStmt = $this->pdo->query(
            "SELECT id FROM runs
             WHERE state IN ('PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK')
                OR (state IN ('FAIL_BS','FAIL_CONTROL','ERROR') AND keep_on_fail = 0
                    AND provider_server_id IS NOT NULL AND destroyed_at IS NULL)"
        );
        $ids = $idsStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $destroying = 0;
        foreach ($ids as $id) {
            $this->updateState((int) $id, 'DESTROYING', null, 'остановлено вручную');
            $destroying++;
        }

        Audit::log($actor, 'run.stop_all', 'runs', null, [
            'skipped' => $skipped,
            'destroying' => $destroying,
        ]);
        $this->tg->send("wlsearch: STOP очередь — skipped={$skipped} destroying={$destroying}");

        return ['skipped' => $skipped, 'destroying' => $destroying];
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
            if ((int) ($run['stop_on_pass'] ?? 0) === 1) {
                $batchId = (string) ($run['batch_id'] ?? '');
                if ($batchId !== '') {
                    $this->skipBatchRemainder($batchId, $runId, 'остановлено: найден PASS в batch');
                }
            }
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
            "SELECT r.*, a.name AS account_name
             FROM runs r
             LEFT JOIN provider_accounts a ON a.id = r.provider_account_id
             ORDER BY r.id DESC LIMIT {$limit}"
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
