<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

use Wlsearch\Support\Env;
use Wlsearch\Support\HttpClient;
use Wlsearch\Support\Settings;

/**
 * bsbord.com API — проверка через мобильные каналы.
 * «БС» в UI bsbord = dpi=on; «без БС» = dpi=off.
 * Мы проверяем ТОЛЬКО dpi=on.
 *
 * PASS = зелёная точка в «МОИ ПРОВЕРКИ»: TCP alive на канале БС.
 * HTTP/маркер — в detail, на PASS не влияют (иначе UI green, wlsearch FAIL).
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
     * Probe HTTP :80 via BS (dpi=on) — основной PASS.
     * HTTPS :443 — информативно (самоподписанный часто валит HTTP-клиент bsbord),
     * на общий ok не влияет, если :80 уже PASS.
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

        $httpRes = $this->probeTarget('http://' . $ipv4 . '/', 80, $operators, $ipv4, $marker);
        $httpsRes = $this->probeTarget('https://' . $ipv4 . '/', 443, $operators, $ipv4, $marker);

        // PASS = HTTP :80. HTTPS не блокирует (в UI часто зелёный только TCP).
        $ok = $httpRes['ok'];
        $ops = array_values(array_unique(array_merge($httpRes['operators'], $httpsRes['operators'])));
        $detail = sprintf(
            'http[%s] https[%s%s]',
            $httpRes['detail'],
            $httpsRes['detail'],
            $httpsRes['ok'] ? '' : ' (игнор для PASS)'
        );

        return [
            'ok' => $ok,
            'operators' => $ops,
            'marker_ok' => $httpRes['marker_ok'] || $httpsRes['marker_ok'],
            'detail' => ($ok ? 'bsbord БС PASS ' : 'bsbord БС FAIL ') . $detail,
            'raw' => [
                'http' => $httpRes['raw'],
                'https' => $httpsRes['raw'],
            ],
        ];
    }

    /**
     * @param list<string> $operators
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function probeTarget(string $target, int $tcpPort, array $operators, string $ipv4, string $marker): array
    {
        $body = [
            'target' => $target,
            'dpi' => 'on',
            'operators' => $operators,
            'probes' => [
                'icmp' => false,
                'tcp' => true,
                'http' => true,
            ],
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

        $out = $this->interpret($json, $ipv4, $marker, $target);
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
     * PASS = зелёная точка в UI bsbord: канал БС (dpi=on) + TCP alive.
     * HTTP/маркер пишем в detail, но НЕ валят PASS — иначе UI зелёный, а wlsearch FAIL
     * (типично: tcp=1 http=0 marker=0 при зелёном «TCP» на http://IP/).
     *
     * @param array<string, mixed> $json
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function interpret(array $json, string $ipv4, string $marker, string $target): array
    {
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
                foreach ($byTarget as $block) {
                    if (is_array($block)) {
                        $targetBlock = $block;
                        break;
                    }
                }
            }
        }

        $byOp = is_array($targetBlock['by_operator'] ?? null) ? $targetBlock['by_operator'] : [];
        // иногда ответ плоский: results[] / operators[]
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

        $passed = [];
        $markerOk = false;
        $notes = [];
        $minPass = max(1, Settings::int('BSBORD_MIN_PASS', Env::int('BSBORD_MIN_PASS', 1)));

        foreach ($byOp as $opKey => $leg) {
            if (!is_array($leg)) {
                continue;
            }

            $opKeyStr = (string) $opKey;
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
            if ($dpi !== 'on') {
                $notes[] = $opKeyStr . '=skip-no-bs';
                continue;
            }

            $tcpOk = $this->isTcpOk($leg);
            $legTruthy = $this->truthy($leg['ok'] ?? null)
                || in_array(strtolower((string) ($leg['verdict'] ?? '')), ['ok', 'pass', 'success', 'alive'], true);

            $http = is_array($leg['http'] ?? null) ? $leg['http'] : null;
            $status = (int) ($http['status'] ?? $http['code'] ?? $http['status_code'] ?? 0);
            $httpTruthy = $http !== null && (
                $this->truthy($http['ok'] ?? null)
                || in_array(strtolower((string) ($http['verdict'] ?? '')), ['ok', 'pass', 'success', 'alive'], true)
            );
            $httpOk = $http !== null
                && ($httpTruthy || ($status >= 200 && $status < 400))
                && ($status === 0 || ($status >= 200 && $status < 400));
            $bodyHead = (string) (
                $http['body_head']
                ?? $http['body']
                ?? $http['response_body']
                ?? $http['preview']
                ?? $leg['body_head']
                ?? ''
            );
            $hasMarker = $bodyHead !== '' && str_contains($bodyHead, $marker);
            if ($hasMarker) {
                $markerOk = true;
            }

            // Как в UI «МОИ ПРОВЕРКИ»: зелёный = TCP (или leg.ok). HTTP — бонус в логе.
            $legOk = $tcpOk || $legTruthy;
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

        // Глобальный ok ответа bsbord (если есть) — тоже зелёный сигнал
        if (count($passed) < $minPass && $this->truthy($json['ok'] ?? null)) {
            $passed[] = 'bsbord';
            $notes[] = 'global_ok=1';
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
        $verdict = strtolower((string) ($tcp['verdict'] ?? ''));
        return in_array($verdict, ['alive', 'ok', 'open', 'pass', 'success'], true);
    }

    private function truthy(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'true';
    }
}
