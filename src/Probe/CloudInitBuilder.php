<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

final class CloudInitBuilder
{
    /**
     * Probe on candidate VPS: nginx :80 + :443 (self-signed), body contains WL_PROBE_OK.
     */
    public static function forRun(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;

        return <<<YAML
#cloud-config
package_update: true
packages:
  - nginx
  - openssl
runcmd:
  - |
    set -e
    mkdir -p /var/www/html /etc/nginx/ssl
    IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}' || true)
    if [ -z "\$IP" ]; then
      IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
    fi
    TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
    printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
    rm -f /var/www/html/index.nginx-debian.html || true
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \\
      -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \\
      -subj "/CN=\${IP}/O=wlsearch-probe" 2>/dev/null
    printf '%s\\n' \\
      'server {' \\
      '    listen 80 default_server;' \\
      '    listen [::]:80 default_server;' \\
      '    root /var/www/html;' \\
      '    index index.html;' \\
      '    server_name _;' \\
      '    location / { }' \\
      '}' \\
      'server {' \\
      '    listen 443 ssl default_server;' \\
      '    listen [::]:443 ssl default_server;' \\
      '    ssl_certificate /etc/nginx/ssl/probe.crt;' \\
      '    ssl_certificate_key /etc/nginx/ssl/probe.key;' \\
      '    ssl_protocols TLSv1.2 TLSv1.3;' \\
      '    root /var/www/html;' \\
      '    index index.html;' \\
      '    server_name _;' \\
      '    location / { }' \\
      '}' \\
      > /etc/nginx/sites-available/default
    ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default || true
    nginx -t
    systemctl enable nginx || true
    systemctl restart nginx || service nginx restart || true
YAML;
    }
}
