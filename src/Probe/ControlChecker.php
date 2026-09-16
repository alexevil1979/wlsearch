<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

use Wlsearch\Support\HttpClient;

final class ControlChecker
{
    private HttpClient $http;

    public function __construct(?HttpClient $http = null)
    {
        $this->http = $http ?? new HttpClient([], 15);
    }

    /**
     * Control: HTTP :80 and HTTPS :443 must both return WL_PROBE_OK.
     *
     * @return array{ok:bool, status:int, body_snippet:string, error:?string}
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
            ];
        }

        return [
            'ok' => true,
            'status' => $https['status'],
            'body_snippet' => 'http+https OK ' . mb_substr($https['body_snippet'], 0, 200),
            'error' => null,
        ];
    }

    /**
     * HTTP :80 only (достаточно для перехода к bsbord, пока поднимается HTTPS).
     *
     * @return array{ok:bool, status:int, body_snippet:string, error:?string}
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
            ];
        }
        return [
            'ok' => true,
            'status' => $http['status'],
            'body_snippet' => 'http OK ' . mb_substr($http['body_snippet'], 0, 200),
            'error' => null,
        ];
    }

    /**
     * @return array{ok:bool, status:int, body_snippet:string, error:?string}
     */
    private function probeOne(string $url, string $marker): array
    {
        $http = $this->http->getPlain($url, 15, true);
        $snippet = mb_substr($resp['body'], 0, 500);
        if ($resp['error'] !== null) {
            return ['ok' => false, 'status' => $resp['status'], 'body_snippet' => $snippet, 'error' => $resp['error']];
        }
        if ($resp['status'] < 200 || $resp['status'] >= 400) {
            return ['ok' => false, 'status' => $resp['status'], 'body_snippet' => $snippet, 'error' => 'HTTP ' . $resp['status']];
        }
        $ok = str_contains($resp['body'], $marker);
        return [
            'ok' => $ok,
            'status' => $resp['status'],
            'body_snippet' => $snippet,
            'error' => $ok ? null : 'marker not found',
        ];
    }
}
