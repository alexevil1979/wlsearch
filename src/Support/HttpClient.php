<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class HttpClient
{
    /** @var list<string> */
    private array $defaultHeaders;

    private int $timeout;

    private ?string $proxy;

    /** @param list<string> $defaultHeaders */
    public function __construct(array $defaultHeaders = [], int $timeout = 60, ?string $proxy = null)
    {
        $this->defaultHeaders = $defaultHeaders;
        $this->timeout = $timeout;
        $proxy = $proxy !== null ? trim($proxy) : null;
        $this->proxy = ($proxy !== null && $proxy !== '') ? $proxy : null;
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{status:int, body:string, headers:array<string,string>}
     */
    public function request(string $method, string $url, ?array $jsonBody = null, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        $headers = array_merge($this->defaultHeaders, $extraHeaders);
        $opts = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($jsonBody !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($this->proxy !== null) {
            $opts[CURLOPT_PROXY] = $this->proxy;
            $opts[CURLOPT_PROXYTYPE] = $this->proxyType($this->proxy);
        }

        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP request failed: ' . $err);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $headerBlob = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $parsedHeaders = [];
        foreach (explode("\r\n", $headerBlob) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsedHeaders[strtolower(trim($k))] = trim($v);
            }
        }

        return ['status' => $status, 'body' => $body === false ? '' : $body, 'headers' => $parsedHeaders];
    }

    /**
     * Plain GET (for control probe).
     * @return array{status:int, body:string, error:?string}
     */
    public function getPlain(string $url, int $timeout = 10, bool $insecureSsl = false): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_USERAGENT => 'wlsearch-control-check/1.0',
        ];
        if ($insecureSsl || str_starts_with(strtolower($url), 'https://')) {
            // Probe VPS uses self-signed cert
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => $error ?: 'request failed'];
        }
        return ['status' => $status, 'body' => $body, 'error' => null];
    }

    private function proxyType(string $proxy): int
    {
        $p = strtolower($proxy);
        if (str_starts_with($p, 'socks5h://') || str_starts_with($p, 'socks5://')) {
            return defined('CURLPROXY_SOCKS5_HOSTNAME') ? CURLPROXY_SOCKS5_HOSTNAME : CURLPROXY_SOCKS5;
        }
        if (str_starts_with($p, 'socks4://') || str_starts_with($p, 'socks4a://')) {
            return defined('CURLPROXY_SOCKS4A') ? CURLPROXY_SOCKS4A : CURLPROXY_SOCKS4;
        }
        return CURLPROXY_HTTP;
    }
}
