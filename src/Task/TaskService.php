<?php

declare(strict_types=1);

namespace Wlsearch\Task;

use PDO;
use Wlsearch\Inventory\InventoryService;
use Wlsearch\CheckedIp\CheckedIpService;
use Wlsearch\Notify\TelegramNotifier;
use Wlsearch\Run\RunService;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;
use Wlsearch\Support\Settings;

final class TaskService
{
    private PDO $pdo;
    private InventoryService $inventory;
    private TelegramNotifier $tg;
    private CheckedIpService $checkedIps;

    public function __construct(
        ?PDO $pdo = null,
        ?InventoryService $inventory = null,
        ?TelegramNotifier $tg = null,
        ?CheckedIpService $checkedIps = null,
    ) {
        $this->pdo = $pdo ?? Database::pdo();
        $this->inventory = $inventory ?? new InventoryService($this->pdo);
        $this->tg = $tg ?? new TelegramNotifier();
        $this->checkedIps = $checkedIps ?? new CheckedIpService($this->pdo);
    }

    public function ensureTaskForRun(int $runId, string $ipv4): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM tasks WHERE run_id = ? AND status IN ('pending','assigned') LIMIT 1"
        );
        $stmt->execute([$runId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            return (int) $existing;
        }

        $ttl = Settings::int('BS_TASK_TTL_SEC', Env::int('BS_TASK_TTL_SEC', 900));
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (run_id, status, target_ipv4, probe_marker, created_at, updated_at, expires_at)
             VALUES (?, \'pending\', ?, \'WL_PROBE_OK\', NOW(), NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))'
        );
        $stmt->execute([$runId, $ipv4, $ttl]);
        return (int) $this->pdo->lastInsertId();
    }

    public function claimNext(int $deviceId): ?array
    {
        return $this->claimNextFallback($deviceId);
    }

    /** @return array<string, mixed>|null */
    private function claimNextFallback(int $deviceId): ?array
    {
        $stmt = $this->pdo->query(
            "SELECT t.id FROM tasks t
             INNER JOIN runs r ON r.id = t.run_id
             WHERE t.status = 'pending' AND r.state = 'BS_CHECK'
               AND (t.expires_at IS NULL OR t.expires_at > NOW())
             ORDER BY t.id ASC LIMIT 1"
        );
        $id = $stmt ? $stmt->fetchColumn() : false;
        if (!$id) {
            return null;
        }
        $upd = $this->pdo->prepare(
            "UPDATE tasks SET status = 'assigned', device_id = ?, assigned_at = NOW(), updated_at = NOW()
             WHERE id = ? AND status = 'pending'"
        );
        $upd->execute([$deviceId, $id]);
        if ($upd->rowCount() === 0) {
            return null;
        }
        $get = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $get->execute([$id]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function submitResult(int $taskId, int $deviceId, array $payload): void
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([$taskId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$task) {
            throw new \RuntimeException('task not found', 404);
        }
        if ((int) ($task['device_id'] ?? 0) !== $deviceId && $task['status'] === 'assigned') {
            // allow same device only
            if ((int) $task['device_id'] !== $deviceId) {
                throw new \RuntimeException('task assigned to another device', 403);
            }
        }
        if (!in_array($task['status'], ['assigned', 'pending'], true)) {
            throw new \RuntimeException('task already finished', 409);
        }

        $cellular = !empty($payload['cellular']);
        $wifi = !empty($payload['wifi']);
        $vpn = !empty($payload['vpn']);
        $markerOk = !empty($payload['marker_ok']) || (
            isset($payload['body']) && is_string($payload['body']) && str_contains($payload['body'], 'WL_PROBE_OK')
        );
        $httpOk = !empty($payload['http_ok']) || (isset($payload['http_status']) && (int) $payload['http_status'] >= 200 && (int) $payload['http_status'] < 400);
        $error = isset($payload['error']) ? (string) $payload['error'] : null;

        // Strict: cellular only, no wifi, no vpn, marker present
        $bsOk = $cellular && !$wifi && !$vpn && $markerOk && $httpOk && $error === null;

        $this->pdo->prepare(
            "UPDATE tasks SET status = 'done', finished_at = NOW(), updated_at = NOW(), result_json = ?, device_id = COALESCE(device_id, ?)
             WHERE id = ?"
        )->execute([
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $deviceId,
            $taskId,
        ]);

        $runId = (int) $task['run_id'];
        $runStmt = $this->pdo->prepare('SELECT * FROM runs WHERE id = ?');
        $runStmt->execute([$runId]);
        $run = $runStmt->fetch(PDO::FETCH_ASSOC);
        if (!$run) {
            return;
        }

        $dev = $this->pdo->prepare('SELECT operator FROM devices WHERE id = ?');
        $dev->execute([$deviceId]);
        $operator = (string) ($dev->fetchColumn() ?: 'other');

        $controlOk = (int) ($run['control_ok'] ?? 0) === 1;

        if ($bsOk && $controlOk && $cellular) {
            $this->pdo->prepare(
                "UPDATE runs SET bs_ok = 1, cellular_ok = 1, bs_source = 'agent', state = 'PASS', verdict = 'PASS',
                        error_message = NULL, tested_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$runId]);

            $this->inventory->upsertPass(
                $runId,
                (string) $run['ipv4'],
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $run['asn_org'] !== null ? (string) $run['asn_org'] : null,
                $operator,
            );
            $this->checkedIps->record(
                (string) $run['ipv4'],
                'pass',
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $runId,
                'agent:' . $operator
            );

            $this->tg->send("wlsearch: PASS (agent) run #{$runId} ip={$run['ipv4']} operator={$operator}");
            Audit::log('device:' . $deviceId, 'run.pass', 'run', (string) $runId, ['operator' => $operator]);
            if ((int) ($run['stop_on_pass'] ?? 0) === 1) {
                $batchId = (string) ($run['batch_id'] ?? '');
                if ($batchId !== '') {
                    (new RunService($this->pdo, $this->tg))->skipBatchRemainder(
                        $batchId,
                        $runId,
                        'остановлено: найден PASS в batch'
                    );
                }
            }
            return;
        }

        $reason = $error ?: (!$cellular ? 'not cellular' : ($wifi ? 'wifi on' : ($vpn ? 'vpn on' : (!$markerOk ? 'no marker' : 'bs fail'))));
        $this->pdo->prepare(
            "UPDATE runs SET bs_ok = 0, cellular_ok = ?, state = 'FAIL_BS', verdict = 'FAIL_BS',
                    error_message = ?, tested_at = NOW(), updated_at = NOW() WHERE id = ?"
        )->execute([$cellular ? 1 : 0, mb_substr($reason, 0, 2000), $runId]);

        if (!empty($run['ipv4'])) {
            $this->checkedIps->record(
                (string) $run['ipv4'],
                'fail_bs',
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $runId,
                $reason
            );
        }

        $this->tg->send("wlsearch: FAIL_BS run #{$runId} ip=" . ($run['ipv4'] ?? '-') . " — {$reason}");
        Audit::log('device:' . $deviceId, 'run.fail_bs', 'run', (string) $runId, ['reason' => $reason]);

        if (!(int) $run['keep_on_fail']) {
            $this->pdo->prepare("UPDATE runs SET state = 'DESTROYING', updated_at = NOW() WHERE id = ?")->execute([$runId]);
        }
    }

    public function expireStale(): int
    {
        $n = 0;
        $stmt = $this->pdo->query(
            "SELECT t.*, r.keep_on_fail, r.ipv4 FROM tasks t
             INNER JOIN runs r ON r.id = t.run_id
             WHERE t.status IN ('pending','assigned')
               AND t.expires_at IS NOT NULL AND t.expires_at < NOW()
               AND r.state = 'BS_CHECK'"
        );
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $task) {
            $this->pdo->prepare(
                "UPDATE tasks SET status = 'expired', finished_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$task['id']]);

            $this->pdo->prepare(
                "UPDATE runs SET bs_ok = 0, state = 'FAIL_BS', verdict = 'FAIL_BS',
                        error_message = 'BS task expired (no agent result)', tested_at = NOW(), updated_at = NOW() WHERE id = ?"
            )->execute([$task['run_id']]);

            if (!empty($task['ipv4'])) {
                $this->checkedIps->record((string) $task['ipv4'], 'fail_bs', null, null, (int) $task['run_id'], 'BS task expired');
            }

            if (!(int) $task['keep_on_fail']) {
                $this->pdo->prepare("UPDATE runs SET state = 'DESTROYING', updated_at = NOW() WHERE id = ?")
                    ->execute([$task['run_id']]);
            }
            $this->tg->send('wlsearch: FAIL_BS (expired) run #' . $task['run_id'] . ' ip=' . ($task['ipv4'] ?? '-'));
            $n++;
        }
        return $n;
    }
}
