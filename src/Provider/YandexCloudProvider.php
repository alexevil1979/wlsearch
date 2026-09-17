<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use Wlsearch\Support\FileLog;
use Wlsearch\Support\HttpClient;

/**
 * Yandex Cloud Compute: VM + one-to-one NAT (публичный IPv4) + user-data (cloud-init).
 *
 * Auth: authorized key сервисного аккаунта → JWT PS256 → IAM token.
 * Docs: https://yandex.cloud/docs/compute/api-ref/Instance/create
 */
final class YandexCloudProvider implements ProviderInterface
{
    private AccountBag $cfg;
    private HttpClient $http;
    private ?string $iamToken = null;
    private int $iamExpires = 0;

    public function __construct(?AccountBag $cfg = null, ?HttpClient $http = null)
    {
        $this->cfg = $cfg ?? AccountBag::legacy('yandex');
        foreach (['YANDEX_FOLDER_ID', 'YANDEX_SUBNET_ID'] as $k) {
            if (($this->cfg->get($k) ?? '') === '') {
                throw new \RuntimeException(
                    "Missing {$k} for Yandex"
                    . ($this->cfg->accountName !== '' ? ' (' . $this->cfg->accountName . ')' : '')
                );
            }
        }
        if (($this->cfg->get('YANDEX_SA_KEY_JSON') ?? '') === ''
            && (($this->cfg->get('YANDEX_SA_ID') ?? '') === ''
                || ($this->cfg->get('YANDEX_SA_KEY_ID') ?? '') === ''
                || ($this->cfg->get('YANDEX_SA_PRIVATE_KEY') ?? '') === '')
        ) {
            throw new \RuntimeException(
                'Нужен YANDEX_SA_KEY_JSON (или SA_ID + KEY_ID + PRIVATE_KEY)'
                . ($this->cfg->accountName !== '' ? ' (' . $this->cfg->accountName . ')' : '')
            );
        }
        $this->http = $http ?? new HttpClient([
            'Content-Type: application/json',
            'Accept: application/json',
        ], 120);
    }

    public function name(): string
    {
        return 'yandex';
    }

