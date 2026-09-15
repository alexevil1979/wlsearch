<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\HttpClient;

/**
 * Selectel OpenStack (Nova + Keystone + optional Neutron floating IP).
 * Credentials/config from AccountBag (multi-account) or legacy .env.
 */
final class SelectelProvider implements ProviderInterface
{
    private HttpClient $http;
    private AccountBag $cfg;
    private ?string $token = null;
    private ?string $computeUrl = null;
    private ?string $networkUrl = null;
    private int $tokenExpires = 0;

    public function __construct(?AccountBag $cfg = null, ?HttpClient $http = null)
    {
        $this->cfg = $cfg ?? AccountBag::legacy('selectel');
        foreach (['SELECTEL_AUTH_URL', 'SELECTEL_USERNAME', 'SELECTEL_PASSWORD', 'SELECTEL_FLAVOR_ID', 'SELECTEL_IMAGE_ID', 'SELECTEL_NETWORK_ID'] as $k) {
            if (($this->cfg->get($k) ?? '') === '') {
                throw new \RuntimeException("Missing {$k} for Selectel" . ($this->cfg->accountName !== '' ? ' (' . $this->cfg->accountName . ')' : ''));
            }
        }
        $this->http = $http ?? new HttpClient([
            'Content-Type: application/json',
            'Accept: application/json',
        ], 90);
    }

    public function name(): string
    {
        return 'selectel';
    }

    public function create(array $opts): ServerInfo
    {
        $this->ensureAuth();
        $userData = base64_encode($opts['cloud_init']);

        $body = [
            'server' => [
                'name' => $opts['name'],
                'flavorRef' => $this->cfg->require('SELECTEL_FLAVOR_ID'),
                'imageRef' => $this->cfg->require('SELECTEL_IMAGE_ID'),
                'networks' => [
                    ['uuid' => $this->cfg->require('SELECTEL_NETWORK_ID')],
                ],
                'user_data' => $userData,
                'config_drive' => true,
            ],
        ];

        $az = $opts['region'] ?? $this->cfg->get('SELECTEL_REGION');
        if ($az !== null && $az !== '') {
            $body['server']['availability_zone'] = $az;
        }

        $resp = $this->nova('POST', '/servers', $body);
        $server = $resp['server'] ?? null;
        if (!is_array($server) || empty($server['id'])) {
            throw new \RuntimeException('Selectel create: no server id in response');
        }

        $id = (string) $server['id'];
        $meta = [];

        $extNet = $this->cfg->get('SELECTEL_EXTERNAL_NET_ID', '') ?? '';
        if ($extNet !== '') {
            sleep(3);
            $portId = $this->findServerPort($id);
            if ($portId) {
                $fip = $this->createFloatingIp($extNet, $portId);
                $meta['floating_ip_id'] = $fip['id'] ?? null;
                $meta['floating_ip'] = $fip['floating_ip_address'] ?? null;
            }
        }

        $info = $this->get($id);
        if ($meta !== []) {
            $raw = $info->raw;
            $raw['_wlsearch_meta'] = $meta;
            return new ServerInfo($info->id, $meta['floating_ip'] ?? $info->ipv4, $info->status, $raw);
        }

        return $info;
    }

    public function get(string $serverId): ServerInfo
    {
        $this->ensureAuth();
        $resp = $this->nova('GET', '/servers/' . rawurlencode($serverId));
        $server = $resp['server'] ?? [];
        if (!is_array($server) || empty($server['id'])) {
            throw new \RuntimeException('Selectel get: invalid server');
        }

        $status = strtolower((string) ($server['status'] ?? 'unknown'));
        $ipv4 = $this->extractIpv4($server);

        return new ServerInfo((string) $server['id'], $ipv4, $status, $server);
    }

    public function list(): array
    {
        $this->ensureAuth();
        $resp = $this->nova('GET', '/servers/detail');
        $out = [];
        foreach ($resp['servers'] ?? [] as $server) {
            if (!is_array($server)) {
                continue;
            }
            $status = strtolower((string) ($server['status'] ?? 'unknown'));
            $out[] = new ServerInfo((string) $server['id'], $this->extractIpv4($server), $status, $server);
        }
        return $out;
    }

