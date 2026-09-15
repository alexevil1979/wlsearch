<?php

declare(strict_types=1);

namespace Wlsearch\Worker;

use PDO;
use Wlsearch\Blacklist\BlacklistService;
use Wlsearch\CheckedIp\CheckedIpService;
use Wlsearch\Inventory\InventoryService;
use Wlsearch\Notify\TelegramNotifier;
use Wlsearch\Probe\BsbordClient;
use Wlsearch\Probe\CloudInitBuilder;
use Wlsearch\Probe\ControlChecker;
use Wlsearch\Provider\ProviderFactory;
use Wlsearch\Run\RunService;
use Wlsearch\Support\AsnLookup;
use Wlsearch\Support\Audit;
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
    private BsbordClient $bsbord;
    private InventoryService $inventory;
    private CheckedIpService $checkedIps;

    public function __construct(
        ?PDO $pdo = null,
        ?RunService $runs = null,
        ?ControlChecker $control = null,
        ?TelegramNotifier $tg = null,
        ?TaskService $tasks = null,
        ?BlacklistService $blacklist = null,
        ?BsbordClient $bsbord = null,
        ?InventoryService $inventory = null,
        ?CheckedIpService $checkedIps = null,
    ) {
        $this->pdo = $pdo ?? Database::pdo();
        $this->runs = $runs ?? new RunService($this->pdo);
        $this->control = $control ?? new ControlChecker();
        $this->tg = $tg ?? new TelegramNotifier();
        $this->tasks = $tasks ?? new TaskService($this->pdo);
        $this->blacklist = $blacklist ?? new BlacklistService($this->pdo);
        $this->bsbord = $bsbord ?? new BsbordClient();
        $this->inventory = $inventory ?? new InventoryService($this->pdo);
        $this->checkedIps = $checkedIps ?? new CheckedIpService($this->pdo);
    }

    public function tick(): int
    {
        $expired = $this->tasks->expireStale();
        if ($expired > 0) {
            fwrite(STDOUT, "expired BS tasks: {$expired}\n");
        }

        // Phone-agent tasks only for agent/both modes
        $bsRuns = $this->pdo->query(
            "SELECT id, ipv4, bs_mode FROM runs
             WHERE state = 'BS_CHECK' AND ipv4 IS NOT NULL AND ipv4 != ''"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($bsRuns as $r) {
            $mode = (string) ($r['bs_mode'] ?? 'agent');
            if (in_array($mode, ['agent', 'both'], true)) {
                $this->tasks->ensureTaskForRun((int) $r['id'], (string) $r['ipv4']);
            }
        }

        $processed = 0;
        $stmt = $this->pdo->query(
            "SELECT * FROM runs
             WHERE state IN ('ORDERING','PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')
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
            'BS_CHECK' => $this->handleBsCheck($run),
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
        fwrite(STDOUT, "run #{$id}: created server {$info->id} status={$info->status} ip=" . ($info->ipv4 ?: '-') . "\n");
        if ($info->isUnpaidOrBlocked()) {
            fwrite(STDOUT, "run #{$id}: WARNING create returned {$info->status} — see storage/logs/timeweb.log\n");
        }
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

        if ($info->isUnpaidOrBlocked()) {
            $stmt = $this->pdo->prepare(
                'UPDATE runs SET ipv4 = ?, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute([$info->ipv4, $id]);
            $finHint = '';
            if (!empty($info->raw['_wlsearch_meta']['finances_before']) && is_array($info->raw['_wlsearch_meta']['finances_before'])) {
                $fb = $info->raw['_wlsearch_meta']['finances_before'];
                $finHint = sprintf(
                    ' balance=%.2f monthly_fee=%.2f hours_left=%s',
                    (float) ($fb['balance'] ?? 0),
                    (float) ($fb['monthly_fee'] ?? 0),
                    (string) ($fb['hours_left'] ?? '?')
                );
            }
            fwrite(STDOUT, "run #{$id}: provider status={$info->status} (unpaid/blocked) → destroy\n");
            $this->failRun(
                $id,
                'ERROR',
                'Timeweb status=' . $info->status . ' (Не оплачен)' . $finHint
                . ' — см. storage/logs/timeweb.log; отдельной активации оплаты в API нет'
            );
            return;
        }

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
                $this->checkedIps->record(
                    $info->ipv4,
                    'fail_seen',
                    (string) $run['provider'],
                    $asn,
                    $id,
                    'blacklist: ' . ($bl['reason'] ?? 'blocked')
                );
                $this->failRun($id, 'ERROR', 'blacklist: ' . ($bl['reason'] ?? 'blocked'));
                return;
            }

            $known = $this->checkedIps->shouldDestroyImmediately($info->ipv4);
            if ($known !== null) {
                $stmt = $this->pdo->prepare(
                    'UPDATE runs SET ipv4 = ?, asn = ?, asn_org = ? WHERE id = ?'
                );
                $stmt->execute([$info->ipv4, $asn, $asnOrg !== null ? mb_substr($asnOrg, 0, 128) : null, $id]);
                fwrite(STDOUT, "run #{$id}: known IP {$info->ipv4} → destroy ({$known})\n");
                $this->failRun($id, 'ERROR', 'known IP skip: ' . $known);
                return;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE runs SET ipv4 = ?, asn = ?, asn_org = ? WHERE id = ?'
        );
        $stmt->execute([$info->ipv4, $asn, $asnOrg !== null ? mb_substr($asnOrg, 0, 128) : null, $id]);

        if ($info->isReady()) {
            $this->setState($id, 'BOOTSTRAPPING');
            fwrite(STDOUT, "run #{$id}: IP {$info->ipv4} status={$info->status} → BOOTSTRAPPING\n");
            return;
        }

        fwrite(STDOUT, sprintf(
            "run #%d: provisioning wait server=%s status=%s ip=%s\n",
            $id,
            $serverId,
            $info->status,
            $info->ipv4 ?: '-'
        ));

        $timeout = Settings::int('PROVISION_TIMEOUT_SEC', Env::int('PROVISION_TIMEOUT_SEC', 900));
        if ($this->updatedAgeSeconds($run) > $timeout) {
            $this->failRun(
                $id,
                'ERROR',
                'provision timeout status=' . $info->status . ' ip=' . ($info->ipv4 ?: 'none')
            );
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

        $serverId = (string) ($run['provider_server_id'] ?? '');
        if ($serverId !== '') {
            try {
                $info = ProviderFactory::make((string) $run['provider'])->get($serverId);
                if ($info->isUnpaidOrBlocked()) {
                    fwrite(STDOUT, "run #{$id}: bootstrap abort status={$info->status}\n");
                    $this->failRun(
                        $id,
                        'ERROR',
                        'Timeweb status=' . $info->status . ' (Не оплачен/заблокирован) во время bootstrap'
                    );
                    return;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: provider get during bootstrap: " . $e->getMessage() . "\n");
            }
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
            $mode = (string) ($run['bs_mode'] ?? 'agent');
            if (in_array($mode, ['agent', 'both'], true)) {
                $this->tasks->ensureTaskForRun($id, $ipv4);
            }
            $this->tg->send("wlsearch: CONTROL_OK run #{$id} ip={$ipv4} → BS_CHECK ({$mode})");
            fwrite(STDOUT, "run #{$id}: CONTROL_OK → BS_CHECK mode={$mode}\n");
            return;
        }

        $timeout = Settings::int('CONTROL_CHECK_TIMEOUT_SEC', Env::int('CONTROL_CHECK_TIMEOUT_SEC', 300));
        if ($this->updatedAgeSeconds($run) > $timeout) {
            $this->failControl($run, $result['error'] ?? 'control check failed');
        }
    }

    /** @param array<string, mixed> $run */
    private function handleBsCheck(array $run): void
    {
        $id = (int) $run['id'];
        $mode = (string) ($run['bs_mode'] ?? 'agent');
        $ipv4 = (string) ($run['ipv4'] ?? '');

        if (!in_array($mode, ['bsbord', 'both'], true)) {
            return;
        }

        if ($ipv4 === '') {
            $this->failBs($run, 'no ipv4 for bsbord');
            return;
        }

        $meta = [];
        if (!empty($run['provider_meta'])) {
            $decoded = json_decode((string) $run['provider_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        // Don't burn bsbord credits every cron tick
        if (!empty($meta['bsbord_checked'])) {
            if ($mode === 'bsbord') {
                $timeout = Settings::int('BS_TASK_TTL_SEC', Env::int('BS_TASK_TTL_SEC', 900));
                if ($this->updatedAgeSeconds($run) > $timeout) {
                    $this->failBs($run, (string) ($meta['bsbord_detail'] ?? 'bsbord failed'));
                }
            }
            return;
        }

        try {
            $result = $this->bsbord->probeIpv4($ipv4);
        } catch (\Throwable $e) {
            $meta['bsbord_checked'] = 1;
            $meta['bsbord_detail'] = 'error: ' . $e->getMessage();
            $this->saveMeta($id, $meta);

            if ($mode === 'bsbord') {
                $this->failBs($run, 'bsbord error: ' . $e->getMessage());
            } else {
                fwrite(STDERR, "run #{$id}: bsbord error, fallback agent: " . $e->getMessage() . "\n");
            }
            return;
        }

        $meta['bsbord_checked'] = 1;
        $meta['bsbord_detail'] = $result['detail'];
        $this->saveMeta($id, $meta);

        if ($result['ok']) {
            $ops = $result['operators'] !== [] ? implode(',', $result['operators']) : 'bsbord';
            $stmt = $this->pdo->prepare(
                "UPDATE runs SET bs_ok = 1, cellular_ok = 1, bs_source = 'bsbord', state = 'PASS', verdict = 'PASS',
                        error_message = ?, updated_at = NOW() WHERE id = ?"
            );
            $stmt->execute([mb_substr($result['detail'], 0, 2000), $id]);

            $this->inventory->upsertPass(
                $id,
                $ipv4,
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $run['asn_org'] !== null ? (string) $run['asn_org'] : null,
                $ops,
                'bsbord: ' . $result['detail'],
            );
            $this->checkedIps->record(
                $ipv4,
                'pass',
                (string) $run['provider'],
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $id,
                $result['detail']
            );

            $this->tg->send("wlsearch: PASS (bsbord) run #{$id} ip={$ipv4} ops={$ops}");
            Audit::log('bsbord', 'run.pass', 'run', (string) $id, ['operators' => $ops]);
            fwrite(STDOUT, "run #{$id}: PASS via bsbord\n");
            return;
        }

        if ($mode === 'bsbord') {
            $this->failBs($run, $result['detail']);
            return;
        }

        fwrite(STDOUT, "run #{$id}: bsbord not ok, waiting agent — " . $result['detail'] . "\n");
    }

    /** @param array<string, mixed> $meta */
    private function saveMeta(int $id, array $meta): void
    {
        $stmt = $this->pdo->prepare('UPDATE runs SET provider_meta = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /** @param array<string, mixed> $run */
    private function failBs(array $run, string $message): void
    {
        $id = (int) $run['id'];
        $stmt = $this->pdo->prepare(
            "UPDATE runs SET bs_ok = 0, bs_source = 'bsbord', state = 'FAIL_BS', verdict = 'FAIL_BS',
                    error_message = ?, updated_at = NOW() WHERE id = ?"
        );
        $stmt->execute([mb_substr($message, 0, 2000), $id]);
        $this->tg->send("wlsearch: FAIL_BS (bsbord) run #{$id} — {$message}");

        $ipv4 = (string) ($run['ipv4'] ?? '');
        if ($ipv4 !== '') {
            $this->checkedIps->record(
                $ipv4,
                'fail_bs',
                (string) ($run['provider'] ?? null),
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $id,
                $message
            );
        }

        if (!(int) $run['keep_on_fail']) {
            $this->setState($id, 'DESTROYING');
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

        $ipv4 = (string) ($run['ipv4'] ?? '');
        if ($ipv4 !== '') {
            $this->checkedIps->record(
                $ipv4,
                'fail_control',
                (string) ($run['provider'] ?? null),
                $run['asn'] !== null ? (int) $run['asn'] : null,
                $id,
                $message
            );
        }

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
        if ($run) {
            $ipv4 = (string) ($run['ipv4'] ?? '');
            if ($ipv4 !== '' && !str_starts_with($message, 'known IP skip:')) {
                $this->checkedIps->record(
                    $ipv4,
                    'error',
                    (string) ($run['provider'] ?? null),
                    $run['asn'] !== null ? (int) $run['asn'] : null,
                    $id,
                    $message
                );
            }
            if (!(int) $run['keep_on_fail'] && !empty($run['provider_server_id']) && empty($run['destroyed_at'])) {
                $this->setState($id, 'DESTROYING');
            }
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
