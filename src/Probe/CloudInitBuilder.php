<?php

declare(strict_types=1);

namespace Wlsearch\Probe;

/**
 * Probe user-data для Timeweb/Selectel/Yandex.
 *
 * Важно: сначала python (без apt) — иначе cloud-init часами висит на
 * apt-get update/nginx, снаружи Connection refused / timeout.
 * nginx-light — только фоном и только если конфиг валиден (не убивает probe зря).
 *
 * Create/reinstall: #cloud-config с bootcmd + per-boot — probe ставится
 * на каждом буте (после обычного reboot и после updateMetadata).
 *
 * Serial console (Yandex): логин wl / пароль DEFAULT — не root
 * (cloud-init на Ubuntu не создаёт root-пользователя).
 */
final class CloudInitBuilder
{
    /** Пароль по умолчанию (create + кнопка «root» в Runs). */
    public const DEFAULT_ROOT_PASSWORD = 'qweasd333123';

    /**
     * Логин для serial/SSH. Не используем root в cloud-init users —
     * Yandex/Ubuntu его игнорируют; root разблокируем скриптом.
     */
    public const LOGIN_USER = 'wl';

    public static function oneLiner(string $provider, int $runId, ?string $rootPassword = null): string
    {
        $inner = self::normalizeLf(self::installScriptBody(
            $provider,
            $runId,
            $rootPassword ?? self::DEFAULT_ROOT_PASSWORD
        ));
        return 'echo ' . base64_encode($inner) . ' | base64 -d | bash';
    }

    public static function forRun(string $provider, int $runId, ?string $rootPassword = null): string
    {
        return self::cloudConfig(
            $provider,
            $runId,
            'create',
            $rootPassword ?? self::DEFAULT_ROOT_PASSWORD
        );
    }

    /** Сырой bash для ручной вставки / сериал-консоли. */
    public static function manualInstallBash(
        string $provider,
        int $runId,
        ?string $rootPassword = null
    ): string {
        $runId = (int) $runId;
        $body = self::normalizeLf(self::installScriptBody(
            $provider,
            $runId,
            $rootPassword ?? self::DEFAULT_ROOT_PASSWORD
        ));
        return "#!/bin/sh\n# wlsearch probe run_{$runId}\n" . $body;
    }

    /**
     * Повторная установка после reboot: тот же cloud-config (bootcmd + per-boot).
     */
    public static function forRerun(string $provider, int $runId, ?string $rootPassword = null): string
    {
        return self::cloudConfig(
            $provider,
            $runId,
            'reinstall',
            $rootPassword ?? self::DEFAULT_ROOT_PASSWORD
        );
    }

    /**
     * Пароль + probe — кнопка «root» на уже живой VM.
     */
    public static function forSetRootPassword(
        string $provider,
        int $runId,
        ?string $rootPassword = null
    ): string {
        return self::cloudConfig(
            $provider,
            $runId,
            'setpass',
            $rootPassword ?? self::DEFAULT_ROOT_PASSWORD
        );
    }

    /** @deprecated используйте DEFAULT_ROOT_PASSWORD */
    public static function generateRootPassword(int $bytes = 9): string
    {
        return self::DEFAULT_ROOT_PASSWORD;
    }

    /** Санитизация пароля для YAML / chpasswd. */
    public static function sanitizeRootPassword(string $password): string
    {
        $pass = preg_replace('/[^A-Za-z0-9]/', '', $password) ?? '';
        return $pass !== '' ? $pass : self::DEFAULT_ROOT_PASSWORD;
    }

    private static function cloudConfig(
        string $provider,
        int $runId,
        string $tag,
        ?string $rootPassword = null
    ): string {
        $runId = (int) $runId;
        $tag = preg_replace('/[^a-z0-9_\-]/i', '', $tag) ?: 'run';
        $pass = self::sanitizeRootPassword((string) ($rootPassword ?? self::DEFAULT_ROOT_PASSWORD));
        $script = self::manualInstallBash($provider, $runId, $pass);
        $b64 = base64_encode($script);

        // ВАЖНО: не использовать users:/chpasswd:/disable_root в YAML —
        // на Yandex Ubuntu cloud-init 26.x зависает на Network Stage
        // (Stop/Start Network Configuration → нет login:).
        // Пароль и user wl — только bash.
        //
        // Тяжёлый probe НЕ в синхронном bootcmd (ломает сеть cloud-init).
        // Auth — лёгкий bootcmd; probe — runcmd + отложенный фон + per-boot.
        $user = self::LOGIN_USER;
        $authCmd = "id -u {$user} >/dev/null 2>&1 || useradd -m -s /bin/bash -G sudo {$user}; "
            . "echo '{$user}:{$pass}' | chpasswd; "
            . "echo 'root:{$pass}' | chpasswd; "
            . "passwd -u root 2>/dev/null; usermod -U root 2>/dev/null; "
            . "passwd -u {$user} 2>/dev/null; usermod -U {$user} 2>/dev/null; "
            . "echo '{$user} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/wlsearch; chmod 440 /etc/sudoers.d/wlsearch; "
            . "mkdir -p /etc/ssh/sshd_config.d; "
            . "rm -f /etc/ssh/sshd_config.d/50-cloud-init.conf /etc/ssh/sshd_config.d/*-cloud-init.conf; "
            . "printf 'PasswordAuthentication yes\\nPermitRootLogin yes\\n' > /etc/ssh/sshd_config.d/99-wlsearch.conf; "
            . "true";

        // После сети (sleep), чтобы не гонять systemctl/iptables во время Network Stage
        $deferredProbe = "nohup bash -c 'sleep 25; echo {$b64} | base64 -d | bash' "
            . ">>/var/log/wlsearch-deferred.log 2>&1 &";

        return "#cloud-config\n"
            . "# wlsearch probe {$tag} run_{$runId}\n"
            . "ssh_pwauth: true\n"
            . "write_files:\n"
            . "  - path: /var/lib/cloud/scripts/per-boot/99-wlsearch.sh\n"
            . "    permissions: '0755'\n"
            . "    encoding: b64\n"
            . "    content: {$b64}\n"
            . "  - path: /usr/local/bin/wlsearch-bootstrap.sh\n"
            . "    permissions: '0755'\n"
            . "    encoding: b64\n"
            . "    content: {$b64}\n"
            . "bootcmd:\n"
            . "  - [ bash, -c, \"{$authCmd}\" ]\n"
            . "  - [ bash, -c, \"{$deferredProbe}\" ]\n"
            . "runcmd:\n"
            . "  - [ bash, /usr/local/bin/wlsearch-bootstrap.sh ]\n";
    }

