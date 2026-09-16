<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

/**
 * User-data для Timeweb/Selectel.
 *
 * Timeweb docs: надёжнее передавать `#!/bin/sh` (они сами обернут в runcmd),
 * чем сложный #cloud-config с огромным base64 в YAML — тот часто молча не выполняется,
 * при этом ручной paste тех же команд на VPS работает сразу.
 */
final class CloudInitBuilder
{
    /**
     * Probe HTTP :80 + HTTPS :443 с телом WL_PROBE_OK …
     */
    public static function forRun(string $provider, int $runId): string
    {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;
        $pyB64 = base64_encode(self::probePythonSource());
        // Разбиваем base64 на короткие строки — безопаснее для shell/heredoc у провайдера
        $pyB64Wrapped = trim(chunk_split($pyB64, 76, "\n"));

        return <<<SH
#!/bin/sh
# wlsearch probe run_{$runId}
exec >>/var/log/wlsearch-cloud-init.log 2>&1
echo "wlsearch-cloud-init start \$(date -u -Iseconds)"
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log
IP=\$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print \$7; exit}')
if [ -z "\$IP" ]; then
  IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
fi
TS=\$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%d %s %s\\n' '{$provider}' {$runId} "\$IP" "\$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html
base64 -d > /usr/local/bin/wlsearch-probe.py <<'WLSEARCH_PY_B64'
{$pyB64Wrapped}
WLSEARCH_PY_B64
chmod +x /usr/local/bin/wlsearch-probe.py
(command -v fuser >/dev/null 2>&1 && fuser -k 80/tcp 443/tcp) || true
systemctl stop nginx 2>/dev/null || service nginx stop 2>/dev/null || true
pkill -f '/usr/local/bin/wlsearch-probe.py' 2>/dev/null || true
nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >>/var/log/wlsearch-probe.log 2>&1 &
sleep 1
echo "wlsearch-cloud-init done IP=\$IP \$(date -u -Iseconds)"
curl -sS -m 2 http://127.0.0.1/ | head -c 120 || true
echo
SH;
    }

    /**
     * Ручная установка probe на уже созданный VPS (если cloud-init не отработал).
     */
    public static function manualInstallBash(string $provider, int $runId): string
    {
        // Тот же скрипт, что уходит в API — чтобы ручной и авто путь совпадали
        return self::forRun($provider, $runId);
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
