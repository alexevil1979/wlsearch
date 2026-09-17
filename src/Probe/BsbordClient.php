<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

use Wlsearch\Support\Env;
use Wlsearch\Support\FileLog;
use Wlsearch\Support\HttpClient;
use Wlsearch\Support\Settings;

/**
 * bsbord.com API — проверка через мобильные каналы.
 * «БС» в UI = dpi=on; «без БС» = dpi=off. Только dpi=on.
 *
 * PASS = зелёная точка TCP в «МОИ ПРОВЕРКИ» на цель = голый IP (не http://),
 * канал БС. Раньше слали http://IP/ и читали leg.ok/global_ok → ложный PASS,
 * пока ручная проверка IP в UI красная.
 */
final class BsbordClient
{
    private HttpClient $http;
    private string $base;
    private string $token;

    public function __construct(?HttpClient $http = null)
    {
        $this->base = rtrim(
            Settings::get('BSBORD_API_BASE', Env::get('BSBORD_API_BASE', 'https://bsbord.com/v1')) ?? 'https://bsbord.com/v1',
            '/'
        );
        $this->token = (string) (Settings::get('BSBORD_API_TOKEN', Env::get('BSBORD_API_TOKEN', '')) ?? '');
        $this->http = $http ?? new HttpClient([], 120);
    }

    public function isConfigured(): bool
    {
        return $this->token !== '';
    }