    private static function normalizeLf(string $s): string
    {
        return str_replace(["\r\n", "\r"], "\n", $s);
    }

    private static function installScriptBody(
        string $provider,
        int $runId,
        ?string $rootPassword = null
    ): string {
        $provider = preg_replace('/[^a-z0-9_\-]/i', '', $provider) ?: 'unknown';
        $runId = (int) $runId;
        $pass = self::sanitizeRootPassword((string) ($rootPassword ?? self::DEFAULT_ROOT_PASSWORD));
        $user = self::LOGIN_USER;
        $pyB64 = base64_encode(self::probePythonSource());
        $pyB64Wrapped = trim(chunk_split($pyB64, 76, "\n"));

        return <<<SH
set +e
exec >>/var/log/wlsearch-cloud-init.log 2>&1
echo "wlsearch-probe start \$(date -u -Iseconds)"
export DEBIAN_FRONTEND=noninteractive
mkdir -p /var/www/html /etc/wlsearch-ssl /usr/local/bin /var/log /etc/nginx/ssl /etc/default /etc/ssh/sshd_config.d

# 0) пароль + serial/SSH (Ubuntu/Yandex: root часто locked; user {$user} для консоли)
id -u {$user} >/dev/null 2>&1 || useradd -m -s /bin/bash -G sudo {$user} 2>/dev/null
echo '{$user}:{$pass}' | chpasswd
echo 'root:{$pass}' | chpasswd
passwd -u root 2>/dev/null || true
usermod -U root 2>/dev/null || true
usermod -s /bin/bash root 2>/dev/null || true
passwd -u {$user} 2>/dev/null || true
usermod -U {$user} 2>/dev/null || true
echo '{$user} ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/wlsearch
chmod 440 /etc/sudoers.d/wlsearch
# cloud-init часто кладёт PasswordAuthentication no в sshd_config.d
rm -f /etc/ssh/sshd_config.d/50-cloud-init.conf /etc/ssh/sshd_config.d/*-cloud-init.conf 2>/dev/null
printf '%s\\n' 'PasswordAuthentication yes' 'PermitRootLogin yes' 'KbdInteractiveAuthentication yes' > /etc/ssh/sshd_config.d/99-wlsearch.conf
sed -i 's/^#\\?PermitRootLogin.*/PermitRootLogin yes/' /etc/ssh/sshd_config 2>/dev/null
sed -i 's/^#\\?PasswordAuthentication.*/PasswordAuthentication yes/' /etc/ssh/sshd_config 2>/dev/null
systemctl reload ssh 2>/dev/null || systemctl reload sshd 2>/dev/null || true
echo "wlsearch-auth user={$user} root=unlocked \$(date -u -Iseconds)"

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
printf 'WLSEARCH_IP=%s\\n' "\$IP" > /etc/default/wlsearch-probe
cat > /etc/systemd/system/wlsearch-probe.service <<'EOF'
[Unit]
Description=wlsearch probe
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
EnvironmentFile=-/etc/default/wlsearch-probe
ExecStart=/usr/bin/python3 /usr/local/bin/wlsearch-probe.py
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload 2>/dev/null
systemctl enable wlsearch-probe.service 2>/dev/null
systemctl restart wlsearch-probe.service 2>/dev/null \\
  || nohup env WLSEARCH_IP="\$IP" python3 /usr/local/bin/wlsearch-probe.py >>/var/log/wlsearch-probe.log 2>&1 &
sleep 1
curl -sS -m 2 http://127.0.0.1/ | head -c 120
echo
echo "python probe up \$(date -u -Iseconds)"

# 3) nginx опционально в фоне (не блокирует boot; порты забирает только после nginx -t)
(
  apt-get update -qq >>/var/log/wlsearch-apt.log 2>&1
  apt-get install -y -qq nginx-light openssl >>/var/log/wlsearch-apt.log 2>&1 \\
    || apt-get install -y -qq nginx-core openssl >>/var/log/wlsearch-apt.log 2>&1 || exit 0
  command -v nginx >/dev/null 2>&1 || exit 0
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
  nginx -t || exit 0
  systemctl stop wlsearch-probe.service 2>/dev/null
  pkill -f wlsearch-probe.py 2>/dev/null
  fuser -k 80/tcp 443/tcp 2>/dev/null
  systemctl restart nginx
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
