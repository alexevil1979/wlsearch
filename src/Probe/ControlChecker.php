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
     * @return array{ok:bool, status:int, body_snippet:string, error:?string}
     */
    public function check(string $ipv4, string $marker = 'WL_PROBE_OK'): array
    {
        $url = 'http://' . $ipv4 . '/';
        $resp = $this->http->getPlain($url, 12);
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
