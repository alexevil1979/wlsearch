<?php

declare(strict_types=1);

namespace Wlsearch\Support;

final class AsnLookup
{
    /**
     * Best-effort ASN lookup. Never throws.
     * @return array{asn:?int, org:?string}
     */
    public static function lookup(string $ipv4): array
    {
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['asn' => null, 'org' => null];
        }

        $token = Env::get('IPINFO_TOKEN', '');
        try {
            $http = new HttpClient([], 8);
            if ($token) {
                $resp = $http->getPlain('https://ipinfo.io/' . rawurlencode($ipv4) . '/json?token=' . rawurlencode($token), 6);
            } else {
                $resp = $http->getPlain('https://api.bgpview.io/ip/' . rawurlencode($ipv4), 6);
            }
            if ($resp['error'] || $resp['status'] >= 400) {
                return ['asn' => null, 'org' => null];
            }
            $json = json_decode($resp['body'], true);
            if (!is_array($json)) {
                return ['asn' => null, 'org' => null];
            }

            if ($token) {
                $org = (string) ($json['org'] ?? '');
                if (preg_match('/AS(\d+)\s*(.*)/', $org, $m)) {
                    return ['asn' => (int) $m[1], 'org' => trim($m[2]) !== '' ? trim($m[2]) : $org];
                }
                return ['asn' => null, 'org' => $org !== '' ? $org : null];
            }

            // bgpview
            $data = $json['data'] ?? [];
            $prefixes = $data['prefixes'] ?? [];
            if (is_array($prefixes) && isset($prefixes[0]['asn'])) {
                $asnBlock = $prefixes[0]['asn'];
                return [
                    'asn' => isset($asnBlock['asn']) ? (int) $asnBlock['asn'] : null,
                    'org' => isset($asnBlock['name']) ? (string) $asnBlock['name'] : null,
                ];
            }
        } catch (\Throwable) {
            // ignore
        }

        return ['asn' => null, 'org' => null];
    }
}