    /**
     * @return list<array{
     *   op_key: string,
     *   operator: string,
     *   name: string,
     *   region: string,
     *   region_code: string,
     *   dpi: string,
     *   channel_state: string,
     *   probeable: bool
     * }>
     */
    public function listOperators(string $dpi = 'on'): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('BSBORD_API_TOKEN не задан');
        }

        $url = $this->base . '/operators?dpi=' . rawurlencode($dpi) . '&probeable=true';
        $resp = $this->http->request('GET', $url, null, [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ]);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('bsbord operators HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 400));
        }
        $json = json_decode($resp['body'], true);
        $units = is_array($json['units'] ?? null) ? $json['units'] : [];
        $out = [];
        foreach ($units as $u) {
            if (!is_array($u) || empty($u['op_key'])) {
                continue;
            }
            $out[] = [
                'op_key' => (string) $u['op_key'],
                'operator' => (string) ($u['operator'] ?? ''),
                'name' => (string) ($u['name'] ?? $u['operator'] ?? ''),
                'region' => (string) ($u['region'] ?? ''),
                'region_code' => strtolower((string) ($u['region_code'] ?? '')),
                'dpi' => strtolower((string) ($u['dpi'] ?? $dpi)),
                'channel_state' => (string) ($u['channel_state'] ?? ''),
                'probeable' => !empty($u['probeable']),
            ];
        }
        return $out;
    }

    /**
     * PASS только по TCP с голого IP (как строки в UI «МОИ ПРОВЕРКИ»).
     * Дополнительно бьём http:// для лога — на ok не влияет.
     *
     * @return array{
     *   ok: bool,
     *   operators: list<string>,
     *   marker_ok: bool,
     *   detail: string,
     *   raw: array<string, mixed>
     * }
     */
    public function probeIpv4(string $ipv4, string $marker = 'WL_PROBE_OK'): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('BSBORD_API_TOKEN не задан');
        }
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new \InvalidArgumentException('invalid ipv4');
        }

        $operators = $this->resolveOperatorKeys();
        if ($operators === []) {
            throw new \RuntimeException('Нет операторов БС (dpi=on) для проверки — выберите в Настройках');
        }

        // Как в UI: цель = IP, пробы TCP (порт 80) — зелёная/красная точка
        $tcpRes = $this->probeTarget(
            $ipv4,
            80,
            $operators,
            $ipv4,
            $marker,
            ['icmp' => false, 'tcp' => true, 'http' => false]
        );

        // Для лога / маркера — не решает PASS
        $httpRes = $this->probeTarget(
            'http://' . $ipv4 . '/',
            80,
            $operators,
            $ipv4,
            $marker,
            ['icmp' => false, 'tcp' => true, 'http' => true]
        );

        $ok = $tcpRes['ok'];
        $ops = $tcpRes['operators'];
        $detail = sprintf(
            'tcp-ip[%s] http-url[%s]',
            $tcpRes['detail'],
            $httpRes['detail']
        );

        FileLog::write('bsbord', $ok ? 'probe:pass' : 'probe:fail', [
            'ipv4' => $ipv4,
            'operators_req' => $operators,
            'ops_pass' => $ops,
            'detail' => $detail,
        ]);

        return [
            'ok' => $ok,
            'operators' => $ops,
            'marker_ok' => $httpRes['marker_ok'],
            'detail' => ($ok ? 'bsbord БС PASS ' : 'bsbord БС FAIL ') . $detail,
            'raw' => [
                'tcp_ip' => $tcpRes['raw'],
                'http_url' => $httpRes['raw'],
            ],
        ];
    }

    /**
     * @param list<string> $operators
     * @param array{icmp:bool,tcp:bool,http:bool} $probes
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function probeTarget(
        string $target,
        int $tcpPort,
        array $operators,
        string $ipv4,
        string $marker,
        array $probes
    ): array {
        $body = [
            'target' => $target,
            'dpi' => 'on',
            'operators' => $operators,
            'probes' => $probes,
            'tcp_port' => $tcpPort,
        ];

        $idem = bin2hex(random_bytes(16));
        $resp = $this->http->request('POST', $this->base . '/probe', $body, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Idempotency-Key: ' . $idem,
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException(
                'bsbord HTTP ' . $resp['status'] . ' (' . $target . '): ' . mb_substr($resp['body'], 0, 500)
            );
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('bsbord: invalid JSON for ' . $target);
        }

        $wantHttp = !empty($probes['http']);
        $out = $this->interpret($json, $ipv4, $marker, $target, $operators, $wantHttp);
        $out['detail'] = $tcpPort . ':' . $out['detail'];
        return $out;
    }

    /**
     * Resolve BSBORD_OPERATORS setting into full op_keys (dpi=on only).
     * Empty setting → default ЦФО: megafon, mts, beeline with dpi=on.
     *
     * @return list<string>
     */
    public function resolveOperatorKeys(): array
    {
        $raw = trim((string) (Settings::get('BSBORD_OPERATORS', Env::get('BSBORD_OPERATORS', '')) ?? ''));
        $units = $this->listOperators('on');

        if ($raw === '') {
            $wanted = ['megafon', 'mts', 'beeline'];
            $keys = [];
            foreach ($units as $u) {
                $op = strtolower($u['operator']);
                $rc = $u['region_code'];
                if (in_array($op, $wanted, true) && ($rc === 'cfo' || str_contains(mb_strtolower($u['region']), 'цфо') || str_contains(mb_strtolower($u['region']), 'моск'))) {
                    $keys[] = $u['op_key'];
                }
            }
            if ($keys === []) {
                foreach ($units as $u) {
                    if (in_array(strtolower($u['operator']), $wanted, true)) {
                        $keys[] = $u['op_key'];
                    }
                }
            }
            return array_values(array_unique($keys));
        }

        $parts = array_filter(array_map('trim', explode(',', $raw)));
        $keys = [];
        foreach ($parts as $part) {
            if (str_contains($part, '|')) {
                if (str_ends_with(strtolower($part), '|on')) {
                    $keys[] = $part;
                } elseif (!preg_match('/\|off$/i', $part)) {
                    foreach ($units as $u) {
                        if ($u['op_key'] === $part && $u['dpi'] === 'on') {
                            $keys[] = $u['op_key'];
                        }
                    }
                }
                continue;
            }
            $op = strtolower($part);
            $aliases = match ($op) {
                'мтс', 'mtc' => ['mts'],
                'мегафон', 'mega', 'megafon' => ['megafon'],
                'билайн', 'beeline' => ['beeline'],
                'теле2', 'tele2' => ['tele2'],
                'йота', 'yota' => ['yota'],
                default => [$op],
            };
            foreach ($units as $u) {
                if (in_array(strtolower($u['operator']), $aliases, true) && $u['dpi'] === 'on') {
                    $keys[] = $u['op_key'];
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param list<string> $requestedOps
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function interpret(
        array $json,
        string $ipv4,
        string $marker,
        string $target,
        array $requestedOps,
        bool $wantHttp
    ): array {
        $byTarget = $json['by_target'] ?? [];
        $targetBlock = null;
        if (is_array($byTarget)) {
            foreach ([$target, $ipv4, rtrim($target, '/'), $target . '/'] as $k) {
                if (isset($byTarget[$k]) && is_array($byTarget[$k])) {
                    $targetBlock = $byTarget[$k];
                    break;
                }
            }
            if ($targetBlock === null) {
                foreach ($byTarget as $key => $block) {
                    if (!is_array($block)) {
                        continue;
                    }
                    // совпадение по IP внутри ключа
                    if (is_string($key) && str_contains($key, $ipv4)) {
                        $targetBlock = $block;
                        break;
                    }
                }
            }
            if ($targetBlock === null) {
                foreach ($byTarget as $block) {
                    if (is_array($block)) {
                        $targetBlock = $block;
                        break;
                    }
                }
            }
        }

        $byOp = is_array($targetBlock['by_operator'] ?? null) ? $targetBlock['by_operator'] : [];
        if ($byOp === [] && is_array($json['results'] ?? null)) {
            foreach ($json['results'] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $k = (string) ($row['op_key'] ?? $row['operator'] ?? '');
                if ($k !== '') {
                    $byOp[$k] = $row;
                }
            }
        }

        $requestedSet = array_fill_keys($requestedOps, true);
        $passed = [];
        $markerOk = false;
        $notes = [];
        $minPass = max(1, Settings::int('BSBORD_MIN_PASS', Env::int('BSBORD_MIN_PASS', 1)));

        foreach ($byOp as $opKey => $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $opKeyStr = (string) $opKey;
            // только запрошенные операторы (не чужие ключи из ответа)
            if ($requestedSet !== [] && !isset($requestedSet[$opKeyStr])) {
                $matched = false;
                foreach ($requestedOps as $req) {
                    if ($req === $opKeyStr || str_starts_with($opKeyStr, explode('|', $req)[0] . '|')) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }

            $dpi = strtolower((string) ($leg['dpi'] ?? ''));
            if ($dpi === '') {
                if (str_ends_with(strtolower($opKeyStr), '|off')) {
                    $notes[] = $opKeyStr . '=skip-off';
                    continue;
                }
                if (str_ends_with(strtolower($opKeyStr), '|on')) {
                    $dpi = 'on';
                }
            }
            if ($dpi !== '' && $dpi !== 'on') {
                $notes[] = $opKeyStr . '=skip-no-bs';
                continue;
            }

            $tcpOk = $this->isTcpOk($leg);
            $http = is_array($leg['http'] ?? null) ? $leg['http'] : null;
            $status = (int) ($http['status'] ?? $http['code'] ?? $http['status_code'] ?? 0);
            $bodyHead = (string) (
                $http['body_head']
                ?? $http['body']
                ?? $http['response_body']
                ?? $http['preview']
                ?? ''
            );
            $hasMarker = $bodyHead !== '' && str_contains($bodyHead, $marker);
            if ($hasMarker) {
                $markerOk = true;
            }
            $httpOk = $http !== null && (
                $this->truthy($http['ok'] ?? null)
                || ($status >= 200 && $status < 400)
            );

            // PASS = только зелёный TCP (как в UI). Без leg.ok / global_ok.
            $legOk = $tcpOk;
            if ($wantHttp && $legOk && !$httpOk && !$hasMarker) {
                // для http-url пробы: TCP зелёный, но HTTP мёртв — в detail, ok по TCP всё равно
            }

            $short = sprintf(
                'tcp=%s http=%s marker=%s',
                $tcpOk ? '1' : '0',
                $httpOk ? (string) ($status > 0 ? $status : 1) : '0',
                $hasMarker ? '1' : '0'
            );

            if ($legOk) {
                $op = (string) ($leg['operator'] ?? explode('|', $opKeyStr)[0] ?? 'other');
                if ($op !== '' && !in_array($op, $passed, true)) {
                    $passed[] = $op;
                }
                $notes[] = $opKeyStr . '=ok(' . $short . ')';
            } else {
                $notes[] = $opKeyStr . '=fail(' . $short . ')';
            }
        }

        $ok = count($passed) >= $minPass;
        $detail = $ok
            ? ('PASS ops=' . implode(',', $passed) . ' min=' . $minPass)
            : ('FAIL need≥' . $minPass . ' ' . implode(';', array_slice($notes, 0, 12)));

        return [
            'ok' => $ok,
            'operators' => $passed,
            'marker_ok' => $markerOk,
            'detail' => $detail,
            'raw' => $json,
        ];
    }

    /** @param array<string, mixed> $leg */
    private function isTcpOk(array $leg): bool
    {
        $tcp = $leg['tcp'] ?? null;
        if (!is_array($tcp)) {
            return false;
        }
        if (array_key_exists('ok', $tcp)) {
            return $this->truthy($tcp['ok']);
        }
        if (array_key_exists('alive', $tcp)) {
            return $this->truthy($tcp['alive']);
        }
        $verdict = strtolower((string) ($tcp['verdict'] ?? $tcp['status'] ?? ''));
        return in_array($verdict, ['alive', 'ok', 'open', 'pass', 'success'], true);
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
