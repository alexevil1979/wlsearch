<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

/**
 * Probe user-data для Timeweb/Selectel.
 *
 * Важно: сначала python (без apt) — иначе cloud-init часами висит на
 * apt-get update/nginx (зеркало Timeweb), снаружи Connection refused.
 * nginx-light — только фоном, не блокирует старт.
 */
final class CloudInitBuilder
{
    public static function oneLiner(string $provider, int $runId): string
    {
        $inner = self::normalizeLf(self::installScriptBody($provider, $runId));
        return 'echo ' . base64_encode($inner) . ' | base64 -d | bash';
    }

    public static function forRun(string $provider, int $runId): string
    {
        $runId = (int) $runId;
        $body = self::normalizeLf(self::installScriptBody($provider, $runId));
        return "#!/bin/sh\n# wlsearch probe run_{$runId}\n" . $body;
    }

    public static function manualInstallBash(string $provider, int $runId): string
    {
        return self::forRun($provider, $runId);
    }

    private static function normalizeLf(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    private static function installScriptBody(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;
        $pyB64 = base64_encode(self::probePythonSource());
        $pyB64Wrapped = trim(chunk_split($pyB64, 76, "\n"));

        return <<<SH
set +e
exec >>/var/log/wlsearch-cloud-init.log 2>&1
echo "wlsearch-probe start \$(date -u -Iseconds)"
export DEBIAN_FRONTEND=noninteractive
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log /etc/nginx/ssl

# 1) открыть сеть сразу (иначе снаружи hang / refused)
command -v ufw >/dev/null 2>&1 && ufw --force disable
iptables -P INPUT ACCEPT 2>/dev/null
iptables -I INPUT 1 -p tcp --dport 80 -j ACCEPT 2>/dev/null
iptables -I INPUT 1 -p tcp --dport 443 -j ACCEPT 2>/dev/null

IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}')
if [ -z "\$IP" ]; then IP=\$(hostname -I 2>/dev/null | awk '{print \$1}'); fi
TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html

# 2) python probe СРАЗУ — без apt (на Ubuntu уже есть python3)
pkill -f wlsearch-probe.py 2>/dev/null
fuser -k 80/tcp 443/tcp 2>/dev/null
base64 -d > /usr/local/bin/wlsearch-probe.py <<'WLSEARCH_PY_B64'
{$pyB64Wrapped}
WLSEARCH_PY_B64
chmod +x /usr/local/bin/wlsearch-probe.py
nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >>/var/log/wlsearch-probe.log 2>&1 &
sleep 1
curl -sS -m 2 http://127.0.0.1/ | head -c 120
echo
echo "python probe up \$(date -u -Iseconds)"

# 3) nginx опционально в фоне (не блокирует boot)
(
  apt-get update -qq >>/var/log/wlsearch-apt.log 2>&1
  apt-get install -y -qq nginx-light openssl >>/var/log/wlsearch-apt.log 2>&1 \\
    || apt-get install -y -qq nginx-core openssl >>/var/log/wlsearch-apt.log 2>&1 || exit 0
  command -v nginx >/dev/null 2>&1 || exit 0
  pkill -f wlsearch-probe.py 2>/dev/null
  fuser -k 80/tcp 443/tcp 2>/dev/null
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \\
    -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \\
    -subj "/CN=\${IP}/O=wlsearch-probe" 2>/dev/null
  mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled
  cat > /etc/nginx/sites-available/default <<'NGX'
server {
    listen 80 default_server;
    root /var/www/html;
    index index.html;
    location / { try_files \$uri /index.html; }
}
server {
    listen 443 ssl default_server;
    ssl_certificate /etc/nginx/ssl/probe.crt;
    ssl_certificate_key /etc/nginx/ssl/probe.key;
    ssl_protocols TLSv1.2 TLSv1.3;
    root /var/www/html;
    index index.html;
    location / { try_files \$uri /index.html; }
}
NGX
  ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
  nginx -t && systemctl restart nginx
  ufw --force disable 2>/dev/null
  echo "nginx probe up \$(date -u -Iseconds)" >>/var/log/wlsearch-cloud-init.log
) >/dev/null 2>&1 &

echo "wlsearch-probe done IP=\$IP \$(date -u -Iseconds)"
SH;
    }

    private static function probePythonSource(): string
    {
        return <<<'PY'
#!/usr/bin/env python3
import http.server, ssl, threading, pathlib, subprocess, os, sys
ROOT = pathlib.Path("/var/www/html")
SSL_DIR = pathlib.Path("/etc/wlsearch-ssl")
BODY = ROOT.joinpath("index.html").read_bytes() if ROOT.joinpath("index.html").exists() else b"WL_PROBE_OK\n"
CRT, KEY = SSL_DIR / "probe.crt", SSL_DIR / "probe.key"
def ensure_cert(cn):
    SSL_DIR.mkdir(parents=True, exist_ok=True)
    if CRT.exists() and KEY.exists(): return True
    try:
        subprocess.check_call(["openssl","req","-x509","-nodes","-newkey","rsa:2048","-days","3650",
            "-keyout",str(KEY),"-out",str(CRT),"-subj",f"/CN={cn}/O=wlsearch-probe"],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        return CRT.exists() and KEY.exists()
    except Exception:
        return False
class H(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    def do_GET(self):
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(BODY)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(BODY)
    def log_message(self, *a): pass
def serve(port, tls=False):
    httpd = http.server.HTTPServer(("0.0.0.0", port), H)
    httpd.allow_reuse_address = True
    if tls:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        ctx.load_cert_chain(str(CRT), str(KEY))
        httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
    httpd.serve_forever()
cn = os.environ.get("WLSEARCH_IP", "wlsearch")
threading.Thread(target=serve, args=(80, False), daemon=True).start()
if ensure_cert(cn):
    threading.Thread(target=serve, args=(443, True), daemon=True).start()
threading.Event().wait()
PY;
    }
}
