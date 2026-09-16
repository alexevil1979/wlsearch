<?php

declare(strict_types=1);

namespace Wlsearch\Worker;

use PDO;
use Wlsearch\Blacklist\BlacklistService;
use Wlsearch\CheckedIp\CheckedIpService;
use Wlsearch\FavoriteSubnet\FavoriteSubnetService;
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
use Wlsearch\Support\FileLog;
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
    private FavoriteSubnetService $favorites;
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
        ?FavoriteSubnetService $favorites = null,
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
        $this->favorites = $favorites ?? new FavoriteSubnetService($this->pdo);
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

            $fav = $this->favorites->matchIp($info->ipv4);
            if ($fav !== null) {
                fwrite(STDOUT, "run #{$id}: FAVORITE subnet {$fav['cidr']} ip={$info->ipv4} → STOP\n");
                $this->runs->hitFavoriteSubnet(
                    $id,
                    $info->ipv4,
                    $fav,
                    $asn,
                    $asnOrg !== null ? mb_substr($asnOrg, 0, 128) : null
                );
                return;
            }

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

        // Возраст только по PHP time() — MySQL NOW() и PHP часто в разных TZ (якорь «в будущем» → age=0)
        $now = time();
        $wall = isset($meta['bootstrap_wall_start']) ? (int) $meta['bootstrap_wall_start'] : 0;
        if ($wall <= 0 || $wall > $now + 30) {
            // уже были неудачные probe — не сбрасываем прогресс в 0
            $wall = !empty($meta['last_probe']) || !empty($meta['bootstrap_fail_ticks'])
                ? $now - 120
                : $now;
            $meta['bootstrap_wall_start'] = $wall;
        }
        $age = max(0, $now - $wall);
        $meta['bootstrap_fail_ticks'] = (int) ($meta['bootstrap_fail_ticks'] ?? 0);
        $this->saveMeta($id, $meta);

        $rebootAt = isset($meta['probe_reboot_at']) ? (int) $meta['probe_reboot_at'] : 0;
        $ageSinceReboot = $rebootAt > 0 ? max(0, $now - $rebootAt) : null;

        $timeout = Settings::int('BOOTSTRAP_TIMEOUT_SEC', Env::int('BOOTSTRAP_TIMEOUT_SEC', 600));
        $rebootAfterSec = 60;
        $rebootAfterTicks = 2; // ~2 тика worker без probe → reboot (независимо от TZ)
        $waitAfterRebootSec = 90; // 1.5 мин пауза → ping → reinstall bootstrap
        $waitAfterReinstallSec = min(300, max(120, (int) ($timeout / 2)));

        $reinstallAt = isset($meta['probe_reinstall_at']) ? (int) $meta['probe_reinstall_at'] : 0;
        $ageSinceReinstall = $reinstallAt > 0 ? max(0, $now - $reinstallAt) : null;

        $dbServerTs = strtotime((string) ($run['server_created_at'] ?? '')) ?: 0;
        $clockSkew = $dbServerTs > 0 && $dbServerTs > $now + 60;

        fwrite(STDOUT, sprintf(
            "run #%d: bs5 age=%ds fail_ticks=%d skew=%s server_created_at=%s php_now=%s reboot=%s reinstall=%s os_reinstall=%s serverId=%s\n",
            $id,
            $age,
            (int) $meta['bootstrap_fail_ticks'],
            $clockSkew ? '1' : '0',
            (string) ($run['server_created_at'] ?? '-'),
            date('Y-m-d H:i:s', $now),
            !empty($meta['probe_reboot']) ? '1' : '0',
            !empty($meta['probe_reinstall']) ? '1' : '0',
            !empty($meta['probe_os_reinstall']) ? '1' : '0',
            $serverId !== '' ? $serverId : '-'
        ));
        FileLog::write('probe', 'bootstrap:tick', [
            'run_id' => $id,
            'code' => 'bs5',
            'ipv4' => $ipv4,
            'age_s' => $age,
            'fail_ticks' => (int) $meta['bootstrap_fail_ticks'],
            'clock_skew' => $clockSkew,
            'server_created_at' => (string) ($run['server_created_at'] ?? ''),
            'php_now' => date('c', $now),
            'probe_reboot' => !empty($meta['probe_reboot']),
            'probe_reinstall' => !empty($meta['probe_reinstall']),
            'probe_os_reinstall' => !empty($meta['probe_os_reinstall']),
            'server_id' => $serverId,
        ]);

        $shouldReboot = $serverId !== ''
            && empty($meta['probe_reboot'])
            && ($age >= $rebootAfterSec || (int) $meta['bootstrap_fail_ticks'] >= $rebootAfterTicks);

        // Hang → сразу переустановка bootstrap + reboot (не голый reboot:
        // старый #!/bin/sh user-data после reboot сам не повторяется)
        if ($shouldReboot) {
            try {
                $provider = ProviderFactory::forRun($run);
                $provName = (string) ($run['provider'] ?? 'unknown');
                $did = false;
                if (method_exists($provider, 'repushCloudInitAndReboot')) {
                    $script = CloudInitBuilder::forRerun($provName, $id);
                    $provider->repushCloudInitAndReboot($serverId, $script);
                    $meta['probe_repush'] = 1;
                    $meta['probe_reinstall'] = 1;
                    $meta['probe_reinstall_at'] = time();
                    $did = true;
                    fwrite(STDOUT, "run #{$id}: reinstall bootstrap + reboot (age={$age}s)\n");
                } elseif (method_exists($provider, 'rebootInstance')) {
                    $provider->rebootInstance($serverId);
                    $did = true;
                    fwrite(STDOUT, "run #{$id}: reboot after probe hang (age={$age}s)\n");
                }
                if ($did) {
                    $meta['probe_reboot'] = 1;
                    $meta['probe_reboot_at'] = time();
                    $meta['bootstrap_fail_ticks'] = 0;
                    $this->saveMeta($id, $meta);
                    $hint = !empty($meta['probe_reinstall'])
                        ? "reinstall+reboot — пауза {$waitAfterRebootSec}s → ping → жду probe"
                        : "reboot — пауза {$waitAfterRebootSec}s → ping → bootstrap";
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = ? WHERE id = ?'
                    )->execute([
                        mb_substr("bs5 {$hint} (age={$age}s)", 0, 500),
                        $id,
                    ]);
                    FileLog::write('probe', 'bootstrap:reboot', [
                        'run_id' => $id,
                        'ipv4' => $ipv4,
                        'server_id' => $serverId,
                        'age_s' => $age,
                        'fail_ticks' => (int) $meta['bootstrap_fail_ticks'],
                        'provider' => $provName,
                        'clock_skew' => $clockSkew,
                        'reinstall' => !empty($meta['probe_reinstall']),
                    ]);
                    return;
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: probe reboot/reinstall failed: " . $e->getMessage() . "\n");
                $meta['probe_reboot'] = 1;
                $meta['probe_reboot_at'] = time();
                $meta['probe_reboot_error'] = mb_substr($e->getMessage(), 0, 200);
                $this->saveMeta($id, $meta);
            }
        }

        // После reboot: 1.5 мин → ping в админку; если reinstall ещё не было — делаем
        if (
            $serverId !== ''
            && !empty($meta['probe_reboot'])
            && $ageSinceReboot !== null
            && $ageSinceReboot >= $waitAfterRebootSec
            && empty($meta['ping_after_reboot'])
        ) {
            $ping = $this->pingHost($ipv4);
            $meta['last_ping'] = $ping;
            $meta['ping_after_reboot'] = 1;
            $this->saveMeta($id, $meta);
            FileLog::write('probe', 'bootstrap:ping', [
                'run_id' => $id,
                'ipv4' => $ipv4,
                'ping' => $ping,
                'age_since_reboot_s' => $ageSinceReboot,
            ]);
            fwrite(STDOUT, "run #{$id}: ping after reboot: {$ping['summary']}\n");

            if (empty($meta['probe_reinstall'])) {
                try {
                    $provider = ProviderFactory::forRun($run);
                    if (method_exists($provider, 'repushCloudInitAndReboot')) {
                        $provName = (string) ($run['provider'] ?? 'unknown');
                        $script = CloudInitBuilder::forRerun($provName, $id);
                        $provider->repushCloudInitAndReboot($serverId, $script);
                        $meta['probe_reinstall'] = 1;
                        $meta['probe_reinstall_at'] = time();
                        $meta['probe_repush'] = 1;
                        $meta['bootstrap_fail_ticks'] = 0;
                        $this->saveMeta($id, $meta);
                        $msg = "ping: {$ping['summary']} → reinstall bootstrap, жду probe ~{$waitAfterReinstallSec}s";
                        $this->pdo->prepare(
                            'UPDATE runs SET error_message = ? WHERE id = ?'
                        )->execute([mb_substr($msg, 0, 500), $id]);
                        FileLog::write('probe', 'bootstrap:reinstall', [
                            'run_id' => $id,
                            'ipv4' => $ipv4,
                            'server_id' => $serverId,
                            'provider' => $provName,
                            'ping' => $ping,
                        ]);
                        fwrite(STDOUT, "run #{$id}: {$msg}\n");
                        return;
                    }
                    $meta['probe_reinstall'] = 1;
                    $meta['probe_reinstall_at'] = time();
                    $meta['probe_reinstall_skip'] = 'no_repush_method';
                    $this->saveMeta($id, $meta);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "run #{$id}: bootstrap reinstall failed: " . $e->getMessage() . "\n");
                    $meta['probe_reinstall'] = 1;
                    $meta['probe_reinstall_at'] = time();
                    $meta['probe_reinstall_error'] = mb_substr($e->getMessage(), 0, 200);
                    $this->saveMeta($id, $meta);
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = ? WHERE id = ?'
                    )->execute([
                        mb_substr(
                            "ping: {$ping['summary']}; reinstall fail: " . $e->getMessage(),
                            0,
                            500
                        ),
                        $id,
                    ]);
                    return;
                }
            } else {
                $this->pdo->prepare(
                    'UPDATE runs SET error_message = ? WHERE id = ?'
                )->execute([
                    mb_substr(
                        "ping: {$ping['summary']} — bootstrap уже переустановлен, жду probe",
                        0,
                        500
                    ),
                    $id,
                ]);
            }
        }

        // После reinstall скрипта всё ещё тишина — одна переустановка ОС/VM, иначе destroy
        if ($ageSinceReinstall !== null && $ageSinceReinstall > $waitAfterReinstallSec) {
            $run['ipv4'] = $ipv4;
            $pingSum = is_array($meta['last_ping'] ?? null)
                ? (string) ($meta['last_ping']['summary'] ?? '')
                : '';
            $reason = "bootstrap timeout после reinstall ({$ageSinceReinstall}s)"
                . ($pingSum !== '' ? "; ping {$pingSum}" : '')
                . ': probe не отвечает';
            if (empty($meta['probe_os_reinstall'])) {
                FileLog::write('probe', 'bootstrap:os_reinstall', [
                    'run_id' => $id,
                    'ipv4' => $ipv4,
                    'age_s' => $age,
                    'age_since_reinstall_s' => $ageSinceReinstall,
                    'last_ping' => $meta['last_ping'] ?? null,
                ]);
                $this->reprovisionOsAfterBootstrapFail($run, $meta, $reason);
                return;
            }
            FileLog::write('probe', 'bootstrap:timeout_after_os_reinstall', [
                'run_id' => $id,
                'ipv4' => $ipv4,
                'age_s' => $age,
                'age_since_reinstall_s' => $ageSinceReinstall,
                'last_ping' => $meta['last_ping'] ?? null,
            ]);
            $this->failControl($run, $reason . ' (после переустановки ОС)');
            return;
        }

        // Общий лимит без reboot (если reboot недоступен)
        if (empty($meta['probe_reboot']) && $age > $timeout) {
            $run['ipv4'] = $ipv4;
            $this->failControl($run, 'bootstrap timeout: probe не отвечает ' . $age . 's');
            return;
        }

        $attempts = 3;
        $sleepSec = 4;
        $lastErr = 'no probe';
        $mode = (string) ($run['bs_mode'] ?? 'agent');
        $checker = $this->control->withLogContext([
            'run_id' => $id,
            'ipv4' => $ipv4,
            'provider' => (string) ($run['provider'] ?? ''),
            'phase' => 'BOOTSTRAPPING',
        ]);

        for ($i = 1; $i <= $attempts; $i++) {
            $ageNow = max(0, time() - $wall);
            $result = $checker->withLogContext([
                'run_id' => $id,
                'ipv4' => $ipv4,
                'provider' => (string) ($run['provider'] ?? ''),
                'phase' => 'BOOTSTRAPPING',
                'attempt' => $i,
                'age_s' => $ageNow,
                'reboot' => !empty($meta['probe_reboot']),
            ])->check($ipv4);
            if ($result['ok']) {
                $this->pdo->prepare(
                    'UPDATE runs SET error_message = NULL WHERE id = ? AND state = \'BOOTSTRAPPING\''
                )->execute([$id]);
                $this->setState($id, 'CONTROL_CHECK');
                FileLog::write('probe', 'bootstrap:ok', ['run_id' => $id, 'ipv4' => $ipv4, 'attempt' => $i, 'age_s' => $ageNow]);
                fwrite(STDOUT, "run #{$id}: probe responding → CONTROL_CHECK\n");
                return;
            }

            if (in_array($mode, ['bsbord', 'both'], true)) {
                $httpOnly = $checker->withLogContext([
                    'run_id' => $id,
                    'ipv4' => $ipv4,
                    'provider' => (string) ($run['provider'] ?? ''),
                    'phase' => 'BOOTSTRAPPING_HTTP',
                    'attempt' => $i,
                    'age_s' => $ageNow,
                ])->checkHttp($ipv4);
                if ($httpOnly['ok']) {
                    $this->pdo->prepare(
                        'UPDATE runs SET error_message = NULL WHERE id = ? AND state = \'BOOTSTRAPPING\''
                    )->execute([$id]);
                    $this->setState($id, 'CONTROL_CHECK');
                    FileLog::write('probe', 'bootstrap:http_ok', ['run_id' => $id, 'ipv4' => $ipv4, 'attempt' => $i]);
                    fwrite(STDOUT, "run #{$id}: http probe OK (bsbord) → CONTROL_CHECK\n");
                    return;
                }
                $lastErr = (string) ($httpOnly['error'] ?? $result['error'] ?? 'no probe');
                $lastDebug = $httpOnly['debug'] ?? ($result['debug'] ?? []);
            } else {
                $lastErr = (string) ($result['error'] ?? 'no probe');
                $lastDebug = $result['debug'] ?? [];
            }

            $meta['last_probe'] = [
                'at' => date('c'),
                'attempt' => $i,
                'age_s' => $ageNow,
                'age_since_reboot_s' => $ageSinceReboot,
                'error' => $lastErr,
                'debug' => $lastDebug,
            ];
            $this->saveMeta($id, $meta);

            $phase = !empty($meta['probe_reinstall'])
                ? ('после reinstall ' . (int) ($ageSinceReinstall ?? 0) . 's')
                : (!empty($meta['probe_reboot'])
                    ? ('после reboot ' . (int) ($ageSinceReboot ?? 0) . 's')
                    : ($ageNow . 's'));
            $waitMsg = sprintf(
                'bs5 ожидание probe %s (повтор %d/%d): %s',
                $phase,
                $i,
                $attempts,
                $lastErr
            );
            $this->pdo->prepare(
                'UPDATE runs SET error_message = ? WHERE id = ? AND state = \'BOOTSTRAPPING\''
            )->execute([mb_substr($waitMsg, 0, 500), $id]);
            FileLog::write('probe', 'bootstrap:wait', [
                'run_id' => $id,
                'ipv4' => $ipv4,
                'msg' => $waitMsg,
                'age_s' => $ageNow,
                'debug' => $lastDebug,
            ]);
            fwrite(STDOUT, "run #{$id}: {$waitMsg}\n");

            if ($i < $attempts) {
                sleep($sleepSec);
            }
        }

        // тик без успеха
        $meta['bootstrap_fail_ticks'] = (int) ($meta['bootstrap_fail_ticks'] ?? 0) + 1;
        $this->saveMeta($id, $meta);
        $age = max(0, time() - $wall);
        $err = $lastErr;
        $ageSinceReboot = $rebootAt > 0 ? max(0, time() - $rebootAt) : $ageSinceReboot;

        // Timeweb firewall detach
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

        // после этого тика уже пора reinstall+reboot
        if (
            $serverId !== ''
            && empty($meta['probe_reboot'])
            && ((int) $meta['bootstrap_fail_ticks'] >= $rebootAfterTicks || $age >= $rebootAfterSec)
        ) {
            try {
                $provider = ProviderFactory::forRun($run);
                $provName = (string) ($run['provider'] ?? 'unknown');
                if (method_exists($provider, 'repushCloudInitAndReboot')) {
                    $provider->repushCloudInitAndReboot(
                        $serverId,
                        CloudInitBuilder::forRerun($provName, $id)
                    );
                    $meta['probe_reinstall'] = 1;
                    $meta['probe_reinstall_at'] = time();
                    $meta['probe_repush'] = 1;
                } elseif (method_exists($provider, 'rebootInstance')) {
                    $provider->rebootInstance($serverId);
                } else {
                    throw new \RuntimeException('no reboot/repush method');
                }
                $meta['probe_reboot'] = 1;
                $meta['probe_reboot_at'] = time();
                $meta['bootstrap_fail_ticks'] = 0;
                $this->saveMeta($id, $meta);
                $this->pdo->prepare(
                    'UPDATE runs SET error_message = ? WHERE id = ?'
                )->execute([
                    mb_substr(
                        "bs5 reinstall+reboot — пауза {$waitAfterRebootSec}s → ping → probe",
                        0,
                        500
                    ),
                    $id,
                ]);
                FileLog::write('probe', 'bootstrap:reboot', [
                    'run_id' => $id,
                    'ipv4' => $ipv4,
                    'age_s' => $age,
                    'fail_ticks' => (int) $meta['bootstrap_fail_ticks'],
                    'reinstall' => !empty($meta['probe_reinstall']),
                ]);
                fwrite(STDOUT, "run #{$id}: reinstall+reboot at end of tick\n");
                return;
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: late reboot failed: " . $e->getMessage() . "\n");
            }
        }

        if ($ageSinceReinstall !== null && $ageSinceReinstall > $waitAfterReinstallSec) {
            $run['ipv4'] = $ipv4;
            $reason = "bootstrap timeout после reinstall: {$err}";
            if (empty($meta['probe_os_reinstall'])) {
                $this->reprovisionOsAfterBootstrapFail($run, $meta, $reason);
                return;
            }
            $this->failControl($run, $reason . ' (после переустановки ОС)');
        }
    }

    /**
     * Одна попытка: удалить текущую VM и заново создать (переустановка системы + повторный прогон).
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $meta
     */
    private function reprovisionOsAfterBootstrapFail(array $run, array $meta, string $reason): void
    {
        $id = (int) $run['id'];
        $serverId = (string) ($run['provider_server_id'] ?? '');
        $ipv4 = (string) ($run['ipv4'] ?? '');

        FileLog::write('probe', 'bootstrap:os_reinstall_start', [
            'run_id' => $id,
            'ipv4' => $ipv4,
            'server_id' => $serverId,
            'reason' => $reason,
            'provider' => (string) ($run['provider'] ?? ''),
        ]);

        if ($serverId !== '') {
            try {
                $provider = ProviderFactory::forRun($run);
                $provider->destroy($serverId);
                fwrite(STDOUT, "run #{$id}: destroyed {$serverId} for OS reinstall\n");
                FileLog::write('probe', 'bootstrap:os_reinstall_destroyed', [
                    'run_id' => $id,
                    'server_id' => $serverId,
                ]);
            } catch (\Throwable $e) {
                fwrite(STDERR, "run #{$id}: OS reinstall destroy failed: " . $e->getMessage() . "\n");
                FileLog::write('probe', 'bootstrap:os_reinstall_destroy_error', [
                    'run_id' => $id,
                    'server_id' => $serverId,
                    'error' => $e->getMessage(),
                ]);
                // всё равно идём на recreate — иначе застрянем
            }
        }

        $newMeta = [
            'probe_os_reinstall' => 1,
            'probe_os_reinstall_at' => time(),
            'probe_os_reinstall_reason' => mb_substr($reason, 0, 300),
            'probe_os_reinstall_prev_ip' => $ipv4 !== '' ? $ipv4 : null,
            'probe_os_reinstall_prev_server' => $serverId !== '' ? $serverId : null,
        ];
        $this->saveMeta($id, $newMeta);

        $this->pdo->prepare(
            "UPDATE runs SET
                provider_server_id = NULL,
                ipv4 = NULL,
                asn = NULL,
                asn_org = NULL,
                server_created_at = NULL,
                destroyed_at = NULL,
                control_ok = NULL,
                state = 'ORDERING',
                verdict = NULL,
                error_message = ?,
                updated_at = NOW()
             WHERE id = ?"
        )->execute([
            mb_substr('переустановка ОС/VM после неудачного bootstrap — создаю новый сервер', 0, 500),
            $id,
        ]);

        $this->tg->send(
            "wlsearch: переустановка ОС run #{$id}"
            . ($ipv4 !== '' ? " ip={$ipv4}" : '')
            . " — старый сервер удалён, повторный create"
        );
        fwrite(STDOUT, "run #{$id}: OS reinstall → ORDERING (повторный create)\n");
    }

    /**
     * ICMP ping + TCP:80 для статуса в админке после reboot.
     *
     * @return array{
     *   ok: bool,
     *   icmp_ok: bool,
     *   tcp80_ok: bool,
     *   loss_pct: int|null,
     *   tcp_ms: int,
     *   summary: string,
     *   raw: string,
     *   at: string
     * }
     */
    private function pingHost(string $ipv4): array
    {
        $ipv4 = trim($ipv4);
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [
                'ok' => false,
                'icmp_ok' => false,
                'tcp80_ok' => false,
                'loss_pct' => null,
                'tcp_ms' => 0,
                'summary' => 'invalid ip',
                'raw' => '',
                'at' => date('c'),
            ];
        }

        $out = [];
        $code = 1;
        $cmd = 'ping -c 3 -W 2 ' . escapeshellarg($ipv4) . ' 2>&1';
        @exec($cmd, $out, $code);
        $raw = implode("\n", $out);
        $loss = null;
        if (preg_match('/(\d+)%\s*packet\s*loss/i', $raw, $m)) {
            $loss = (int) $m[1];
        }
        $icmpOk = $code === 0 && ($loss === null || $loss < 100);

        $tcpOk = false;
        $t0 = microtime(true);
        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($ipv4, 80, $errno, $errstr, 3.0);
        if (is_resource($fp)) {
            $tcpOk = true;
            fclose($fp);
        }
        $tcpMs = (int) round((microtime(true) - $t0) * 1000);

        $summary = sprintf(
            'icmp=%s loss=%s tcp80=%s %dms',
            $icmpOk ? 'ok' : 'fail',
            $loss === null ? '?' : ($loss . '%'),
            $tcpOk ? 'open' : ('closed/' . $errno),
            $tcpMs
        );

        return [
            'ok' => $icmpOk || $tcpOk,
            'icmp_ok' => $icmpOk,
            'tcp80_ok' => $tcpOk,
            'loss_pct' => $loss,
            'tcp_ms' => $tcpMs,
            'summary' => $summary,
            'raw' => mb_substr($raw, 0, 800),
            'at' => date('c'),
        ];
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
