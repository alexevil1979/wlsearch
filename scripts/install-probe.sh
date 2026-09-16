#!/bin/sh
# Manual nginx probe. Usage: bash install-probe.sh 26 timeweb
set -e
RUN_ID="${1:-0}"
PROVIDER="${2:-timeweb}"
export DEBIAN_FRONTEND=noninteractive
mkdir -p /var/www/html /etc/nginx/ssl /var/log
IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{print $7; exit}' || true)
if [ -z "$IP" ]; then IP=$(hostname -I 2>/dev/null | awk '{print $1}'); fi
TS=$(date -u +%Y-%m-%dT%H:%M:%SZ)
printf 'WL_PROBE_OK %s run_%s %s %s\n' "$PROVIDER" "$RUN_ID" "$IP" "$TS" > /var/www/html/index.html
pkill -f wlsearch-probe.py 2>/dev/null || true
fuser -k 80/tcp 443/tcp 2>/dev/null || true
apt-get install -y -qq nginx openssl || { apt-get update -qq; apt-get install -y -qq nginx openssl; }
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
  -keyout /etc/nginx/ssl/probe.key -out /etc/nginx/ssl/probe.crt \
  -subj "/CN=${IP}/O=wlsearch-probe" 2>/dev/null
cat > /etc/nginx/sites-available/default <<'NGX'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.html;
    server_name _;
    location / { try_files $uri /index.html; }
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
    location / { try_files $uri /index.html; }
}
NGX
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
rm -f /var/www/html/index.nginx-debian.html 2>/dev/null || true
nginx -t && systemctl restart nginx
iptables -I INPUT -p tcp --dport 80 -j ACCEPT 2>/dev/null || true
iptables -I INPUT -p tcp --dport 443 -j ACCEPT 2>/dev/null || true
ufw allow 80/tcp 2>/dev/null || true
ufw allow 443/tcp 2>/dev/null || true
curl -sS http://127.0.0.1/; echo
