<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\Env;
use Wlsearch\Support\HttpClient;

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
        $presetId = Env::int('TIMEWEB_PRESET_ID', 0);
        $osId = Env::int('TIMEWEB_OS_ID', 0);
        if ($presetId <= 0 || $osId <= 0) {
            throw new \RuntimeException('TIMEWEB_PRESET_ID and TIMEWEB_OS_ID must be set in .env');
        }

        $body = [
            'name' => $opts['name'],
            'preset_id' => $presetId,
            'os_id' => $osId,
            'is_ddos_guard' => false,
            'is_local_network' => false,
            'bandwidth' => Env::int('TIMEWEB_BANDWIDTH', 200),
            'cloud_init' => $opts['cloud_init'],
        ];

        if (!empty($opts['comment'])) {
            $body['comment'] = mb_substr((string) $opts['comment'], 0, 255);
        }

        $zone = $opts['region'] ?? Env::get('TIMEWEB_AVAILABILITY_ZONE');
        if ($zone !== null && $zone !== '') {
            $body['availability_zone'] = $zone;
        }

        $projectId = Env::int('TIMEWEB_PROJECT_ID', 0);
        if ($projectId > 0) {
            $body['project_id'] = $projectId;
        }

        $floatingIdUsed = null;

        if ($this->ensureIpv4()) {
            $az = (string) ($zone ?? Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3') ?? 'spb-3');
            $fip = $this->resolveFloatingIp($az);
            if ($fip !== null) {
                // Timeweb validates network.floating_ip as dotted IPv4, not UUID
                $body['network'] = ['floating_ip' => $fip['ip']];
                $floatingIdUsed = $fip['id'];
            }
        }

        $resp = $this->http->request('POST', $this->base . '/servers', $body);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            $hint = $resp['status'] === 402
                ? ' (недостаточно средств Timeweb: для создания API требует запас ≈30 дней тарифа, списания потом почасовые)'
                : '';
            throw new \RuntimeException('Timeweb create failed HTTP ' . $resp['status'] . $hint . ': ' . $resp['body']);
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb create: invalid JSON');
        }

        $info = $this->mapServer($json['server'] ?? $json);
        if ($floatingIdUsed !== null) {
            $raw = $info->raw;
            $raw['_wlsearch_meta'] = array_merge(
                is_array($raw['_wlsearch_meta'] ?? null) ? $raw['_wlsearch_meta'] : [],
                ['floating_ip_id' => $floatingIdUsed]
            );
            return new ServerInfo($info->id, $info->ipv4, $info->status, $raw);
        }

        return $info;
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
            $this->attachIpv4IfMissing($serverId, $zone);
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
        if (!Env::bool('TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY', true)) {
            return;
        }
        $pinned = Env::get('TIMEWEB_FLOATING_IP_ID', '') ?? '';
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
        return Env::bool('TIMEWEB_ENSURE_IPV4', true);
    }

    /** @param array<string, mixed> $server */
    private function zoneFromServer(array $server): string
    {
        $zone = (string) ($server['availability_zone'] ?? $server['location'] ?? '');
        if ($zone !== '') {
            return $zone;
        }
        return Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3') ?? 'spb-3';
    }

    /**
     * @return array{id: string, ip: string}|null
     */
    private function resolveFloatingIp(string $zone): ?array
    {
        $pinned = trim(Env::get('TIMEWEB_FLOATING_IP_ID', '') ?? '');
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
        if (in_array($s, ['installing', 'turning_on', 'creating', 'unknown', ''], true)) {
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
