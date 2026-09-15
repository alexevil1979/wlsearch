<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\Env;
use Wlsearch\Support\FileLog;
use Wlsearch\Support\HttpClient;
use Wlsearch\Support\Settings;

final class TimewebProvider implements ProviderInterface
{
    private HttpClient $http;
    private string $base;

    public function __construct(?HttpClient $http = null)
    {
        $token = Env::get('TIMEWEB_API_TOKEN', '');
        if ($token === null || $token === '') {
            throw new \RuntimeException('TIMEWEB_API_TOKEN is not configured');
        }
        $this->base = rtrim(Env::get('TIMEWEB_API_BASE', 'https://api.timeweb.cloud/api/v1') ?? '', '/');
        $this->http = $http ?? new HttpClient([
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
    }

    public function name(): string
    {
        return 'timeweb';
    }

    public function create(array $opts): ServerInfo
    {
        $osId = Settings::int('TIMEWEB_OS_ID', Env::int('TIMEWEB_OS_ID', 99));
        if ($osId <= 0) {
            throw new \RuntimeException('TIMEWEB_OS_ID must be set (Настройки / .env)');
        }

        $financesBefore = $this->fetchFinances();
        FileLog::write('timeweb', 'create:start', [
            'finances' => $financesBefore,
            'opts_name' => $opts['name'] ?? null,
        ]);

        $body = [
            'name' => $opts['name'],
            'os_id' => $osId,
            'is_ddos_guard' => false,
            'is_local_network' => false,
            'bandwidth' => Settings::int('TIMEWEB_BANDWIDTH', Env::int('TIMEWEB_BANDWIDTH', 200)),
            'cloud_init' => $opts['cloud_init'],
        ];

        $presetId = Settings::int('TIMEWEB_PRESET_ID', Env::int('TIMEWEB_PRESET_ID', 0));
        $configuratorId = Settings::int('TIMEWEB_CONFIGURATOR_ID', Env::int('TIMEWEB_CONFIGURATOR_ID', 0));

        if ($presetId > 0) {
            $body['preset_id'] = $presetId;
        } elseif ($configuratorId > 0) {
            $ramGb = max(1, Settings::int('TIMEWEB_RAM_GB', Env::int('TIMEWEB_RAM_GB', 1)));
            $diskGb = max(1, Settings::int('TIMEWEB_DISK_GB', Env::int('TIMEWEB_DISK_GB', 15)));
            $body['configuration'] = [
                'configurator_id' => $configuratorId,
                'cpu' => max(1, Settings::int('TIMEWEB_CPU', Env::int('TIMEWEB_CPU', 1))),
                'gpu' => max(0, Settings::int('TIMEWEB_GPU', Env::int('TIMEWEB_GPU', 0))),
                'ram' => $ramGb * 1024,
                'disk' => $diskGb * 1024,
            ];
        } else {
            throw new \RuntimeException(
                'Задайте TIMEWEB_PRESET_ID в Настройках (или TIMEWEB_CONFIGURATOR_ID как запасной вариант)'
            );
        }

        if (!empty($opts['comment'])) {
            $body['comment'] = mb_substr((string) $opts['comment'], 0, 255);
        }

        $zone = $opts['region']
            ?? Settings::get('TIMEWEB_AVAILABILITY_ZONE', Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3'));
        if ($zone !== null && $zone !== '') {
            $body['availability_zone'] = $zone;
        }

        $projectId = Settings::int('TIMEWEB_PROJECT_ID', Env::int('TIMEWEB_PROJECT_ID', 0));
        if ($projectId > 0) {
            $body['project_id'] = $projectId;
        }

        // IPv4 attach AFTER server is paid/on — ordering FIP first often leaves VPS in no_paid
        // even when balance looks “enough” (30d reserve = VPS + IP + current burn).
        $attachIpv4After = $this->ensureIpv4();

        $logBody = $body;
        unset($logBody['cloud_init']); // huge
        FileLog::write('timeweb', 'create:request', $logBody);

        $shortage = $this->explainBalanceRisk($financesBefore, $attachIpv4After);
        if ($shortage !== null) {
            FileLog::write('timeweb', 'create:balance_warn', ['warn' => $shortage, 'finances' => $financesBefore]);
        }

        $resp = $this->http->request('POST', $this->base . '/servers', $body);
        FileLog::write('timeweb', 'create:response', [
            'http' => $resp['status'],
            'body' => mb_substr($resp['body'], 0, 4000),
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            $hint = $resp['status'] === 402
                ? ' (недостаточно средств Timeweb: для создания API требует запас ≈30 дней тарифа+IP)'
                : '';
            if ($shortage !== null) {
                $hint .= ' | ' . $shortage;
            }
            throw new \RuntimeException('Timeweb create failed HTTP ' . $resp['status'] . $hint . ': ' . $resp['body']);
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb create: invalid JSON');
        }

        $info = $this->mapServer($json['server'] ?? $json);
        $meta = ['_wlsearch_meta' => [
            'attach_ipv4_after' => $attachIpv4After ? 1 : 0,
            'finances_before' => $financesBefore,
            'create_status' => $info->status,
        ]];

        if ($info->isUnpaidOrBlocked()) {
            $finAfter = $this->fetchFinances();
            FileLog::write('timeweb', 'create:no_paid', [
                'server_id' => $info->id,
                'status' => $info->status,
                'finances_before' => $financesBefore,
                'finances_after' => $finAfter,
                'hint' => $shortage,
            ]);
            // Не бросаем исключение: VPS уже создан — worker сделает destroy.
            $meta['_wlsearch_meta']['no_paid'] = 1;
            $meta['_wlsearch_meta']['no_paid_hint'] = $shortage
                ?? 'API вернул no_paid; отдельной активации оплаты нет';
        }

        $raw = array_merge($info->raw, $meta);
        return new ServerInfo($info->id, $info->ipv4, $info->status, $raw);
    }

    public function get(string $serverId): ServerInfo
    {
        $resp = $this->http->request('GET', $this->base . '/servers/' . rawurlencode($serverId));
        if ($resp['status'] === 404) {
            throw new \RuntimeException('Timeweb server not found: ' . $serverId);
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb get failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb get: invalid JSON');
        }
        $info = $this->mapServer($json['server'] ?? $json);

        // Fallback: dedicated IPs endpoint if networks empty while installing
        if ($info->ipv4 === null || $info->ipv4 === '') {
            $ip = $this->fetchIpv4FromIpsEndpoint($serverId);
            if ($ip !== null) {
                return new ServerInfo($info->id, $ip, $info->status, $info->raw);
            }
        }

        if (($info->ipv4 === null || $info->ipv4 === '') && $this->ensureIpv4() && $this->shouldOrderIpv4($info->status)) {
            $zone = $this->zoneFromServer($info->raw);
            FileLog::write('timeweb', 'attach_ipv4:start', ['server_id' => $serverId, 'zone' => $zone, 'status' => $info->status]);
            try {
                $this->attachIpv4IfMissing($serverId, $zone);
            } catch (\Throwable $e) {
                FileLog::write('timeweb', 'attach_ipv4:error', ['server_id' => $serverId, 'error' => $e->getMessage()]);
                throw $e;
            }
            $ip = $this->fetchIpv4FromIpsEndpoint($serverId);
            if ($ip !== null) {
                return new ServerInfo($info->id, $ip, $info->status, $info->raw);
            }
            $retry = $this->http->request('GET', $this->base . '/servers/' . rawurlencode($serverId));
            if ($retry['status'] >= 200 && $retry['status'] < 300) {
                $json = json_decode($retry['body'], true);
                if (is_array($json)) {
                    return $this->mapServer($json['server'] ?? $json);
                }
            }
        }

        return $info;
    }

    public function list(): array
    {
        $resp = $this->http->request('GET', $this->base . '/servers');
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb list failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
        $json = json_decode($resp['body'], true);
        $servers = $json['servers'] ?? [];
        $out = [];
        foreach ($servers as $s) {
            if (is_array($s)) {
                $out[] = $this->mapServer($s);
            }
        }
        return $out;
    }

    public function destroy(string $serverId): void
    {
        $floatingIds = $this->floatingIpIdsForServer($serverId);

        $resp = $this->http->request('DELETE', $this->base . '/servers/' . rawurlencode($serverId));
        if ($resp['status'] === 404) {
            $this->maybeDeleteFloatingIps($floatingIds);
            return;
        }
        if ($resp['status'] === 423) {
            throw new \RuntimeException('Timeweb destroy blocked (SMS confirmation required on account)');
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb destroy failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }

        $this->maybeDeleteFloatingIps($floatingIds);
    }

    /** @return list<string> */
    private function floatingIpIdsForServer(string $serverId): array
    {
        $ids = [];
        foreach ($this->listFloatingIps() as $row) {
            $rid = (string) ($row['resource_id'] ?? '');
            $rtype = strtolower((string) ($row['resource_type'] ?? ''));
            if ($rid === $serverId && ($rtype === '' || $rtype === 'server')) {
                $id = (string) ($row['id'] ?? '');
                if ($id !== '') {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
    }

    /** @param list<string> $ids */
    private function maybeDeleteFloatingIps(array $ids): void
    {
        // Default ON: stop hourly charges for IPv4 after VPS destroy (probe workflow).
        // Set TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY=0 to keep and reuse free IPs.
        if (!Settings::bool('TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY', Env::bool('TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY', true))) {
            return;
        }
        $pinned = trim(Settings::get('TIMEWEB_FLOATING_IP_ID', Env::get('TIMEWEB_FLOATING_IP_ID', '') ?? '') ?? '');
        foreach ($ids as $id) {
            if ($pinned !== '' && $id === $pinned) {
                continue; // never delete explicitly pinned IP
            }
            try {
                $this->http->request('DELETE', $this->base . '/floating-ips/' . rawurlencode($id));
            } catch (\Throwable) {
                // best-effort
            }
        }
    }

    /** @param array<string, mixed> $server */
    private function mapServer(array $server): ServerInfo
    {
        $id = (string) ($server['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Timeweb response missing server id');
        }
        $status = (string) ($server['status'] ?? $server['state'] ?? 'unknown');
        $ipv4 = $this->extractIpv4($server);

        return new ServerInfo($id, $ipv4, $status, $server);
    }

    /** @param array<string, mixed> $server */
    private function extractIpv4(array $server): ?string
    {
        foreach (['main_ipv4', 'public_ip', 'ip', 'ipv4'] as $k) {
            if (!empty($server[$k]) && filter_var($server[$k], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return (string) $server[$k];
            }
        }

        $networks = $server['networks'] ?? $server['network'] ?? [];
        if ($networks instanceof \stdClass) {
            $networks = (array) $networks;
        }
        if (isset($networks['ips']) && is_array($networks['ips'])) {
            $networks = [$networks];
        }
        // associative map of networks
        if (is_array($networks) && $networks !== [] && !array_is_list($networks)) {
            $networks = array_values($networks);
        }
        if (!is_array($networks)) {
            return null;
        }

        $candidates = [];
        foreach ($networks as $net) {
            if (!is_array($net)) {
                continue;
            }
            $netType = strtolower((string) ($net['type'] ?? ''));
            $ips = $net['ips'] ?? [];
            if (!is_array($ips)) {
                continue;
            }
            foreach ($ips as $ipRow) {
                if (is_string($ipRow) && filter_var($ipRow, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $candidates[] = ['ip' => $ipRow, 'score' => 10];
                    continue;
                }
                if (!is_array($ipRow)) {
                    continue;
                }
                $ip = (string) ($ipRow['ip'] ?? $ipRow['address'] ?? '');
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                $type = strtolower((string) ($ipRow['type'] ?? ''));
                $score = 1;
                if (!empty($ipRow['is_main'])) {
                    $score += 50;
                }
                // Timeweb uses type=ipv4 (not "public")
                if ($type === 'ipv4' || $type === 'public' || str_contains($type, 'float')) {
                    $score += 20;
                }
                if ($netType === 'public' || $netType === '') {
                    $score += 10;
                }
                if ($netType === 'local' || $netType === 'private') {
                    $score -= 30;
                }
                // prefer non-RFC1918
                if (!preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $ip)) {
                    $score += 15;
                }
                $candidates[] = ['ip' => $ip, 'score' => $score];
            }
        }

        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn ($a, $b) => $b['score'] <=> $a['score']);
        return $candidates[0]['ip'];
    }

    private function fetchIpv4FromIpsEndpoint(string $serverId): ?string
    {
        foreach ($this->fetchIpsList($serverId) as $row) {
            $ip = (string) ($row['ip'] ?? '');
            $type = strtolower((string) ($row['type'] ?? 'ipv4'));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && ($type === '' || $type === 'ipv4' || str_contains($type, 'public'))
            ) {
                return $ip;
            }
        }
        return null;
    }

    private function ensureIpv4(): bool
    {
        return Settings::bool('TIMEWEB_ENSURE_IPV4', Env::bool('TIMEWEB_ENSURE_IPV4', true));
    }

    /**
     * @return array{balance:?float,monthly_fee:?float,hourly_fee:?float,hours_left:mixed,currency:?string,raw?:array}
     */
    public function fetchFinances(): array
    {
        try {
            $resp = $this->http->request('GET', $this->base . '/account/finances');
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return ['balance' => null, 'monthly_fee' => null, 'hourly_fee' => null, 'hours_left' => null, 'currency' => null];
            }
            $json = json_decode($resp['body'], true);
            $f = is_array($json['finances'] ?? null) ? $json['finances'] : (is_array($json) ? $json : []);
            return [
                'balance' => isset($f['balance']) ? (float) $f['balance'] : null,
                'monthly_fee' => isset($f['monthly_fee']) ? (float) $f['monthly_fee'] : (isset($f['monthly_cost']) ? (float) $f['monthly_cost'] : null),
                'hourly_fee' => isset($f['hourly_fee']) ? (float) $f['hourly_fee'] : (isset($f['hourly_cost']) ? (float) $f['hourly_cost'] : null),
                'hours_left' => $f['hours_left'] ?? null,
                'currency' => isset($f['currency']) ? (string) $f['currency'] : 'RUB',
                'raw' => $f,
            ];
        } catch (\Throwable) {
            return ['balance' => null, 'monthly_fee' => null, 'hourly_fee' => null, 'hours_left' => null, 'currency' => null];
        }
    }

    /**
     * Heuristic: Timeweb create needs ~30 days of (current burn + new VPS [+ IP]).
     */
    private function explainBalanceRisk(array $fin, bool $withIpv4): ?string
    {
        $balance = $fin['balance'] ?? null;
        $monthly = $fin['monthly_fee'] ?? null;
        if ($balance === null) {
            return null;
        }
        // Rough new service cost (admin estimate or defaults)
        $vpsEst = (float) Settings::int('TIMEWEB_PRESET_COST_RUB', Env::int('TIMEWEB_PRESET_COST_RUB', 0));
        if ($vpsEst <= 0) {
            $vpsEst = 700.0; // typical cheap cloud VPS/month ballpark
        }
        $ipEst = $withIpv4 ? 180.0 : 0.0;
        $need = ($monthly ?? 0.0) + $vpsEst + $ipEst;
        if ($balance + 0.01 >= $need) {
            // still warn if hours_left is tiny
            $hours = $fin['hours_left'];
            if ($hours !== null && is_numeric($hours) && (float) $hours < 24) {
                return sprintf(
                    'hours_left=%.1f при balance=%.2f — Timeweb может создать VPS как no_paid',
                    (float) $hours,
                    $balance
                );
            }
            return null;
        }
        return sprintf(
            'Риск no_paid: balance=%.2f ₽ < оценка запаса ≈%.0f ₽ (текущий monthly_fee=%.0f + VPS~%.0f + IP~%.0f). В ЛК «денег хватает» на часы, но create смотрит запас ~30 дней.',
            $balance,
            $need,
            (float) ($monthly ?? 0),
            $vpsEst,
            $ipEst
        );
    }

    /** @param array<string, mixed> $server */
    private function zoneFromServer(array $server): string
    {
        $zone = (string) ($server['availability_zone'] ?? $server['location'] ?? '');
        if ($zone !== '') {
            return $zone;
        }
        return Settings::get('TIMEWEB_AVAILABILITY_ZONE', Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3') ?? 'spb-3') ?? 'spb-3';
    }

    /**
     * @return array{id: string, ip: string}|null
     */
    private function resolveFloatingIp(string $zone): ?array
    {
        $pinned = trim(Settings::get('TIMEWEB_FLOATING_IP_ID', Env::get('TIMEWEB_FLOATING_IP_ID', '') ?? '') ?? '');
        if ($pinned !== '') {
            $fromPin = $this->floatingIpByPin($pinned, $zone);
            if ($fromPin !== null) {
                return $fromPin;
            }
        }

        return $this->findFreeFloatingIp($zone) ?? $this->createFloatingIp($zone);
    }

    /**
     * @return array{id: string, ip: string}|null
     */
    private function floatingIpByPin(string $pin, string $zone): ?array
    {
        if (filter_var($pin, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach ($this->listFloatingIps() as $row) {
                $parsed = $this->parseFloatingIpRow($row);
                if ($parsed !== null && $parsed['ip'] === $pin) {
                    return $parsed;
                }
            }
            return ['id' => $pin, 'ip' => $pin];
        }

        foreach ($this->listFloatingIps() as $row) {
            $parsed = $this->parseFloatingIpRow($row);
            if ($parsed !== null && $parsed['id'] === $pin) {
                return $parsed;
            }
        }

        $got = $this->getFloatingIp($pin);
        return $got;
    }

    /**
     * @return array{id: string, ip: string}|null
     */
    private function findFreeFloatingIp(string $zone): ?array
    {
        foreach ($this->listFloatingIps() as $row) {
            $rid = $row['resource_id'] ?? null;
            $rtype = $row['resource_type'] ?? null;
            $bound = ($rid !== null && $rid !== '' && $rid !== 0)
                || ($rtype !== null && $rtype !== '');
            if ($bound) {
                continue;
            }
            $az = (string) ($row['availability_zone'] ?? '');
            if ($az !== '' && $az !== $zone) {
                continue;
            }
            $parsed = $this->parseFloatingIpRow($row);
            if ($parsed !== null) {
                return $parsed;
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: string, ip: string}|null
     */
    private function parseFloatingIpRow(array $row): ?array
    {
        $id = (string) ($row['id'] ?? '');
        $ip = (string) ($row['ip'] ?? $row['address'] ?? '');
        if ($id === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        return ['id' => $id, 'ip' => $ip];
    }

    /** @return array{id: string, ip: string}|null */
    private function getFloatingIp(string $id): ?array
    {
        try {
            $resp = $this->http->request('GET', $this->base . '/floating-ips/' . rawurlencode($id));
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return null;
            }
            $json = json_decode($resp['body'], true);
            if (!is_array($json)) {
                return null;
            }
            $row = $json['ip'] ?? $json['floating_ip'] ?? $json;
            return is_array($row) ? $this->parseFloatingIpRow($row) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<array<string, mixed>> */
    private function listFloatingIps(): array
    {
        try {
            $resp = $this->http->request('GET', $this->base . '/floating-ips');
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return [];
            }
            $json = json_decode($resp['body'], true);
            $list = $json['ips'] ?? $json['floating_ips'] ?? $json;
            if (!is_array($list)) {
                return [];
            }
            $out = [];
            foreach ($list as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{id: string, ip: string} */
    private function createFloatingIp(string $zone): array
    {
        $resp = $this->http->request('POST', $this->base . '/floating-ips', [
            'availability_zone' => $zone,
            'is_ddos_guard' => false,
            'comment' => 'wlsearch',
        ]);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            $hint = $resp['status'] === 402
                ? ' (недостаточно средств Timeweb на новый IPv4)'
                : '';
            throw new \RuntimeException(
                'Timeweb floating IP create failed HTTP ' . $resp['status'] . $hint . ': ' . $resp['body']
            );
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb floating IP: invalid JSON');
        }
        $fip = $json['ip'] ?? $json['floating_ip'] ?? $json;
        $parsed = is_array($fip) ? $this->parseFloatingIpRow($fip) : null;
        if ($parsed !== null) {
            return $parsed;
        }
        $id = is_array($fip) ? (string) ($fip['id'] ?? '') : '';
        if ($id !== '') {
            $got = $this->getFloatingIp($id);
            if ($got !== null) {
                return $got;
            }
        }
        throw new \RuntimeException('Timeweb floating IP: missing ipv4 in response');
    }

    private function shouldOrderIpv4(string $status): bool
    {
        $s = strtolower(trim($status));
        if (in_array($s, ['installing', 'turning_on', 'creating', 'unknown', '', 'no_paid', 'blocked', 'permanent_blocked', 'configuring'], true)) {
            return false;
        }
        return true;
    }

    private function attachIpv4IfMissing(string $serverId, string $zone): void
    {
        if ($this->fetchIpv4FromIpsEndpoint($serverId) !== null) {
            return;
        }
        if ($this->hasFloatingIpForServer($serverId)) {
            return;
        }

        $resp = $this->http->request(
            'POST',
            $this->base . '/servers/' . rawurlencode($serverId) . '/ips',
            ['type' => 'ipv4']
        );
        if ($resp['status'] >= 200 && $resp['status'] < 300) {
            return;
        }

        $fip = $this->findFreeFloatingIp($zone) ?? $this->createFloatingIp($zone);
        $fipId = $fip['id'];
        $bind = $this->http->request(
            'POST',
            $this->base . '/floating-ips/' . rawurlencode($fipId) . '/bind',
            [
                'resource_type' => 'server',
                'resource_id' => is_numeric($serverId) ? (int) $serverId : $serverId,
            ]
        );
        if ($bind['status'] < 200 || $bind['status'] >= 300) {
            throw new \RuntimeException(
                'Timeweb bind floating IP failed HTTP ' . $bind['status'] . ': ' . $bind['body']
            );
        }
    }

    private function hasFloatingIpForServer(string $serverId): bool
    {
        foreach ($this->listFloatingIps() as $row) {
            $rid = (string) ($row['resource_id'] ?? '');
            $rtype = strtolower((string) ($row['resource_type'] ?? ''));
            if ($rid !== '' && $rid === $serverId && ($rtype === '' || $rtype === 'server')) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array<string, mixed>> */
    private function fetchIpsList(string $serverId): array
    {
        try {
            $resp = $this->http->request('GET', $this->base . '/servers/' . rawurlencode($serverId) . '/ips');
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return [];
            }
            $json = json_decode($resp['body'], true);
            $ips = $json['server_ips'] ?? $json['ips'] ?? $json;
            if (!is_array($ips)) {
                return [];
            }
            $out = [];
            foreach ($ips as $row) {
                if (is_array($row)) {
                    $out[] = $row;
                }
            }
            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
