<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

/**
 * User-data / ручной probe: nginx-light (после apt-get update).
 * Fallback: python3, если apt/зеркало Timeweb снова 404.
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
mkdir -p /var/www/html /etc/nginx/ssl /etc/wlsearch-ssl /usr/local/bin /var/log
IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}')
if [ -z "\$IP" ]; then IP=\$(hostname -I 2>/dev/null | awk '{print \$1}'); fi
TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html
pkill -f wlsearch-probe.py 2>/dev/null || true
fuser -k 80/tcp 443/tcp 2>/dev/null || true

# Timeweb mirror часто 404 без свежего update
apt-get update -qq >>/var/log/wlsearch-apt.log 2>&1
apt-get install -y -qq nginx-light openssl >>/var/log/wlsearch-apt.log 2>&1 \\
  || apt-get install -y -qq nginx-core openssl >>/var/log/wlsearch-apt.log 2>&1 \\
  || apt-get install -y -qq nginx openssl >>/var/log/wlsearch-apt.log 2>&1

NGX_OK=0
if command -v nginx >/dev/null 2>&1; then
  openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \\
    -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \\
    -subj "/CN=\${IP}/O=wlsearch-probe" 2>/dev/null
  cat > /etc/nginx/sites-available/default <<'NGX'
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
  mkdir -p /etc/nginx/sites-enabled
  ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
  rm -f /var/www/html/index.nginx-debian.html 2>/dev/null || true
  if nginx -t >>/var/log/wlsearch-cloud-init.log 2>&1; then
    systemctl enable nginx 2>/dev/null || true
    systemctl restart nginx || service nginx restart
    NGX_OK=1
  fi
fi

if [ "\$NGX_OK" != "1" ]; then
  echo "nginx failed — python fallback"
  base64 -d > /usr/local/bin/wlsearch-probe.py <<'WLSEARCH_PY_B64'
{$pyB64Wrapped}
WLSEARCH_PY_B64
  chmod +x /usr/local/bin/wlsearch-probe.py
  nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >>/var/log/wlsearch-probe.log 2>&1 &
fi

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
echo "wlsearch-probe done IP=\$IP ngx=\$NGX_OK \$(date -u -Iseconds)"
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
