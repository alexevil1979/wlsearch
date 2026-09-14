<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class HttpClient
{
    /** @var list<string> */
    private array $defaultHeaders;

    private int $timeout;

    /** @param list<string> $defaultHeaders */
    public function __construct(array $defaultHeaders = [], int $timeout = 60)
    {
        $this->defaultHeaders = $defaultHeaders;
        $this->timeout = $timeout;
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
    public function getPlain(string $url, int $timeout = 10): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl_init failed'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_USERAGENT => 'wlsearch-control-check/1.0',
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => $error ?: 'request failed'];
        }
        return ['status' => $status, 'body' => $body, 'error' => null];
    }
}