    public function create(array $opts): ServerInfo
    {
        $folderId = $this->cfg->require('YANDEX_FOLDER_ID');
        $zone = (string) ($opts['region'] ?? $this->cfg->get('YANDEX_ZONE_ID', 'ru-central1-a'));
        if ($zone === '') {
            $zone = 'ru-central1-a';
        }
        $subnetId = $this->cfg->require('YANDEX_SUBNET_ID');
        $imageId = $this->resolveImageId();
        $cores = max(2, $this->cfg->int('YANDEX_CORES', 2));
        $memoryGb = max(1, $this->cfg->int('YANDEX_MEMORY_GB', 2));
        $diskGb = max(10, $this->cfg->int('YANDEX_DISK_GB', 15));
        $platform = $this->cfg->get('YANDEX_PLATFORM_ID', 'standard-v3') ?? 'standard-v3';
        $coreFraction = max(5, min(100, $this->cfg->int('YANDEX_CORE_FRACTION', 100)));
        $preemptible = $this->cfg->bool('YANDEX_PREEMPTIBLE', true);

        $body = [
            'folderId' => $folderId,
            'name' => $this->sanitizeName((string) $opts['name']),
            'description' => mb_substr((string) ($opts['comment'] ?? 'wlsearch'), 0, 256),
            'zoneId' => $zone,
            'platformId' => $platform,
            'resourcesSpec' => [
                'memory' => (string) ($memoryGb * 1024 * 1024 * 1024),
                'cores' => (string) $cores,
                'coreFraction' => (string) $coreFraction,
            ],
            'bootDiskSpec' => [
                'autoDelete' => true,
                'diskSpec' => [
                    'typeId' => $this->cfg->get('YANDEX_DISK_TYPE', 'network-hdd') ?? 'network-hdd',
                    'size' => (string) ($diskGb * 1024 * 1024 * 1024),
                    'imageId' => $imageId,
                ],
            ],
            'networkInterfaceSpecs' => [
                [
                    'subnetId' => $subnetId,
                    'primaryV4AddressSpec' => [
                        'oneToOneNatSpec' => [
                            'ipVersion' => 'IPV4',
                        ],
                    ],
                ],
            ],
            'metadata' => [
                // cloud-init
                'user-data' => (string) $opts['cloud_init'],
            ],
            'schedulingPolicy' => [
                'preemptible' => $preemptible,
            ],
        ];

        FileLog::write('yandex', 'create:request', [
            'folder' => $folderId,
            'zone' => $zone,
            'subnet' => $subnetId,
            'image' => $imageId,
            'cores' => $cores,
            'memory_gb' => $memoryGb,
            'preemptible' => $preemptible,
            'name' => $body['name'],
            'account' => $this->cfg->logTag(),
        ]);

        $op = $this->api('POST', 'https://compute.api.cloud.yandex.net/compute/v1/instances', $body);
        $instanceId = (string) ($op['metadata']['instanceId'] ?? '');
        $op = $this->waitOperation($op);
        if ($instanceId === '') {
            $instanceId = (string) ($op['metadata']['instanceId'] ?? $op['response']['id'] ?? '');
        }
        if ($instanceId === '') {
            throw new \RuntimeException('Yandex create: no instanceId in operation');
        }

        FileLog::write('yandex', 'create:done', [
            'instance_id' => $instanceId,
            'account' => $this->cfg->logTag(),
        ]);

        // IP может появиться через несколько секунд после RUNNING
        for ($i = 0; $i < 20; $i++) {
            $info = $this->get($instanceId);
            if ($info->ipv4) {
                return $info;
            }
            usleep(500000);
        }
        return $this->get($instanceId);
    }

    public function get(string $serverId): ServerInfo
    {
        $inst = $this->api(
            'GET',
            'https://compute.api.cloud.yandex.net/compute/v1/instances/' . rawurlencode($serverId)
        );
        return $this->mapInstance($inst);
    }

    public function list(): array
    {
        $folderId = $this->cfg->require('YANDEX_FOLDER_ID');
        $json = $this->api(
            'GET',
            'https://compute.api.cloud.yandex.net/compute/v1/instances?folderId=' . rawurlencode($folderId)
        );
        $out = [];
        foreach ($json['instances'] ?? [] as $inst) {
            if (is_array($inst)) {
                $out[] = $this->mapInstance($inst);
            }
        }
        return $out;
    }