    public function destroy(string $serverId): void
    {
        $this->ensureAuth();

        try {
            $portId = $this->findServerPort($serverId);
            if ($portId && $this->networkUrl) {
                $fips = $this->neutron('GET', '/v2.0/floatingips?port_id=' . rawurlencode($portId));
                foreach ($fips['floatingips'] ?? [] as $fip) {
                    if (!empty($fip['id'])) {
                        $this->neutron('DELETE', '/v2.0/floatingips/' . rawurlencode((string) $fip['id']));
                    }
                }
            }
        } catch (\Throwable) {
        }

        $url = rtrim((string) $this->computeUrl, '/') . '/servers/' . rawurlencode($serverId);
        $resp = $this->http->request('DELETE', $url, null, [
            'X-Auth-Token: ' . $this->token,
        ]);
        if ($resp['status'] === 404) {
            return;
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Selectel destroy failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
    }

    /** @param array<string, mixed>|null $body */
    private function nova(string $method, string $path, ?array $body = null): array
    {
        $url = rtrim((string) $this->computeUrl, '/') . $path;
        $resp = $this->http->request($method, $url, $body, [
            'X-Auth-Token: ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Selectel Nova HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
        if ($resp['body'] === '') {
            return [];
        }
        $json = json_decode($resp['body'], true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed>|null $body */
    private function neutron(string $method, string $path, ?array $body = null): array
    {
        if (!$this->networkUrl) {
            throw new \RuntimeException('Selectel network endpoint not in catalog');
        }
        $base = preg_replace('#/v2\.0/?$#', '', rtrim($this->networkUrl, '/'));
        $url = $base . $path;
        $resp = $this->http->request($method, $url, $body, [
            'X-Auth-Token: ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($method === 'DELETE' && ($resp['status'] === 204 || $resp['status'] === 404)) {
            return [];
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Selectel Neutron HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }
        if ($resp['body'] === '') {
            return [];
        }
        $json = json_decode($resp['body'], true);
        return is_array($json) ? $json : [];
    }

    private function ensureAuth(): void
    {
        if ($this->token && time() < $this->tokenExpires - 60) {
            return;
        }

        $authUrl = rtrim($this->cfg->require('SELECTEL_AUTH_URL'), '/');
        if (!str_ends_with($authUrl, '/auth/tokens')) {
            $authUrl .= '/auth/tokens';
        }

        $userDomain = $this->cfg->get('SELECTEL_USER_DOMAIN_NAME', 'Default') ?? 'Default';
        $projectDomain = $this->cfg->get('SELECTEL_PROJECT_DOMAIN_NAME', 'Default') ?? 'Default';
        $projectId = $this->cfg->get('SELECTEL_PROJECT_ID', '') ?? '';
        $projectName = $this->cfg->get('SELECTEL_PROJECT_NAME', '') ?? '';

        $scope = $projectId !== ''
            ? ['project' => ['id' => $projectId]]
            : ['project' => ['name' => $projectName, 'domain' => ['name' => $projectDomain]]];

        if ($projectId === '' && $projectName === '') {
            throw new \RuntimeException('Set SELECTEL_PROJECT_ID or SELECTEL_PROJECT_NAME');
        }

        $payload = [
            'auth' => [
                'identity' => [
                    'methods' => ['password'],
                    'password' => [
                        'user' => [
                            'name' => $this->cfg->require('SELECTEL_USERNAME'),
                            'domain' => ['name' => $userDomain],
                            'password' => $this->cfg->require('SELECTEL_PASSWORD'),
                        ],
                    ],
                ],
                'scope' => $scope,
            ],
        ];

        $resp = $this->http->request('POST', $authUrl, $payload, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('Selectel Keystone auth failed HTTP ' . $resp['status'] . ': ' . $resp['body']);
        }

        $token = $resp['headers']['x-subject-token'] ?? '';
        if ($token === '') {
            throw new \RuntimeException('Selectel auth: missing X-Subject-Token');
        }
        $this->token = $token;

        $json = json_decode($resp['body'], true);
        $expires = $json['token']['expires_at'] ?? null;
        $this->tokenExpires = $expires ? (strtotime((string) $expires) ?: time() + 3600) : time() + 3600;

        $region = $this->cfg->get('SELECTEL_REGION', '') ?? '';
        $this->computeUrl = null;
        $this->networkUrl = null;

        foreach ($json['token']['catalog'] ?? [] as $svc) {
            $type = (string) ($svc['type'] ?? '');
            foreach ($svc['endpoints'] ?? [] as $ep) {
                $iface = (string) ($ep['interface'] ?? '');
                if ($iface !== 'public') {
                    continue;
                }
                $epRegion = (string) ($ep['region'] ?? $ep['region_id'] ?? '');
                if ($type === 'compute' && ($this->computeUrl === null || ($region && str_contains($epRegion, $region)))) {
                    $this->computeUrl = rtrim((string) $ep['url'], '/');
                }
                if ($type === 'network' && ($this->networkUrl === null || ($region && str_contains($epRegion, $region)))) {
                    $this->networkUrl = rtrim((string) $ep['url'], '/');
                }
            }
        }

        if (!$this->computeUrl) {
            throw new \RuntimeException('Selectel: compute endpoint not found in catalog');
        }
    }

    private function findServerPort(string $serverId): ?string
    {
        if (!$this->networkUrl) {
            return null;
        }
        $ports = $this->neutron('GET', '/v2.0/ports?device_id=' . rawurlencode($serverId));
        foreach ($ports['ports'] ?? [] as $port) {
            if (!empty($port['id'])) {
                return (string) $port['id'];
            }
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function createFloatingIp(string $extNetId, string $portId): array
    {
        $resp = $this->neutron('POST', '/v2.0/floatingips', [
            'floatingip' => [
                'floating_network_id' => $extNetId,
                'port_id' => $portId,
            ],
        ]);
        return is_array($resp['floatingip'] ?? null) ? $resp['floatingip'] : [];
    }

    /** @param array<string, mixed> $server */
    private function extractIpv4(array $server): ?string
    {
        if (!empty($server['accessIPv4']) && filter_var($server['accessIPv4'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (string) $server['accessIPv4'];
        }

        $addresses = $server['addresses'] ?? [];
        if (!is_array($addresses)) {
            return null;
        }

        $private = null;
        foreach ($addresses as $nets) {
            if (!is_array($nets)) {
                continue;
            }
            foreach ($nets as $addr) {
                if (!is_array($addr)) {
                    continue;
                }
                $ip = (string) ($addr['addr'] ?? '');
                $type = strtolower((string) ($addr['OS-EXT-IPS:type'] ?? $addr['type'] ?? ''));
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    continue;
                }
                if ($type === 'floating' || $type === 'fixed' && $this->looksPublic($ip)) {
                    if ($type === 'floating') {
                        return $ip;
                    }
                }
                if ($this->looksPublic($ip)) {
                    return $ip;
                }
                $private = $private ?? $ip;
            }
        }

        return $private;
    }

    private function looksPublic(string $ip): bool
    {
        return !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|127\.)/', $ip);
    }
}
