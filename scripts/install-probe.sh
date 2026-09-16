#!/bin/sh
# Manual fallback when create-time cloud-init did not start probe.
# Usage: bash install-probe.sh 26 timeweb
# (same body as CloudInitBuilder::forRun — keep in sync conceptually)
set -e
RUN_ID="${1:-0}"
PROVIDER="${2:-timeweb}"
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log
IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}' || true)
if [ -z "$IP" ]; then IP=$(hostname -I 2>/dev/null | awk '{print $1}'); fi
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%s %s %s\n' "$PROVIDER" "$RUN_ID" "$IP" "$TS" > /var/www/html/index.html
chmod 644 /var/www/html/index.html
(command -v fuser >/dev/null 2>&1 && fuser -k 80/tcp 443/tcp) || true
systemctl stop nginx 2>/dev/null || true
pkill -f wlsearch-probe.py 2>/dev/null || true
if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -qi 'Status: active'; then
  ufw allow 80/tcp || true
  ufw allow 443/tcp || true
fi
iptables -C INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 80 -j ACCEPT || true
iptables -C INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || iptables -I INPUT -p tcp --dport 443 -j ACCEPT || true
cat > /usr/local/bin/wlsearch-probe.py <<'PY'
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
    def do_GET(self):
        self.send_response(200); self.send_header("Content-Type","text/plain; charset=utf-8")
        self.send_header("Content-Length", str(len(BODY))); self.end_headers(); self.wfile.write(BODY)
    def log_message(self, *a): pass
def serve(port, tls=False):
    httpd = http.server.HTTPServer(("0.0.0.0", port), H)
    if tls:
        ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); ctx.load_cert_chain(str(CRT), str(KEY))
        httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True)
    httpd.serve_forever()
cn = os.environ.get("WLSEARCH_IP", "wlsearch")
threading.Thread(target=serve, args=(80, False), daemon=True).start()
if ensure_cert(cn):
    threading.Thread(target=serve, args=(443, True), daemon=True).start()
threading.Event().wait()
PY
chmod +x /usr/local/bin/wlsearch-probe.py
nohup env WLSEARCH_IP="$IP" python3 /usr/local/bin/wlsearch-probe.py >/var/log/wlsearch-probe.log 2>&1 &
sleep 1
echo "OK probe on $IP"
curl -sS http://127.0.0.1/ | head -c 200; echo
