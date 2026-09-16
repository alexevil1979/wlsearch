<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

/**
 * User-data для Timeweb/Selectel.
 *
 * Timeweb: надёжнее `#!/bin/sh`. Probe — nginx :80+:443 (python http.server
 * снаружи часто даёт 502 у прокси/браузера при живом localhost).
 */
final class CloudInitBuilder
{
    /**
     * Одна shell-команда для уже созданного VPS (вставить по SSH).
     */
    public static function oneLiner(string $provider, int $runId): string
    {
        $inner = self::installScriptBody($provider, $runId);
        // сжатый однострочник для копипаста
        $b64 = base64_encode($inner);
        return "echo {$b64} | base64 -d | bash";
    }

    public static function forRun(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;
        $body = self::installScriptBody($provider, $runId);

        return "#!/bin/sh\n# wlsearch probe run_{$runId}\n" . $body;
    }

    public static function manualInstallBash(string $provider, int $runId): string
    {
        return self::forRun($provider, $runId);
    }

    private static function installScriptBody(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;

        return <<<SH
set -e
exec >>/var/log/wlsearch-cloud-init.log 2>&1
echo "wlsearch-probe start \$(date -u -Iseconds)"
export DEBIAN_FRONTEND=noninteractive
mkdir -p /var/www/html /etc/nginx/ssl /var/log
IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}')
if [ -z "\$IP" ]; then IP=\$(hostname -I 2>/dev/null | awk '{print \$1}'); fi
TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html
# убрать кривой python-probe
pkill -f wlsearch-probe.py 2>/dev/null || true
fuser -k 80/tcp 443/tcp 2>/dev/null || true
apt-get install -y -qq nginx openssl >/var/log/wlsearch-apt.log 2>&1 \\
  || { apt-get update -qq >>/var/log/wlsearch-apt.log 2>&1; apt-get install -y -qq nginx openssl >>/var/log/wlsearch-apt.log 2>&1; }
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \\
  -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \\
  -subj "/CN=\${IP}/O=wlsearch-probe" 2>/dev/null
cat > /etc/nginx/sites-available/default <<NGX
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.html;
    server_name _;
    location / { try_files \$uri /index.html; }
}
server {
    listen 443 ssl default_server;
    listen [::]:443 ssl default_server;
    ssl_certificate /etc/nginx/ssl/probe.crt;
    ssl_certificate_key /etc/nginx/ssl/probe.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /var/www/html;
    index index.html;
    server_name _;
    location / { try_files \$uri /index.html; }
}
NGX
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
rm -f /var/www/html/index.nginx-debian.html 2>/dev/null || true
nginx -t
systemctl enable nginx 2>/dev/null || true
systemctl restart nginx || service nginx restart
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'Status: active'; then
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
fi
iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 80 -j ACCEPT || true
iptables -C INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 443 -j ACCEPT || true
sleep 1
ss -lntp 2>/dev/null | grep -E ':80|:443' || true
curl -sS -m 3 http://127.0.0.1/ | head -c 160 || true
echo
echo "wlsearch-probe done IP=\$IP \$(date -u -Iseconds)"
SH;
    }
}
