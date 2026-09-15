<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

use Wlsearch\Support\Env;
use Wlsearch\Support\HttpClient;
use Wlsearch\Support\Settings;

/**
 * Alternative BS check via https://bsbord.com/v1 (mobile DPI-on probes).
 *
 * Docs surface in product UI: POST /v1/probe with Bearer bsk_live_…
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
     * Probe candidate IPv4 through mobile operators with dpi=on.
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

        $operatorsFilter = $this->operatorFilter();
        $body = [
            'target' => 'http://' . $ipv4 . '/',
            'dpi' => 'on',
            'probes' => [
                'icmp' => false,
                'tcp' => true,
            ],
            'tcp_port' => 80,
        ];
        if ($operatorsFilter !== []) {
            $body['operators'] = $operatorsFilter;
        }

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
     * @param array<string, mixed> $json
     * @return array{ok:bool,operators:list<string>,marker_ok:bool,detail:string,raw:array}
     */
    private function interpret(array $json, string $ipv4, string $marker): array
    {
        $byTarget = $json['by_target'] ?? [];
        $targetBlock = null;
        if (is_array($byTarget)) {
            foreach ($byTarget as $key => $block) {
                if (is_array($block)) {
                    $targetBlock = $block;
                    break;
                }
            }
            // try exact keys
            foreach ([$ipv4, 'http://' . $ipv4 . '/', 'http://' . $ipv4] as $k) {
                if (isset($byTarget[$k]) && is_array($byTarget[$k])) {
                    $targetBlock = $byTarget[$k];
                    break;
                }
            }
        }

        $byOp = is_array($targetBlock['by_operator'] ?? null) ? $targetBlock['by_operator'] : [];
        $passed = [];
        $markerOk = false;
        $notes = [];

        foreach ($byOp as $opKey => $leg) {
            if (!is_array($leg)) {
                continue;
            }
            $dpi = strtolower((string) ($leg['dpi'] ?? ''));
            // Only count DPI/whitelist-on legs as BS signal
            if ($dpi !== '' && $dpi !== 'on') {
                continue;
            }

            $tcpOk = !empty($leg['tcp']['ok']) || (($leg['tcp']['verdict'] ?? '') === 'alive');
            $http = is_array($leg['http'] ?? null) ? $leg['http'] : null;
            $httpOk = $http && !empty($http['ok']) && (int) ($http['status'] ?? 0) >= 200 && (int) ($http['status'] ?? 0) < 400;
            $bodyHead = (string) ($http['body_head'] ?? '');
            $hasMarker = $bodyHead !== '' && str_contains($bodyHead, $marker);

            $legOk = false;
            if ($hasMarker) {
                $legOk = true;
                $markerOk = true;
            } elseif ($httpOk) {
                $legOk = true;
            } elseif ($tcpOk) {
                // TCP:80 from dpi=on mobile channel — strong BS reachability signal;
                // marker already verified by control_ok on orchestrator.
                $legOk = true;
            }

            if ($legOk || !empty($leg['ok'])) {
                if ($legOk || (!empty($leg['ok']) && ($tcpOk || $httpOk))) {
                    $op = (string) ($leg['operator'] ?? explode('|', (string) $opKey)[0] ?? 'other');
                    if ($op !== '' && !in_array($op, $passed, true)) {
                        $passed[] = $op;
                    }
                    $notes[] = $opKey . '=ok';
                }
            } else {
                $notes[] = $opKey . '=fail';
            }
        }

        $ok = $passed !== [];
        $detail = $ok
            ? ('bsbord PASS ops=' . implode(',', $passed) . ' marker=' . ($markerOk ? 'yes' : 'n/a-tcp'))
            : ('bsbord FAIL ' . implode(';', array_slice($notes, 0, 12)));

        return [
            'ok' => $ok,
            'operators' => $passed,
            'marker_ok' => $markerOk,
            'detail' => $detail,
            'raw' => $json,
        ];
    }

    /** @return list<string> */
    private function operatorFilter(): array
    {
        $raw = Settings::get('BSBORD_OPERATORS', Env::get('BSBORD_OPERATORS', ''));
        if ($raw === null || trim($raw) === '') {
            return [];
        }
        // Allow either full op_keys (mts|Мск|on) or short names (mts,beeline)
        $parts = array_filter(array_map('trim', explode(',', $raw)));
        return array_values($parts);
    }
}
