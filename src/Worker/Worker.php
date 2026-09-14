<?php

declare(strict_types=1);

namespace Wlsearch\Worker;

use PDO;
use Wlsearch\Blacklist\BlacklistService;
use Wlsearch\Notify\TelegramNotifier;
use Wlsearch\Probe\CloudInitBuilder;
use Wlsearch\Probe\ControlChecker;
use Wlsearch\Provider\ProviderFactory;
use Wlsearch\Run\RunService;
use Wlsearch\Support\AsnLookup;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;
use Wlsearch\Support\Settings;
use Wlsearch\Task\TaskService;

final class Worker
{
    private PDO $pdo;
    private RunService $runs;
    private ControlChecker $control;
    private TelegramNotifier $tg;
    private TaskService $tasks;
    private BlacklistService $blacklist;

    public function __construct(
        ?PDO $pdo = null,
        ?RunService $runs = null,
        ?ControlChecker $control = null,
        ?TelegramNotifier $tg = null,
        ?TaskService $tasks = null,
        ?BlacklistService $blacklist = null,
    ) {
        $this->pdo = $pdo ?? Database::pdo();
        $this->runs = $runs ?? new RunService($this->pdo);
        $this->control = $control ?? new ControlChecker();
        $this->tg = $tg ?? new TelegramNotifier();
        $this->tasks = $tasks ?? new TaskService($this->pdo);
        $this->blacklist = $blacklist ?? new BlacklistService($this->pdo);
    }

    public function tick(): int
    {
        $expired = $this->tasks->expireStale();
        if ($expired > 0) {
            fwrite(STDOUT, "expired BS tasks: {$expired}\n");
        }

        // Ensure pending tasks for BS_CHECK runs
        $bsRuns = $this->pdo->query(
            "SELECT id, ipv4 FROM runs WHERE state = 'BS_CHECK' AND ipv4 IS NOT NULL AND ipv4 != ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($bsRuns as $r) {
            $this->tasks->ensureTaskForRun((int) $r['id'], (string) $r['ipv4']);
        }

        $processed = 0;
        $stmt = $this->pdo->query(
            "SELECT * FROM runs
             WHERE state IN ('ORDERING','PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','DESTROYING')
                OR (state IN ('FAIL_BS','FAIL_CONTROL','ERROR') AND keep_on_fail = 0 AND destroyed_at IS NULL AND provider_server_id IS NOT NULL)
             ORDER BY id ASC
             LIMIT 50"
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $run) {
            try {
                $this->process($run);
                $processed++;
            } catch (\Throwable $e) {
                $this->failRun((int) $run['id'], 'ERROR', $e->getMessage());
                fwrite(STDERR, 'run #' . $run['id'] . ' error: ' . $e->getMessage() . "\n");
            }
        }

        return $processed;
    }

    /** @param array<string, mixed> $run */
    private function process(array $run): void
    {
        $id = (int) $run['id'];
        $state = (string) $run['state'];

        if (in_array($state, ['FAIL_BS', 'FAIL_CONTROL', 'ERROR'], true)
            && !(int) $run['keep_on_fail']
            && !empty($run['provider_server_id'])
            && empty($run['destroyed_at'])
        ) {
            $this->setState($id, 'DESTROYING');
            $state = 'DESTROYING';
            $run['state'] = 'DESTROYING';
        }

        match ($state) {
            'ORDERING' => $this->handleOrdering($run),
            'PROVISIONING' => $this->handleProvisioning($run),
            'BOOTSTRAPPING' => $this->handleBootstrapping($run),
            'CONTROL_CHECK' => $this->handleControlCheck($run),
            'DESTROYING' => $this->handleDestroying($run),
            default => null,
        };
    }

    /** @param array<string, mixed> $run */
    private function handleOrdering(array $run): void
    {
        $id = (int) $run['id'];
        $provider = ProviderFactory::make((string) $run['provider']);
        $name = sprintf('wlsearch-%d-%s', $id, date('His'));
        $cloudInit = CloudInitBuilder::forRun((string) $run['provider'], $id);

        $info = $provider->create([
            'name' => $name,
            'region' => $run['region'],
            'cloud_init' => $cloudInit,
            'comment' => $run['comment'] ?? ('wlsearch run #' . $id),
        ]);

        $meta = null;
        if (!empty($info->raw['_wlsearch_meta']) && is_array($info->raw['_wlsearch_meta'])) {
            $meta = json_encode($info->raw['_wlsearch_meta'], JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE runs SET provider_server_id = ?, ipv4 = ?, provider_meta = ?, state = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$info->id, $info->ipv4, $meta, 'PROVISIONING', $id]);
        fwrite(STDOUT, "run #{$id}: created server {$info->id}\n");
    }

