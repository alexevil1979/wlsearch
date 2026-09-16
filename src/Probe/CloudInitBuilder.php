<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

final class CloudInitBuilder
{
    /**
     * Fast probe on candidate VPS: HTTP :80 + HTTPS :443 with WL_PROBE_OK.
     * Prefer python3 (no apt update — was ~5–15 min); fallback nginx.
     */
    public static function forRun(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;

        $py = self::probePythonSource();
        $pyB64 = base64_encode($py);

        return <<<YAML
#cloud-config
package_update: false
package_upgrade: false
bootcmd:
  - [ cloud-init-per, once, wlsearch-stamp, sh, -c, "date -u > /var/log/wlsearch-cloud-init-started" ]
runcmd:
  - |
    set +e
    mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log
    date -u > /var/log/wlsearch-cloud-init-runcmd
    IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}' || true)
    if [ -z "\$IP" ]; then
      IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
    fi
    TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
    printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
    chmod 644 /var/www/html/index.html
    export WLSEARCH_IP="\$IP"

    if command -v python3 >/dev/null 2>&1; then
      echo '{$pyB64}' | base64 -d > /usr/local/bin/wlsearch-probe.py
      chmod +x /usr/local/bin/wlsearch-probe.py
      (command -v fuser >/dev/null 2>&1 && fuser -k 80/tcp 443/tcp) || true
      systemctl stop nginx 2>/dev/null || service nginx stop 2>/dev/null || true
      pkill -f wlsearch-probe.py 2>/dev/null || true
      nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >/var/log/wlsearch-probe.log 2>&1 &
      sleep 1
      exit 0
    fi

    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y --no-install-recommends nginx openssl >>/var/log/wlsearch-apt.log 2>&1 \\
      || { apt-get update -qq >>/var/log/wlsearch-apt.log 2>&1; apt-get install -y --no-install-recommends nginx openssl >>/var/log/wlsearch-apt.log 2>&1; }
    mkdir -p /etc/nginx/ssl
    openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \\
      -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \\
      -subj "/CN=\${IP}/O=wlsearch-probe" 2>/dev/null || true
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

    /**
     * Ручная установка probe на уже созданный VPS (если cloud-init не отработал).
     */
    public static function manualInstallBash(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;
        $py = self::probePythonSource();
        $pyB64 = base64_encode($py);

        return <<<BASH
#!/bin/bash
set -e
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log
IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}' || true)
if [ -z "\$IP" ]; then IP=\$(hostname -I 2>/dev/null | awk '{print \$1}'); fi
TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html
echo '{$pyB64}' | base64 -d > /usr/local/bin/wlsearch-probe.py
chmod +x /usr/local/bin/wlsearch-probe.py
(command -v fuser >/dev/null 2>&1 && fuser -k 80/tcp 443/tcp) || true
systemctl stop nginx 2>/dev/null || service nginx stop 2>/dev/null || true
pkill -f wlsearch-probe.py 2>/dev/null || true
nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >/var/log/wlsearch-probe.log 2>&1 &
sleep 1
echo "probe up IP=\$IP"
curl -sS "http://127.0.0.1/" | head -c 200; echo
BASH;
    }

    private static function probePythonSource(): string
    {
        return <<<'PY'
#!/usr/bin/env python3
import http.server, ssl, threading, pathlib, subprocess, os, sys

ROOT = pathlib.Path("/var/www/html")
SSL_DIR = pathlib.Path("/etc/wlsearch-ssl")
BODY = ROOT.joinpath("index.html").read_bytes() if ROOT.joinpath("index.html").exists() else b"WL_PROBE_OK\n"
CRT = SSL_DIR / "probe.crt"
KEY = SSL_DIR / "probe.key"

def ensure_cert(cn: str) -> bool:
    SSL_DIR.mkdir(parents=True, exist_ok=True)
    if CRT.exists() and KEY.exists():
        return True
    try:
        subprocess.check_call([
            "openssl", "req", "-x509", "-nodes", "-newkey", "rsa:2048", "-days", "3650",
            "-keyout", str(KEY), "-out", str(CRT),
            "-subj", f"/CN={cn}/O=wlsearch-probe",
        ], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return CRT.exists() and KEY.exists()
    except Exception:
        return False

class H(http.server.BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(BODY)))
        self.end_headers()
        self.wfile.write(BODY)
    def log_message(self, *a):
        pass

def serve(port: int, tls: bool = False) -> None:
    httpd = http.server.HTTPServer(("0.0.0.0", port), H)
    if tls:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(CRT), str(KEY))
        httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
    httpd.serve_forever()

def main() -> int:
    cn = os.environ.get("WLSEARCH_IP", "wlsearch")
    threading.Thread(target=serve, args=(80, False), daemon=True).start()
    if ensure_cert(cn):
        threading.Thread(target=serve, args=(443, True), daemon=True).start()
    else:
        sys.stderr.write("wlsearch-probe: https skipped (no cert)\n")
    threading.Event().wait()
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
PY;
    }
}
