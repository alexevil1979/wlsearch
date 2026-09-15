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

        if ($this->ensureIpv4()) {
            $floatingId = Env::get('TIMEWEB_FLOATING_IP_ID', '') ?? '';
            if ($floatingId === '') {
                $floatingId = $this->createFloatingIp((string) ($zone ?? Env::get('TIMEWEB_AVAILABILITY_ZONE', 'spb-3')));
            }
            if ($floatingId !== '') {
                $body['network'] = ['floating_ip' => $floatingId];
            }
        }

        $resp = $this->http->request('POST', $this->base . '/servers', $body);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb create failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb create: invalid JSON');
        }

        return $this->mapServer($json['server'] ?? $json);
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
        $resp = $this->http->request('DELETE', $this->base . '/servers/' . rawurlencode($serverId));
        if ($resp['status'] === 404) {
            return;
        }
        if ($resp['status'] === 423) {
            throw new \RuntimeException('Timeweb destroy blocked (SMS confirmation required on account)');
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb destroy failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
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

    private function createFloatingIp(string $zone): string
    {
        $resp = $this->http->request('POST', $this->base . '/floating-ips', [
            'availability_zone' => $zone,
            'is_ddos_guard' => false,
            'comment' => 'wlsearch',
        ]);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Timeweb floating IP create failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('Timeweb floating IP: invalid JSON');
        }
        $fip = $json['ip'] ?? $json['floating_ip'] ?? $json;
        if (is_array($fip)) {
            $id = (string) ($fip['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
        throw new \RuntimeException('Timeweb floating IP: missing id in response');
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

        $fipId = $this->createFloatingIp($zone);
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
        try {
            $resp = $this->http->request('GET', $this->base . '/floating-ips');
            if ($resp['status'] < 200 || $resp['status'] >= 300) {
                return false;
            }
            $json = json_decode($resp['body'], true);
            $list = $json['ips'] ?? $json['floating_ips'] ?? $json;
            if (!is_array($list)) {
                return false;
            }
            foreach ($list as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rid = (string) ($row['resource_id'] ?? '');
                $rtype = strtolower((string) ($row['resource_type'] ?? ''));
                if ($rid !== '' && $rid === $serverId && ($rtype === '' || $rtype === 'server')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            return false;
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