    /** @param array<string, mixed> $run */
    private function handleProvisioning(array $run): void
    {
        $id = (int) $run['id'];
        $serverId = (string) $run['provider_server_id'];
        if ($serverId === '') {
            $this->failRun($id, 'ERROR', 'missing provider_server_id');
            return;
        }

        $provider = ProviderFactory::make((string) $run['provider']);
        $info = $provider->get($serverId);

        $asn = null;
        $asnOrg = null;
        if ($info->ipv4) {
            $lookup = AsnLookup::lookup($info->ipv4);
            $asn = $lookup['asn'];
            $asnOrg = $lookup['org'];

            $bl = $this->blacklist->checkIp($info->ipv4, $asn);
            if ($bl['blocked']) {
                $stmt = $this->pdo->prepare(
                    'UPDATE runs SET ipv4 = ?, asn = ?, asn_org = ? WHERE id = ?'
                );
                $stmt->execute([$info->ipv4, $asn, $asnOrg, $id]);
                $this->failRun($id, 'ERROR', 'blacklist: ' . ($bl['reason'] ?? 'blocked'));
                return;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE runs SET ipv4 = ?, asn = ?, asn_org = ? WHERE id = ?'
        );
        $stmt->execute([$info->ipv4, $asn, $asnOrg !== null ? mb_substr($asnOrg, 0, 128) : null, $id]);

        if ($info->isReady()) {
            $this->setState($id, 'BOOTSTRAPPING');
            fwrite(STDOUT, "run #{$id}: IP {$info->ipv4} ready → BOOTSTRAPPING\n");
            return;
        }

        $timeout = Settings::int('PROVISION_TIMEOUT_SEC', Env::int('PROVISION_TIMEOUT_SEC', 900));
        if ($this->updatedAgeSeconds($run) > $timeout) {
            $this->failRun($id, 'ERROR', 'provision timeout waiting for IP/status');
        }
    }

    /** @param array<string, mixed> $run */
    private function handleBootstrapping(array $run): void
    {
        $id = (int) $run['id'];
        $ipv4 = (string) ($run['ipv4'] ?? '');
        if ($ipv4 === '') {
            $this->setState($id, 'PROVISIONING');
            return;
        }

        $result = $this->control->check($ipv4);
        if ($result['ok']) {
            $this->setState($id, 'CONTROL_CHECK');
            fwrite(STDOUT, "run #{$id}: probe responding → CONTROL_CHECK\n");
            return;
        }

        $timeout = Settings::int('BOOTSTRAP_TIMEOUT_SEC', Env::int('BOOTSTRAP_TIMEOUT_SEC', 900));
        if ($this->updatedAgeSeconds($run) > $timeout) {
            $this->failControl($run, 'bootstrap timeout: ' . ($result['error'] ?? 'no probe'));
        }
    }

    /** @param array<string, mixed> $run */
    private function handleControlCheck(array $run): void
    {
        $id = (int) $run['id'];
        $ipv4 = (string) ($run['ipv4'] ?? '');
        if ($ipv4 === '') {
            $this->failControl($run, 'no ipv4');
            return;
        }

        $result = $this->control->check($ipv4);
        if ($result['ok']) {
            $stmt = $this->pdo->prepare(
                'UPDATE runs SET control_ok = 1, state = ?, error_message = NULL, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute(['BS_CHECK', $id]);
            $this->tasks->ensureTaskForRun($id, $ipv4);
            $this->tg->send("wlsearch: CONTROL_OK run #{$id} ip={$ipv4} → BS_CHECK");
            fwrite(STDOUT, "run #{$id}: CONTROL_OK → BS_CHECK + task\n");
            return;
        }

        $timeout = Settings::int('CONTROL_CHECK_TIMEOUT_SEC', Env::int('CONTROL_CHECK_TIMEOUT_SEC', 300));
        if ($this->updatedAgeSeconds($run) > $timeout) {
            $this->failControl($run, $result['error'] ?? 'control check failed');
        }
    }

    /** @param array<string, mixed> $run */
    private function handleDestroying(array $run): void
    {
        $id = (int) $run['id'];
        $serverId = (string) ($run['provider_server_id'] ?? '');
        if ($serverId === '') {
            $stmt = $this->pdo->prepare(
                'UPDATE runs SET state = ?, destroyed_at = NOW(), updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute(['DESTROYED', $id]);
            return;
        }

        $provider = ProviderFactory::make((string) $run['provider']);
        $provider->destroy($serverId);

        $stmt = $this->pdo->prepare(
            'UPDATE runs SET state = ?, destroyed_at = NOW(), updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute(['DESTROYED', $id]);
        $this->tg->send('wlsearch: DESTROYED run #' . $id . ' server=' . $serverId);
        fwrite(STDOUT, "run #{$id}: destroyed {$serverId}\n");
    }

    /** @param array<string, mixed> $run */
    private function failControl(array $run, string $message): void
    {
        $id = (int) $run['id'];
        $stmt = $this->pdo->prepare(
            'UPDATE runs SET control_ok = 0, state = ?, verdict = ?, error_message = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute(['FAIL_CONTROL', 'FAIL_CONTROL', mb_substr($message, 0, 2000), $id]);
        $this->tg->send("wlsearch: FAIL_CONTROL run #{$id} ip=" . ($run['ipv4'] ?? '-') . ' — ' . $message);

        if (!(int) $run['keep_on_fail']) {
            $this->setState($id, 'DESTROYING');
        }
    }

    private function failRun(int $id, string $verdict, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE runs SET state = ?, verdict = ?, error_message = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$verdict, $verdict, mb_substr($message, 0, 2000), $id]);
        $this->tg->send("wlsearch: {$verdict} run #{$id} — {$message}");

        $run = $this->runs->get($id);
        if ($run && !(int) $run['keep_on_fail'] && !empty($run['provider_server_id']) && empty($run['destroyed_at'])) {
            $this->setState($id, 'DESTROYING');
        }
    }

    private function setState(int $id, string $state): void
    {
        $stmt = $this->pdo->prepare('UPDATE runs SET state = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$state, $id]);
    }

    /** @param array<string, mixed> $run */
    private function updatedAgeSeconds(array $run): int
    {
        $updated = strtotime((string) ($run['updated_at'] ?? $run['created_at'])) ?: time();
        return max(0, time() - $updated);
    }
}
