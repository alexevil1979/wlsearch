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

        $body = [
            'target' => 'http://' . $ipv4 . '/',
            'dpi' => 'on', // строго режим БС
            'operators' => $operators,
            'probes' => [
                'icmp' => false,
                'tcp' => true,
            ],
            'tcp_port' => 80,
        ];

        $idem = bin2hex(random_bytes(16));
        $resp = $this->http->request('POST', $this->base . '/probe', $body, [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Idempotency-Key: ' . $idem,
        ]);

        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new \RuntimeException('bsbord HTTP ' . $resp['status'] . ': ' . mb_substr($resp['body'], 0, 500));
        }

        $json = json_decode($resp['body'], true);
        if (!is_array($json)) {
            throw new \RuntimeException('bsbord: invalid JSON');
        }

        return $this->interpret($json, $ipv4, $marker);
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
        $units = $this->listOperators('on'); // только БС

        if ($raw === '') {
            // Дефолт как на скрине: МегаФон / МТС / Билайн ЦФО (БС)
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
                // fallback: любые megafon/mts/beeline с dpi=on
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
                // full op_key — only allow dpi=on suffix
                if (str_ends_with(strtolower($part), '|on') || str_ends_with(strtolower($part), '|on"')) {
                    $keys[] = $part;
                } elseif (!preg_match('/\|off$/i', $part)) {
                    // if no dpi suffix, try match from units
                    foreach ($units as $u) {
                        if ($u['op_key'] === $part && $u['dpi'] === 'on') {
                            $keys[] = $u['op_key'];
                        }
                    }
                }
                continue;
            }
            // short name: mts / megafon / beeline — take all dpi=on or prefer cfo
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
     * @param array<string, mixed> $json
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function interpret(array $json, string $ipv4, string $marker): array
    {
        $byTarget = $json['by_target'] ?? [];
        $targetBlock = null;
        if (is_array($byTarget)) {
            foreach ([$ipv4, 'http://' . $ipv4 . '/', 'http://' . $ipv4] as $k) {
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
        $passed = [];
        $markerOk = false;
        $notes = [];
        $minPass = max(1, Settings::int('BSBORD_MIN_PASS', Env::int('BSBORD_MIN_PASS', 1)));

        foreach ($byOp as $opKey => $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $dpi = strtolower((string) ($leg['dpi'] ?? ''));
            if ($dpi !== '' && $dpi !== 'on') {
                $notes[] = $opKey . '=skip-no-bs';
                continue;
            }

            $tcpOk = !empty($leg['tcp']['ok']) || (($leg['tcp']['verdict'] ?? '') === 'alive');
            $http = is_array($leg['http'] ?? null) ? $leg['http'] : null;
            $httpOk = $http && !empty($http['ok']) && (int) ($http['status'] ?? 0) >= 200 && (int) ($http['status'] ?? 0) < 400;
            $bodyHead = (string) ($http['body_head'] ?? '');
            $hasMarker = $bodyHead !== '' && str_contains($bodyHead, $marker);

            $legOk = $hasMarker || $httpOk || $tcpOk || !empty($leg['ok']);
            if ($hasMarker) {
                $markerOk = true;
            }

            if ($legOk) {
                $op = (string) ($leg['operator'] ?? explode('|', (string) $opKey)[0] ?? 'other');
                if ($op !== '' && !in_array($op, $passed, true)) {
                    $passed[] = $op;
                }
                $notes[] = $opKey . '=ok';
            } else {
                $notes[] = $opKey . '=fail';
            }
        }

        $ok = count($passed) >= $minPass;
        $detail = $ok
            ? ('bsbord БС PASS ops=' . implode(',', $passed) . ' min=' . $minPass)
            : ('bsbord БС FAIL need≥' . $minPass . ' ' . implode(';', array_slice($notes, 0, 16)));

        return [
            'ok' => $ok,
            'operators' => $passed,
            'marker_ok' => $markerOk,
            'detail' => $detail,
            'raw' => $json,
        ];
    }
}
