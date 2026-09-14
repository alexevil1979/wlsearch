<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

final class CloudInitBuilder
{
    /**
     * Probe on candidate VPS: nginx :80, body contains WL_PROBE_OK.
     * IP resolved at boot; run id is known before create.
     */
    public static function forRun(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;

        // cloud-config + runcmd to write marker with detected public/local IPv4
        return <<<YAML
#cloud-config
package_update: true
packages:
  - nginx
runcmd:
  - |
    set -e
    mkdir -p /var/www/html
    IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}' || true)
    if [ -z "\$IP" ]; then
      IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
    fi
    TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
    printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
    rm -f /var/www/html/index.nginx-debian.html || true
    systemctl enable nginx || true
    systemctl restart nginx || service nginx restart || true
YAML;
    }
}
