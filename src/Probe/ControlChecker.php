<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

use Wlsearch\Support\FileLog;
use Wlsearch\Support\HttpClient;

final class ControlChecker
{
    private HttpClient $http;

    /** @var array<string, mixed> */
    private array $logCtx = [];

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient([], 15);
    }

    /** @param array<string, mixed> $ctx */
    public function withLogContext(array $ctx): self
    {
        $clone = clone $this;
        $clone->logCtx = $ctx;
        return $clone;
    }

    /**
     * Control: HTTP :80 and HTTPS :443 must both return WL_PROBE_OK.
     *
     * @return array{ok:bool, status:int, body_snippet:string, error:?string, debug?:array<string,mixed>}
     */
    public function check(string $ipv4, string $marker = 'WL_PROBE_OK'): array
    {
        $http = $this->checkHttp($ipv4, $marker);
        if (!$http['ok']) {
            return $http;
        }

        $https = $this->probeOne('https://' . $ipv4 . '/', $marker);
        if (!$https['ok']) {
            return [
                'ok' => false,
                'status' => $https['status'],
                'body_snippet' => $https['body_snippet'],
                'error' => 'https: ' . ($https['error'] ?? 'fail'),
                'debug' => $https['debug'] ?? [],
            ];
        }

        return [
            'ok' => true,
            'status' => $https['status'],
            'body_snippet' => 'http+https OK ' . mb_substr($https['body_snippet'], 0, 200),
            'error' => null,
            'debug' => $https['debug'] ?? [],
        ];
    }

    /**
     * HTTP :80 only (достаточно для перехода к bsbord, пока поднимается HTTPS).
     *
     * @return array{ok:bool, status:int, body_snippet:string, error:?string, debug?:array<string,mixed>}
     */
    public function checkHttp(string $ipv4, string $marker = 'WL_PROBE_OK'): array
    {
        $http = $this->probeOne('http://' . $ipv4 . '/', $marker);
        if (!$http['ok']) {
            return [
                'ok' => false,
                'status' => $http['status'],
                'body_snippet' => $http['body_snippet'],
                'error' => 'http: ' . ($http['error'] ?? 'fail'),
                'debug' => $http['debug'] ?? [],
            ];
        }
        return [
            'ok' => true,
            'status' => $http['status'],
            'body_snippet' => 'http OK ' . mb_substr($http['body_snippet'], 0, 200),
            'error' => null,
            'debug' => $http['debug'] ?? [],
        ];
    }

    /**
     * @return array{ok:bool, status:int, body_snippet:string, error:?string, debug:array<string,mixed>}
     */
    private function probeOne(string $url, string $marker): array
    {
        $resp = $this->http->getPlain($url, 15, true);
        $snippet = mb_substr($resp['body'], 0, 500);
        $debug = [
            'curl' => $resp['curl'] ?? [],
            'body_len' => strlen($resp['body']),
            'body_head' => mb_substr($resp['body'], 0, 120),
            'error_raw' => $resp['error'],
        ];

        $ok = false;
        $error = null;
        if ($resp['error'] !== null) {
            $error = $resp['error'];
        } elseif ($resp['status'] < 200 || $resp['status'] >= 400) {
            $error = 'HTTP ' . $resp['status'];
        } else {
            $ok = str_contains($resp['body'], $marker);
            $error = $ok ? null : 'marker not found';
        }

        FileLog::write('probe', $ok ? 'probe:ok' : 'probe:fail', array_merge($this->logCtx, [
            'url' => $url,
            'ok' => $ok,
            'status' => $resp['status'],
            'error' => $error,
            'curl' => $resp['curl'] ?? [],
            'body_head' => mb_substr($resp['body'], 0, 160),
        ]));

        return [
            'ok' => $ok,
            'status' => $resp['status'],
            'body_snippet' => $snippet,
            'error' => $error,
            'debug' => $debug,
        ];
    }
}
