#!/bin/sh
# Fast probe without apt. Usage: bash install-probe.sh 27 timeweb
set +e
RUN_ID="${1:-0}"
PROVIDER="${2:-timeweb}"
ufw --force disable 2>/dev/null
iptables -P INPUT ACCEPT 2>/dev/null
iptables -I INPUT 1 -p tcp --dport 80 -j ACCEPT 2>/dev/null
iptables -I INPUT 1 -p tcp --dport 443 -j ACCEPT 2>/dev/null
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log
IP=$(hostname -I 2>/dev/null | awk '{print $1}')
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%s %s %s\n' "$PROVIDER" "$RUN_ID" "$IP" "$TS" > /var/www/html/index.html
pkill -f wlsearch-probe.py 2>/dev/null
fuser -k 80/tcp 443/tcp 2>/dev/null
cat > /usr/local/bin/wlsearch-probe.py <<'PY'
#!/usr/bin/env python3
import http.server, ssl, threading, pathlib, subprocess, os
ROOT=pathlib.Path("/var/www/html"); SSL=pathlib.Path("/etc/wlsearch-ssl")
BODY=ROOT.joinpath("index.html").read_bytes()
CRT,KEY=SSL/"probe.crt",SSL/"probe.key"
def cert(cn):
  SSL.mkdir(parents=True, exist_ok=True)
  if CRT.exists() and KEY.exists(): return True
  try:
    subprocess.check_call(["openssl","req","-x509","-nodes","-newkey","rsa:2048","-days","3650","-keyout",str(KEY),"-out",str(CRT),"-subj",f"/CN={cn}/O=wlsearch"],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL); return True
  except Exception: return False
class H(http.server.BaseHTTPRequestHandler):
  protocol_version="HTTP/1.1"
  def do_GET(self):
    self.send_response(200); self.send_header("Content-Type","text/plain; charset=utf-8"); self.send_header("Content-Length",str(len(BODY))); self.send_header("Connection","close"); self.end_headers(); self.wfile.write(BODY)
  def log_message(self,*a): pass
def serve(p,tls=False):
  s=http.server.HTTPServer(("0.0.0.0",p),H); s.allow_reuse_address=True
  if tls:
    c=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER); c.load_cert_chain(str(CRT),str(KEY)); s.socket=c.wrap_socket(s.socket,server_side=True)
  s.serve_forever()
cn=os.environ.get("WLSEARCH_IP","x")
threading.Thread(target=serve,args=(80,False),daemon=True).start()
if cert(cn): threading.Thread(target=serve,args=(443,True),daemon=True).start()
threading.Event().wait()
PY
chmod +x /usr/local/bin/wlsearch-probe.py
nohup env WLSEARCH_IP="$IP" python3 /usr/local/bin/wlsearch-probe.py >/var/log/wlsearch-probe.log 2>&1 &
sleep 1
curl -sS http://127.0.0.1/; echo
ss -lntp | grep -E ':80|:443'