    public function destroy(string $serverId): void
    {
        // ВАЖНО: удаляем только Compute Instance.
        // VPC Address (статический публичный IP) НИКОГДА не удаляем через API —
        // иначе сорвётся резерв после «избранная подсеть» / ручного KEEP.
        FileLog::write('yandex', 'destroy:start', [
            'instance_id' => $serverId,
            'account' => $this->cfg->logTag(),
        ]);
        try {
            $this->detachProtectedNatBeforeDestroy($serverId);
        } catch (\Throwable $e) {
            FileLog::write('yandex', 'destroy:detach_protected_warn', [
                'instance_id' => $serverId,
                'error' => $e->getMessage(),
            ]);
        }
        try {
            $op = $this->api(
                'DELETE',
                'https://compute.api.cloud.yandex.net/compute/v1/instances/' . rawurlencode($serverId)
            );
            $this->waitOperation($op);
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return;
            }
            throw $e;
        }
        FileLog::write('yandex', 'destroy:done', ['instance_id' => $serverId]);
    }

    /**
     * Если на VM ещё висит защищённый (зарезервированный) публичный IP —
     * сначала отвязать one-to-one NAT, не удаляя Address в VPC.
     */
    private function detachProtectedNatBeforeDestroy(string $serverId): void
    {
        $inst = $this->api(
            'GET',
            'https://compute.api.cloud.yandex.net/compute/v1/instances/' . rawurlencode($serverId)
        );
        $publicIp = $this->extractPublicIpv4($inst);
        if ($publicIp === null || $publicIp === '') {
            return;
        }
        $protected = new \Wlsearch\ProtectedIp\ProtectedIpService();
        if (!$protected->isProtected($publicIp)) {
            return;
        }

        $nicIndex = '0';
        $internal = null;
        foreach ($inst['networkInterfaces'] ?? [] as $i => $nic) {
            if (!is_array($nic)) {
                continue;
            }
            $nat = $nic['primaryV4Address']['oneToOneNat']['address'] ?? null;
            if (is_string($nat) && $nat === $publicIp) {
                $nicIndex = (string) ($nic['index'] ?? $i);
                $internal = $nic['primaryV4Address']['address'] ?? null;
                break;
            }
        }

        $body = ['networkInterfaceIndex' => $nicIndex];
        if (is_string($internal) && $internal !== '') {
            $body['internalAddress'] = $internal;
        }

        FileLog::write('yandex', 'destroy:detach_protected_nat', [
            'instance_id' => $serverId,
            'public_ip' => $publicIp,
            'nic' => $nicIndex,
        ]);
        $op = $this->api(
            'POST',
            'https://compute.api.cloud.yandex.net/compute/v1/instances/'
            . rawurlencode($serverId) . ':removeOneToOneNat',
            $body
        );
        $this->waitOperation($op);
    }

    /** Restart VM (probe hang recovery). */
    public function rebootInstance(string $serverId): void
    {
        FileLog::write('yandex', 'reboot:start', [
            'instance_id' => $serverId,
            'account' => $this->cfg->logTag(),
        ]);
        $op = $this->api(
            'POST',
            'https://compute.api.cloud.yandex.net/compute/v1/instances/'
            . rawurlencode($serverId) . ':restart'
        );
        $this->waitOperation($op);
        FileLog::write('yandex', 'reboot:done', ['instance_id' => $serverId]);
    }

    /**
     * Перезалить user-data (cloud-init) и restart — повторная установка probe.
     * bootcmd в #cloud-config подхватится на следующем буте.
     */
    public function repushCloudInitAndReboot(string $serverId, string $cloudInit): void
    {
        FileLog::write('yandex', 'cloud_init:repush', [
            'instance_id' => $serverId,
            'bytes' => strlen($cloudInit),
            'head' => mb_substr($cloudInit, 0, 40),
            'account' => $this->cfg->logTag(),
        ]);
        $op = $this->api(
            'POST',
            'https://compute.api.cloud.yandex.net/compute/v1/instances/'
            . rawurlencode($serverId) . '/updateMetadata',
            [
                'upsert' => [
                    'user-data' => $cloudInit,
                    'serial-port-enable' => '1',
                ],
            ]
        );
        $this->waitOperation($op);
        FileLog::write('yandex', 'cloud_init:repush_meta_done', ['instance_id' => $serverId]);
        $this->rebootInstance($serverId);
    }

    /** @param array<string, mixed> $inst */
    private function mapInstance(array $inst): ServerInfo
    {
        $id = (string) ($inst['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Yandex instance missing id');
        }
        $status = strtolower((string) ($inst['status'] ?? 'unknown'));
        // normalize for ServerInfo::isReady()
        if ($status === 'running') {
            $status = 'running';
        }
        $ipv4 = $this->extractPublicIpv4($inst);
        return new ServerInfo($id, $ipv4, $status, $inst);
    }

    /** @param array<string, mixed> $inst */
    private function extractPublicIpv4(array $inst): ?string
    {
        foreach ($inst['networkInterfaces'] ?? [] as $nic) {
            if (!is_array($nic)) {
                continue;
            }
            $nat = $nic['primaryV4Address']['oneToOneNat'] ?? null;
            if (is_array($nat) && !empty($nat['address'])
                && filter_var($nat['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
            ) {
                return (string) $nat['address'];
            }
            // fallback internal (обычно не публичный)
            $addr = $nic['primaryV4Address']['address'] ?? null;
            if (is_string($addr) && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && !preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $addr)
            ) {
                return $addr;
            }
        }
        return null;
    }

    private function resolveImageId(): string
    {
        $imageId = trim($this->cfg->get('YANDEX_IMAGE_ID', '') ?? '');
        if ($imageId !== '') {
            return $imageId;
        }
        $family = trim($this->cfg->get('YANDEX_IMAGE_FAMILY', 'ubuntu-2204-lts') ?? 'ubuntu-2204-lts');
        $folder = trim($this->cfg->get('YANDEX_IMAGE_FOLDER_ID', 'standard-images') ?? 'standard-images');
        $json = $this->api(
            'GET',
            'https://compute.api.cloud.yandex.net/compute/v1/images:latestByFamily'
            . '?folderId=' . rawurlencode($folder)
            . '&family=' . rawurlencode($family)
        );
        $id = (string) ($json['id'] ?? '');
        if ($id === '') {
            throw new \RuntimeException('Yandex: не удалось найти image family=' . $family);
        }
        return $id;
    }

    private static function isQuotaRateBody(string $body): bool
    {
        if ($body === '') {
            return false;
        }
        return str_contains($body, 'QuotaFailure')
            || str_contains($body, 'externalAddressesCreation.rate')
            || str_contains($body, 'Quota vpc.')
            || (str_contains($body, '"code": 8') && str_contains($body, 'exceeded'));
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function api(string $method, string $url, ?array $body = null): array
    {
        $this->ensureIam();
        $resp = $this->http->request($method, $url, $body, [
            'Authorization: Bearer ' . $this->iamToken,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        if ($resp['status'] === 404) {
            throw new \RuntimeException('Yandex HTTP 404: ' . mb_substr($resp['body'], 0, 400));
        }
        if ($resp['status'] === 429 || self::isQuotaRateBody($resp['body'])) {
            throw new ProviderQuotaException(
                'Yandex HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 800)
            );
        }
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException(
                'Yandex HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 800)
            );
        }
        if ($resp['body'] === '') {
            return [];
        }
        $json = json_decode($resp['body'], true);
        return is_array($json) ? $json : [];
    }

    /** @param array<string, mixed> $op */
    private function waitOperation(array $op): array
    {
        $id = (string) ($op['id'] ?? '');
        if ($id === '') {
            return $op;
        }
        $deadline = time() + 300;
        while (time() < $deadline) {
            if (!empty($op['done'])) {
                if (!empty($op['error'])) {
                    $msg = is_array($op['error'])
                        ? (($op['error']['message'] ?? '') . ' ' . json_encode($op['error'], JSON_UNESCAPED_UNICODE))
                        : (string) $op['error'];
                    if (self::isQuotaRateBody($msg)) {
                        throw new ProviderQuotaException('Yandex operation quota: ' . mb_substr($msg, 0, 800));
                    }
                    throw new \RuntimeException('Yandex operation failed: ' . $msg);
                }
                return $op;
            }
            usleep(800000);
            $op = $this->api(
                'GET',
                'https://operation.api.cloud.yandex.net/operations/' . rawurlencode($id)
            );
        }
        throw new \RuntimeException('Yandex operation timeout: ' . $id);
    }

    private function ensureIam(): void
    {
        if ($this->iamToken && time() < $this->iamExpires - 60) {
            return;
        }
        [$saId, $keyId, $privateKey] = $this->saCredentials();
        $jwt = $this->buildPs256Jwt($saId, $keyId, $privateKey);
        $resp = $this->http->request(
            'POST',
            'https://iam.api.cloud.yandex.net/iam/v1/tokens',
            ['jwt' => $jwt]
        );
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException(
                'Yandex IAM token HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 500)
            );
        }
        $json = json_decode($resp['body'], true);
        $token = is_array($json) ? (string) ($json['iamToken'] ?? '') : '';
        if ($token === '') {
            throw new \RuntimeException('Yandex IAM: empty iamToken');
        }
        $this->iamToken = $token;
        // обычно ~12ч; обновляем раньше
        $this->iamExpires = time() + 10 * 3600;
    }

    /** @return array{0:string,1:string,2:string} saId, keyId, privateKeyPem */
    private function saCredentials(): array
    {
        $rawJson = trim($this->cfg->get('YANDEX_SA_KEY_JSON', '') ?? '');
        if ($rawJson !== '') {
            $j = json_decode($rawJson, true);
            if (!is_array($j)) {
                throw new \RuntimeException('YANDEX_SA_KEY_JSON: невалидный JSON');
            }
            $saId = (string) ($j['service_account_id'] ?? '');
            $keyId = (string) ($j['id'] ?? '');
            $pk = (string) ($j['private_key'] ?? '');
            if ($saId === '' || $keyId === '' || $pk === '') {
                throw new \RuntimeException('YANDEX_SA_KEY_JSON: нужны service_account_id, id, private_key');
            }
            return [$saId, $keyId, $this->normalizePem($pk)];
        }
        return [
            $this->cfg->require('YANDEX_SA_ID'),
            $this->cfg->require('YANDEX_SA_KEY_ID'),
            $this->normalizePem($this->cfg->require('YANDEX_SA_PRIVATE_KEY')),
        ];
    }

    private function normalizePem(string $pem): string
    {
        $pem = str_replace(["\r\n", "\r"], "\n", $pem);
        // из JSON часто приходит с \n как двумя символами
        if (!str_contains($pem, "\n") && str_contains($pem, '\\n')) {
            $pem = str_replace('\\n', "\n", $pem);
        }
        return trim($pem) . "\n";
    }

    private function buildPs256Jwt(string $saId, string $keyId, string $privateKeyPem): string
    {
        $header = ['typ' => 'JWT', 'alg' => 'PS256', 'kid' => $keyId];
        $now = time();
        $payload = [
            'iss' => $saId,
            'aud' => 'https://iam.api.cloud.yandex.net/iam/v1/tokens',
            'iat' => $now,
            'exp' => $now + 3600,
        ];
        $h = $this->b64url(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}');
        $p = $this->b64url(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
        $signing = $h . '.' . $p;
        $sig = $this->signPs256($signing, $privateKeyPem);
        return $signing . '.' . $this->b64url($sig);
    }

    private function signPs256(string $data, string $privateKeyPem): string
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'ycpk');
        if ($keyFile === false) {
            throw new \RuntimeException('tempnam failed');
        }
        try {
            file_put_contents($keyFile, $privateKeyPem);
            $cmd = 'openssl dgst -sha256 -sigopt rsa_padding_mode:pss -sigopt rsa_pss_saltlen:-1 -sign '
                . escapeshellarg($keyFile);
            $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $desc, $pipes);
            if (!is_resource($proc)) {
                throw new \RuntimeException('openssl proc_open failed (нужен openssl CLI для PS256)');
            }
            fwrite($pipes[0], $data);
            fclose($pipes[0]);
            $sig = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0 || $sig === false || $sig === '') {
                throw new \RuntimeException('openssl PS256 sign failed: ' . trim((string) $err));
            }
            return $sig;
        } finally {
            @unlink($keyFile);
        }
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function sanitizeName(string $name): string
    {
        $name = strtolower($name);
        $name = preg_replace('/[^a-z0-9\-]/', '-', $name) ?? 'wlsearch';
        $name = trim($name, '-');
        if ($name === '') {
            $name = 'wlsearch';
        }
        return mb_substr($name, 0, 63);
    }
}
