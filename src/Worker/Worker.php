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
        try {
            $n = (new \Wlsearch\Database\Migrator())->migrateQuiet();
            if ($n > 0) {
                fwrite(STDOUT, "auto-migrated {$n} schema change(s)\n");
            }
        } catch (\Throwable $e) {
            fwrite(STDERR, 'auto-migrate: ' . $e->getMessage() . "\n");
        }

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
        // Сначала destroy/live, потом ORDERING — строго последовательно
        usort($rows, static function (array $a, array $b): int {
            $prio = static fn (string $s): int => $s === 'ORDERING' ? 1 : 0;
            $c = $prio((string) $a['state']) <=> $prio((string) $b['state']);
            return $c !== 0 ? $c : ((int) $a['id'] <=> (int) $b['id']);
        });

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

        $maxParallel = max(1, Settings::int('MAX_PARALLEL_VMS', 1));
        $live = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM runs WHERE state IN ('PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')"
        )->fetchColumn();
        if ($live >= $maxParallel) {
            return;
        }

        // Атомарно забираем ORDERING → иначе два worker/cron создают две VM на один run
        $claim = $this->pdo->prepare(
            "UPDATE runs SET state = 'PROVISIONING', updated_at = NOW()
             WHERE id = ? AND state = 'ORDERING'"
        );
        $claim->execute([$id]);
        if ($claim->rowCount() === 0) {
            return;
        }
        $run['state'] = 'PROVISIONING';

        // На том же аккаунте ещё висит PASS/KEEP — burn не даст второму create
        $accountId = isset($run['provider_account_id']) ? (int) $run['provider_account_id'] : 0;
        if ($accountId > 0 && (string) $run['provider'] === 'timeweb') {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM runs
                 WHERE provider_account_id = ?
                   AND state IN ('PASS','KEEP')
                   AND provider_server_id IS NOT NULL AND provider_server_id != ''
                   AND destroyed_at IS NULL"
            );
            $st->execute([$accountId]);
            $kept = (int) $st->fetchColumn();
            if ($kept > 0) {
                $switched = $this->reassignOrderingAccount($run, [$accountId]);
                if (!$switched) {
                    fwrite(STDOUT, "run #{$id}: acc #{$accountId} занят PASS/KEEP — жду другой аккаунт\n");
                    return;
                }
                $run = $this->runs->get($id) ?? $run;
                $accountId = (int) ($run['provider_account_id'] ?? 0);
            }
        }

        $accounts = new \Wlsearch\Provider\ProviderAccountService($this->pdo);
        if ($accountId <= 0) {
            $picked = $accounts->pick((string) $run['provider']);
            if ($picked !== null) {
                $accountId = (int) $picked['id'];
                $this->pdo->prepare('UPDATE runs SET provider_account_id = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$accountId, $id]);
                $run['provider_account_id'] = $accountId;
            }
        }

        $name = sprintf('wlsearch-%d-%s', $id, date('His'));
        $cloudInit = CloudInitBuilder::forRun((string) $run['provider'], $id);

        $tried = [];
        $lastBalanceErr = null;
        $maxAccountTries = max(1, count($accounts->listEnabled((string) $run['provider'])) + 1);

        for ($attempt = 0; $attempt < $maxAccountTries; $attempt++) {
            if ($accountId > 0) {
                $tried[$accountId] = true;
            }
            $run['provider_account_id'] = $accountId > 0 ? $accountId : null;
            $provider = ProviderFactory::forRun($run);

            try {
                $info = $provider->create([
                    'name' => $name,
                    'region' => $run['region'],
                    'cloud_init' => $cloudInit,
                    'comment' => $run['comment'] ?? ('wlsearch run #' . $id),
                ]);
                if ($accountId > 0) {
                    $accounts->markUsed($accountId);
                }
                $lastBalanceErr = null;
                break;
            } catch (\Wlsearch\Provider\BalanceShortException $e) {
                // больше не блокируем create по оценке запаса; на всякий случай пробуем другой аккаунт
                $lastBalanceErr = $e;
                if ($accountId > 0) {
                    $accounts->markUsed($accountId, $e->getMessage());
                }
                fwrite(STDOUT, "run #{$id}: balance note on acc #" . ($accountId ?: '-') . ' — ' . $e->getMessage() . "\n");
                $switched = $this->reassignOrderingAccount($run, array_keys($tried));
                if ($switched) {
                    $run = $this->runs->get($id) ?? $run;
                    $accountId = (int) ($run['provider_account_id'] ?? 0);
                    fwrite(STDOUT, "run #{$id}: пробуем acc #{$accountId}\n");
                    continue;
                }
                throw $e;
            } catch (\Throwable $e) {
                if ($accountId > 0) {
                    $accounts->markUsed($accountId, $e->getMessage());
                }
                throw $e;
            }
        }

        if ($lastBalanceErr !== null || !isset($info)) {
            throw $lastBalanceErr ?? new \RuntimeException('create: no provider response');
        }

        $meta = null;
        if (!empty($info->raw['_wlsearch_meta']) && is_array($info->raw['_wlsearch_meta'])) {
            $meta = json_encode($info->raw['_wlsearch_meta'], JSON_UNESCAPED_UNICODE);
        }

        $stmt = $this->pdo->prepare(
            'UPDATE runs SET provider_server_id = ?, ipv4 = ?, provider_meta = ?, state = ?,
                    error_message = NULL, server_created_at = COALESCE(server_created_at, NOW()), updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([$info->id, $info->ipv4, $meta, 'PROVISIONING', $id]);
        fwrite(STDOUT, "run #{$id}: created server {$info->id} status={$info->status} ip=" . ($info->ipv4 ?: '-') . " acc=" . ($accountId ?: '-') . "\n");
        if ($info->isUnpaidOrBlocked()) {
            fwrite(STDOUT, "run #{$id}: WARNING create returned {$info->status} — see storage/logs/timeweb.log\n");
        }
    }

    /**
     * Переназначить ORDERING на другой enabled аккаунт.
     * @param list<int> $excludeIds
     */
    private function reassignOrderingAccount(array $run, array $excludeIds): bool
    {
        $id = (int) $run['id'];
        $provider = (string) $run['provider'];
        $accounts = new \Wlsearch\Provider\ProviderAccountService($this->pdo);
        $exclude = array_fill_keys(array_map('intval', $excludeIds), true);
        $pool = $accounts->listEnabled($provider);
        foreach ($pool as $row) {
            $aid = (int) $row['id'];
            if (isset($exclude[$aid])) {
                continue;
            }
            // Не брать аккаунт с живым PASS/KEEP
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM runs
                 WHERE provider_account_id = ?
                   AND state IN ('PASS','KEEP')
                   AND provider_server_id IS NOT NULL AND provider_server_id != ''
                   AND destroyed_at IS NULL"
            );
            $st->execute([$aid]);
            if ((int) $st->fetchColumn() > 0) {
                continue;
            }
            $this->pdo->prepare(
                'UPDATE runs SET provider_account_id = ?, error_message = NULL, updated_at = NOW() WHERE id = ?'
            )->execute([$aid, $id]);
            return true;
        }
        return false;
    }

    /** @param array<string, mixed> $run */
    private function handleProvisioning(array $run): void
    {
        $id = (int) $run['id'];
        $serverId = (string) ($run['provider_server_id'] ?? '');
        if ($serverId === '') {
            // ORDERING уже захвачен под create; ждём пока create допишет server_id
            $updated = strtotime((string) ($run['updated_at'] ?? '')) ?: 0;
            if ($updated > 0 && (time() - $updated) > 600) {
                $this->failRun($id, 'ERROR', 'create stalled: нет provider_server_id >10 мин');
            }
            return;
        }

        $provider = ProviderFactory::forRun($run);
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
                $run['ipv4'] = $info->ipv4;
                $run['asn'] = $asn;
                $run['asn_org'] = $asnOrg !== null ? mb_substr($asnOrg, 0, 128) : null;
                fwrite(STDOUT, "run #{$id}: known/subnet {$info->ipv4} → skip ({$known})\n");
                // /24 или точный fail_bs — сразу FAIL_BS + destroy
                if (str_starts_with($known, 'fail_bs_subnet') || str_starts_with($known, 'known_fail_bs')) {
                    $this->failBs($run, $known);
                    return;
                }
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
                $info = ProviderFactory::forRun($run)->get($serverId);
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

        $meta = [];
        if (!empty($run['provider_meta'])) {
            $decoded = json_decode((string) $run['provider_meta'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        if (empty($meta['bootstrap_at'])) {
            $fromServer = strtotime((string) ($run['server_created_at'] ?? '')) ?: 0;
            $meta['bootstrap_at'] = $fromServer > 0 ? $fromServer : time();
            $this->saveMeta($id, $meta);
        }
        $bootstrapAt = (int) $meta['bootstrap_at'];
        if ($bootstrapAt <= 0) {
            $bootstrapAt = time();
            $meta['bootstrap_at'] = $bootstrapAt;
            $this->saveMeta($id, $meta);
        }

        $attempts = 5;
        $sleepSec = 8;
        $lastErr = 'no probe';
        $mode = (string) ($run['bs_mode'] ?? 'agent');

        for ($i = 1; $i <= $attempts; $i++) {
            $age = max(0, time() - $bootstrapAt);
            $result = $this->control->check($ipv4);
            if ($result['ok']) {
                $this->pdo->prepare(
                    'UPDATE runs SET error_message = NULL WHERE id = ? AND state = \'BOOTSTRAPPING\''
                )->execute([$id]);
                $this->setState($id, 'CONTROL_CHECK');
                fwrite(STDOUT, "run #{$id}: probe responding → CONTROL_CHECK\n");
                return;
            }

            // HTTP уже отвечает — для bsbord этого достаточно
            if (in_array($mode, ['bsbord', 'both'], true)) {
                $httpOnly = $this->control->checkHttp($ipv4);
                if ($httpOnly['ok']) {
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = NULL WHERE id = ? AND state = \'BOOTSTRAPPING\''
                    )->execute([$id]);
                    $this->setState($id, 'CONTROL_CHECK');
                    fwrite(STDOUT, "run #{$id}: http probe OK (bsbord) → CONTROL_CHECK\n");
                    return;
                }
                $lastErr = (string) ($httpOnly['error'] ?? $result['error'] ?? 'no probe');
            } else {
                $lastErr = (string) ($result['error'] ?? 'no probe');
            }

            $waitMsg = sprintf(
                'ожидание probe %ds (повтор %d/%d): %s',
                $age,
                $i,
                $attempts,
                $lastErr
            );
            $this->pdo->prepare(
                'UPDATE runs SET error_message = ? WHERE id = ? AND state = \'BOOTSTRAPPING\''
            )->execute([mb_substr($waitMsg, 0, 500), $id]);
            fwrite(STDOUT, "run #{$id}: {$waitMsg}\n");

            if ($i < $attempts) {
                sleep($sleepSec);
            }
        }

        $age = max(0, time() - $bootstrapAt);
        $err = $lastErr;

        // Снаружи timeout при живом localhost → облачный Firewall Timeweb (whitelist)
        $looksBlocked = str_contains(strtolower($err), 'timed out')
            || str_contains(strtolower($err), 'timeout')
            || str_contains(strtolower($err), 'connection refused');
        if (
            $looksBlocked
            && $age >= 20
            && $serverId !== ''
            && (string) ($run['provider'] ?? '') === 'timeweb'
            && empty($meta['firewall_detach_tried'])
        ) {
            try {
                $provider = ProviderFactory::forRun($run);
                if (method_exists($provider, 'detachCloudFirewall')) {
                    /** @var \Wlsearch\Provider\TimewebProvider $provider */
                    $detached = $provider->detachCloudFirewall($serverId);
                    $meta['firewall_detach_tried'] = 1;
                    $meta['firewall_detached'] = $detached;
                    $this->saveMeta($id, $meta);
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = ? WHERE id = ?'
                    )->execute([
                        mb_substr(
                            'снял cloud firewall (' . count($detached) . ' групп), жду probe: ' . $err,
                            0,
                            500
                        ),
                        $id,
                    ]);
                    fwrite(STDOUT, "run #{$id}: detached cloud firewall groups=" . count($detached) . "\n");
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: firewall detach: " . $e->getMessage() . "\n");
            }
        }

        // Один раз: reboot если probe долго не отвечает (Yandex и др.)
        if ($age >= 90 && $serverId !== '' && empty($meta['probe_reboot'])) {
            try {
                $provider = ProviderFactory::forRun($run);
                $didReboot = false;
                // Timeweb: сначала cloud-init repush + reboot
                if (
                    (string) ($run['provider'] ?? '') === 'timeweb'
                    && empty($meta['probe_repush'])
                    && method_exists($provider, 'repushCloudInitAndReboot')
                ) {
                    $script = CloudInitBuilder::forRun((string) $run['provider'], $id);
                    /** @var \Wlsearch\Provider\TimewebProvider $provider */
                    $provider->repushCloudInitAndReboot($serverId, $script);
                    $meta['probe_repush'] = 1;
                    $meta['probe_repush_at'] = date('c');
                    $didReboot = true;
                    fwrite(STDOUT, "run #{$id}: repush cloud_init + reboot\n");
                } elseif (method_exists($provider, 'rebootInstance')) {
                    $provider->rebootInstance($serverId);
                    $didReboot = true;
                    fwrite(STDOUT, "run #{$id}: reboot after probe hang\n");
                }
                if ($didReboot) {
                    $meta['probe_reboot'] = 1;
                    $meta['probe_reboot_at'] = time();
                    // новый отсчёт ожидания после reboot
                    $meta['bootstrap_at'] = time();
                    $this->saveMeta($id, $meta);
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = ? WHERE id = ?'
                    )->execute([
                        mb_substr('reboot после таймаута probe — жду ответ снова', 0, 500),
                        $id,
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: probe reboot failed: " . $e->getMessage() . "\n");
                $meta['probe_reboot'] = 1;
                $meta['probe_reboot_error'] = mb_substr($e->getMessage(), 0, 200);
                $this->saveMeta($id, $meta);
            }
        }

        $timeout = Settings::int('BOOTSTRAP_TIMEOUT_SEC', Env::int('BOOTSTRAP_TIMEOUT_SEC', 600));
        if ($age > $timeout) {
            $run['ipv4'] = $ipv4;
            $hint = !empty($meta['probe_reboot'])
                ? 'bootstrap timeout после reboot: '
                : 'bootstrap timeout: ';
            // failControl → DESTROYING (если не keep) — очередь пойдёт дальше
            $this->failControl($run, $hint . $err);
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
        $mode = (string) ($run['bs_mode'] ?? 'agent');
        // bsbord сам бьёт http/https — control с панели достаточно по HTTP
        if (!$result['ok'] && in_array($mode, ['bsbord', 'both'], true)) {
            $result = $this->control->checkHttp($ipv4);
        }
        if ($result['ok']) {
            $stmt = $this->pdo->prepare(
                'UPDATE runs SET control_ok = 1, state = ?, error_message = NULL, updated_at = NOW() WHERE id = ?'
            );
            $stmt->execute(['BS_CHECK', $id]);
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
                        error_message = ?, tested_at = NOW(), updated_at = NOW() WHERE id = ?"
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
            $this->maybeStopBatchOnPass($run);
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
        // без updated_at — иначе age bootstrap сбрасывается и timeout/retry логика ломается
        $stmt = $this->pdo->prepare('UPDATE runs SET provider_meta = ? WHERE id = ?');
        $stmt->execute([json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
    }

    /** @param array<string, mixed> $run */
    private function failBs(array $run, string $message): void
    {
        $id = (int) $run['id'];
        $stmt = $this->pdo->prepare(
            "UPDATE runs SET bs_ok = 0, bs_source = 'bsbord', state = 'FAIL_BS', verdict = 'FAIL_BS',
                    error_message = ?, tested_at = NOW(), updated_at = NOW() WHERE id = ?"
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

        $provider = ProviderFactory::forRun($run);
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
            'UPDATE runs SET control_ok = 0, state = ?, verdict = ?, error_message = ?, tested_at = NOW(), updated_at = NOW() WHERE id = ?'
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

    /** @param array<string, mixed> $run */
    private function maybeStopBatchOnPass(array $run): void
    {
        if (!(int) ($run['stop_on_pass'] ?? 0)) {
            return;
        }
        $batchId = (string) ($run['batch_id'] ?? '');
        if ($batchId === '') {
            return;
        }
        $n = $this->runs->skipBatchRemainder($batchId, (int) $run['id'], 'остановлено: найден PASS в batch');
        if ($n > 0) {
            fwrite(STDOUT, 'batch ' . $batchId . ": SKIPPED {$n} queued run(s) after PASS\n");
            $this->tg->send("wlsearch: PASS — остановлена очередь batch {$batchId}, skipped={$n}");
        }
    }
}
