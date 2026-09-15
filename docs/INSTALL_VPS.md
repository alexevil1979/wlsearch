# Установка wlsearch на VPS с нуля

Пошаговый runbook для **чистого** Debian/Ubuntu VPS (или существующего сервера рядом с RushVPN — **отдельный** vhost и каталог).

| Параметр | Значение |
|----------|----------|
| Код | `/ssd/www/wlsearch` |
| Домен | `wlsearch.1tlt.ru` |
| DocumentRoot | `/ssd/www/wlsearch/public` |
| Web | Apache2 + php8.2-fpm |
| БД | MySQL 5.7 (отдельная БД `wlsearch`) |
| SSL | Certbot Let’s Encrypt |
| Репозиторий | https://github.com/alexevil1979/wlsearch |

**Нельзя:** ставить DocumentRoot в `/ssd/www/rushvpn.site`, Docker Compose как прод-деплой, светить cloud-токены в git.

Секреты в чат/тикеты не копировать — только в `.env` на сервере (`chmod 600`).

---

## 0. Что нужно заранее

1. VPS с root/sudo и публичным IPv4.
2. Доступ к DNS зоны `1tlt.ru` (или вашего домена).
3. Токен Timeweb Cloud и/или учётка Selectel OpenStack (можно добавить после базовой установки).
4. ~15–30 минут.

Опционально: Telegram bot token + chat id.

---

## 1. DNS

Создайте **A-запись**:

```
wlsearch.1tlt.ru  →  <публичный IPv4 этого VPS>
```

Проверка (с вашего ПК или с VPS):

```bash
dig +short wlsearch.1tlt.ru A
# должен вернуть IP сервера
```

Пока DNS не резолвится — certbot не получит сертификат.

---

## 2. Базовые пакеты

Подключение по SSH:

```bash
ssh root@YOUR_VPS_IP
```

### 2.1. Ubuntu 22.04 / Debian 12 (типичный случай)

На свежих дистрибутивах `apt install mysql-server` часто даёт **MySQL 8**. Для требования **MySQL 5.7**:

- если на сервере **уже есть** MySQL 5.7 (как у многих прод-хостов с RushVPN) — **используйте его**, новые пакеты MySQL не ставьте;
- если MySQL нет и 5.7 из репозитория недоступен — варианты:
  - поставить MariaDB 10.3/10.4 (совместим с клиентским SQL приложения) **только если явно согласны**;
  - или поставить MySQL 5.7 из vendor/архивного репо вручную.

Ниже — установка **Apache + PHP 8.2 + клиент к уже существующему MySQL 5.7** (рекомендуемый сценарий на shared VPS):

```bash
sudo apt update
sudo apt install -y software-properties-common ca-certificates curl gnupg git

# PHP 8.2 (Ubuntu: ondrej/php; на Debian — свой способ получить 8.2)
sudo add-apt-repository -y ppa:ondrej/php   # Ubuntu
sudo apt update

sudo apt install -y apache2 \
  php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml php8.2-zip \
  certbot python3-certbot-apache

# MySQL client (если сервер БД уже локальный)
sudo apt install -y mysql-client || sudo apt install -y default-mysql-client

sudo a2enmod rewrite proxy_fcgi setenvif headers ssl
sudo a2enconf php8.2-fpm
sudo systemctl enable --now apache2 php8.2-fpm
```

Проверка версий:

```bash
php8.2 -v          # PHP 8.2.x
apache2 -v
mysql --version    # или mysql -uroot -e "SELECT VERSION();"
```

Убедитесь, что `SELECT VERSION();` показывает **5.7.x** (или согласованный совместимый сервер).

### 2.2. Если MySQL 5.7 ещё нет на хосте

Установите/поднимите MySQL 5.7 по политике вашего хостинга, затем:

```bash
sudo systemctl enable --now mysql
# или mysqld
```

Не обновляйте существующий MySQL 5.7 до 8 «заодно» — могут пострадать другие проекты на том же VPS.

---

## 3. Каталог и клон кода

```bash
sudo mkdir -p /ssd/www
sudo git clone https://github.com/alexevil1979/wlsearch.git /ssd/www/wlsearch
cd /ssd/www/wlsearch

sudo cp .env.example .env
sudo chmod 600 .env

# Права: код читает www-data; .env только владелец
sudo chown -R www-data:www-data /ssd/www/wlsearch
sudo chmod 750 /ssd/www/wlsearch
sudo chmod 600 /ssd/www/wlsearch/.env

sudo mkdir -p storage/logs storage/cache
sudo chown -R www-data:www-data storage
```

Composer **не обязателен** (autoload встроен в `src/bootstrap.php`).

---

## 4. MySQL: база и пользователь

Войдите в MySQL под админом:

```bash
sudo mysql
# или: mysql -uroot -p
```

Выполните (пароль придумайте сами, **не** коммитьте):

```sql
CREATE DATABASE IF NOT EXISTS wlsearch
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'wlsearch'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON wlsearch.* TO 'wlsearch'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

На чистом MySQL 5.7 без `CREATE USER IF NOT EXISTS`:

```sql
CREATE DATABASE wlsearch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wlsearch'@'localhost' IDENTIFIED BY 'CHANGE_ME_STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON wlsearch.* TO 'wlsearch'@'localhost';
FLUSH PRIVILEGES;
```

Проверка:

```bash
mysql -u wlsearch -p -h 127.0.0.1 wlsearch -e "SELECT 1;"
```

---

## 5. Файл `.env`

```bash
sudo -u www-data nano /ssd/www/wlsearch/.env
```

Минимум для старта админки:

```env
APP_NAME=wlsearch
APP_ENV=production
APP_DEBUG=false
APP_URL=https://wlsearch.1tlt.ru
APP_TIMEZONE=Europe/Moscow

ADMIN_LOGIN=admin
ADMIN_PASSWORD=CHANGE_ME_STRONG_ADMIN_PASSWORD

SESSION_NAME=wlsearch_sess
SESSION_SECURE=true

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=wlsearch
DB_USERNAME=wlsearch
DB_PASSWORD=CHANGE_ME_STRONG_DB_PASSWORD
DB_CHARSET=utf8mb4

MAX_PARALLEL_VMS=3
MAX_CREATES_PER_DAY=20
MAX_DAILY_SPEND_RUB=500
```

### Timeweb (рекомендуется первым)

```env
TIMEWEB_API_TOKEN=twc_...
TIMEWEB_API_BASE=https://api.timeweb.cloud/api/v1
TIMEWEB_PRESET_ID=4795
TIMEWEB_OS_ID=99
TIMEWEB_AVAILABILITY_ZONE=spb-3
TIMEWEB_BANDWIDTH=200
TIMEWEB_PRESET_COST_RUB=0
```

`PRESET_ID` / `OS_ID` возьмите из панели Timeweb или API (`GET /presets`, `GET /os`) — числа из примеров могут не совпасть с вашим аккаунтом.

### Selectel (опционально)

```env
SELECTEL_AUTH_URL=https://cloud.api.selcloud.ru/identity/v3
SELECTEL_USERNAME=...
SELECTEL_PASSWORD=...
SELECTEL_PROJECT_ID=...
SELECTEL_USER_DOMAIN_NAME=...
SELECTEL_PROJECT_DOMAIN_NAME=...
SELECTEL_REGION=ru-9a
SELECTEL_FLAVOR_ID=...
SELECTEL_IMAGE_ID=...
SELECTEL_NETWORK_ID=...
SELECTEL_EXTERNAL_NET_ID=...
```

### Telegram (опционально)

```env
TELEGRAM_BOT_TOKEN=
TELEGRAM_CHAT_ID=
```

После правок:

```bash
sudo chmod 600 /ssd/www/wlsearch/.env
sudo chown www-data:www-data /ssd/www/wlsearch/.env
```

---

## 6. Миграции БД

На хостах с общим `open_basedir` (часто `/ssd/www/botfabric:...` без wlsearch) обычный вызов падает. Используйте обёртку или `-d open_basedir=`:

```bash
cd /ssd/www/wlsearch
sudo chmod +x bin/wlsearch-run

# рекомендуется:
sudo -u www-data ./bin/wlsearch-run migrate
sudo -u www-data ./bin/wlsearch-run health

# эквивалент:
# sudo -u www-data php8.2 -d open_basedir= bin/wlsearch migrate
# ожидается: db=up phase=4
```

Если ошибка подключения — проверьте `DB_*` и `GRANT`.  
Если снова `open_basedir restriction` — см. §13.

---

## 7. Apache vhost

Готовые конфиги в репо (PHP-FPM по TCP `127.0.0.1:9000`):

| Файл | Назначение |
|------|------------|
| `deploy/apache-wlsearch.1tlt.ru.conf` | :80 → редирект на HTTPS |
| `deploy/apache-wlsearch.1tlt.ru-le-ssl.conf` | :443 + Let’s Encrypt + PHP |
| `deploy/apache-wlsearch.1tlt.ru-http-php.conf` | :80 с PHP (только отладка) |

```bash
sudo a2enmod rewrite proxy proxy_fcgi setenvif headers ssl

sudo cp /ssd/www/wlsearch/deploy/apache-wlsearch.1tlt.ru.conf \
  /etc/apache2/sites-available/wlsearch.1tlt.ru.conf
sudo cp /ssd/www/wlsearch/deploy/apache-wlsearch.1tlt.ru-le-ssl.conf \
  /etc/apache2/sites-available/wlsearch.1tlt.ru-le-ssl.conf

# Уберите старые/битые варианты, если мешают:
# sudo a2dissite wlsearch.1tlt.ru-le-ssl.conf  # перед заменой — по желанию

sudo a2ensite wlsearch.1tlt.ru.conf
sudo a2ensite wlsearch.1tlt.ru-le-ssl.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

В `FilesMatch` должно быть:

```apache
SetHandler "proxy:fcgi://127.0.0.1:9000"
```

Cloudflare SSL: **Full** / **Full (strict)**. На :443 не должно быть `Redirect` на https.

---

## 8. SSL (Certbot)

DNS должен уже указывать на этот VPS. Порты **80** и **443** открыты в firewall.

```bash
# если ufw:
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw status

sudo certbot --apache -d wlsearch.1tlt.ru
sudo systemctl reload apache2
```

После certbot проверьте, что в SSL-vhost остались:

- DocumentRoot → `/ssd/www/wlsearch/public`
- handler `php8.2-fpm`
- `SetEnvIf Authorization` (без `CGIPassAuth`)

Проверка:

```bash
curl -sS https://wlsearch.1tlt.ru/health
# {"status":"ok","db":"up",...}
```

В браузере: https://wlsearch.1tlt.ru/ — форма входа (`ADMIN_LOGIN` / `ADMIN_PASSWORD`).

---

## 9. Cron worker

```bash
sudo crontab -u www-data -e
```

Добавьте строку:

```cron
* * * * * cd /ssd/www/wlsearch && ./bin/wlsearch-run worker >> /ssd/www/wlsearch/storage/logs/worker.log 2>&1
```

(альтернатива: `/usr/bin/php8.2 -d open_basedir= bin/wlsearch worker`)

Проверка через минуту:

```bash
sudo tail -n 50 /ssd/www/wlsearch/storage/logs/worker.log
# worker tick start / done
```

Права на лог:

```bash
sudo chown -R www-data:www-data /ssd/www/wlsearch/storage
```

---

## 10. Первый рабочий цикл

### 10.1. Админка

1. Войти на https://wlsearch.1tlt.ru/
2. **Настройки** — при необходимости подкрутить лимиты
3. **Запуск** — provider `timeweb`, region, count=1
4. **Runs** — дождаться `CONTROL_CHECK` → `BS_CHECK` (смотрится worker.log)

### 10.2. Phone-agent (Termux)

На сервере:

```bash
cd /ssd/www/wlsearch
sudo -u www-data php8.2 bin/wlsearch agent-token:create --name=phone-mts --operator=mts
# сохраните TOKEN — показывается один раз
```

Или: админка → **Agents** → создать.

На Android (Termux), Wi‑Fi **выкл**, VPN **выкл**:

```bash
pkg install curl python
# скопируйте agents/termux/wlsearch_agent.sh на телефон
export WLSEARCH_URL=https://wlsearch.1tlt.ru
export WLSEARCH_TOKEN='wls_...'
bash wlsearch_agent.sh
```

После PASS IP появится в **Inventory**.

Подробнее: [PHONE_AGENT.md](PHONE_AGENT.md).

---

## 11. Firewall / безопасность (рекомендуется)

```bash
# пример ufw — не режьте SSH
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 443/tcp
sudo ufw enable
```

Дополнительно:

- сильный `ADMIN_PASSWORD`
- опциональный IP allowlist в Apache conf (`Require ip ...`)
- `.env` chmod 600, вне webroot (DocumentRoot только `public/`)
- отдельные cloud-проекты только под ферму wlsearch
- лимиты `MAX_PARALLEL_VMS` / `MAX_CREATES_PER_DAY` / spend

---

## 12. Обновление с GitHub

```bash
cd /ssd/www/wlsearch
sudo -u www-data git pull origin main
sudo -u www-data ./bin/wlsearch-run migrate
sudo systemctl reload php8.2-fpm
```

`.env` не перезаписывается pull’ом (в `.gitignore`).

---

## 13. Типовые проблемы

| Симптом | Что проверить |
|---------|----------------|
| `/health` → HTML **301** / `Maximum redirects` | Cloudflare Flexible + редирект на origin, или лишний `Redirect` в SSL-vhost. См. § Cloudflare ниже. |
| `/health` локально **503** на :443 | Неверный socket PHP-FPM в vhost. См. § 503 / FPM ниже. |
| `There is no active transaction` на migrate | Исправлено в коде (DDL MySQL). `git pull` и снова `./bin/wlsearch-run migrate` |
| `db=down` в `/health` | MySQL up, `DB_*`, grants, `127.0.0.1` vs `localhost` (socket) |
| 404 Apache | DocumentRoot = `.../public`, site enabled, DNS |
| Белый экран PHP | `storage/logs`, `php8.2-fpm` status, `error.log` Apache |
| Certbot fail | DNS A, порт 80, нет чужого vhost на том же имени |
| Agent 401 | token; `SetEnvIf Authorization` / `.htaccess` HTTP_AUTHORIZATION; не использовать CGIPassAuth в VirtualHost |
| Create Timeweb fail | token, preset/os id, лимиты, баланс облака |
| Selectel без IP | `SELECTEL_EXTERNAL_NET_ID` + network id |
| Worker молчит | crontab www-data, `wlsearch-run` / `-d open_basedir=`, права на `storage/logs` |

### curl отдаёт 301 / Maximum redirects (Cloudflare)

У вас в заголовках `server: cloudflare`. Типичная петля:

1. Cloudflare в режиме **Flexible** ходит на origin по **HTTP :80**
2. Origin (certbot) отвечает `301 → https://wlsearch.1tlt.ru/...`
3. Cloudflare снова запрашивает… и так по кругу

**Исправление в Cloudflare (главное):**

- SSL/TLS → Overview → режим **Full** или **Full (strict)** (не Flexible)
- Always Use HTTPS можно оставить On
- Подождите 30–60 сек, затем:

```bash
curl -sSI https://wlsearch.1tlt.ru/health
curl -sSL https://wlsearch.1tlt.ru/health
```

**Исправление на origin (SSL vhost не должен редиректить сам на себя):**

```bash
sudo grep -nE 'Redirect|RewriteRule.*https' \
  /etc/apache2/sites-enabled/wlsearch.1tlt.ru.conf \
  /etc/apache2/sites-enabled/wlsearch.1tlt.ru-le-ssl.conf

# В *-le-ssl.conf (порт 443) НЕ должно быть:
#   Redirect permanent / https://wlsearch.1tlt.ru/
# Редирект HTTP→HTTPS — только в conf на порту 80.
```

Проверка **минуя Cloudflare** (подставьте IP сервера):

```bash
curl -sSIk --resolve wlsearch.1tlt.ru:443:127.0.0.1 https://wlsearch.1tlt.ru/health
```

### 503 на https://127.0.0.1 (PHP-FPM socket)

`503 Service Unavailable` на :443 = Apache не может достучаться до php-fpm (неверный `.sock`).

```bash
# найти реальный socket
ls -la /run/php/*.sock 2>/dev/null
ls -la /usr/local/php82/var/run/*.sock 2>/dev/null
ls -la /var/run/php/*.sock 2>/dev/null

# что прописано в vhost
grep -n SetHandler /etc/apache2/sites-enabled/wlsearch*.conf
```

В **обоих** файлах (`wlsearch.1tlt.ru.conf` и `wlsearch.1tlt.ru-le-ssl.conf`) выставьте правильный socket, например:

```apache
<FilesMatch \.php$>
    SetHandler "proxy:unix:/usr/local/php82/var/run/php-fpm.sock|fcgi://localhost"
</FilesMatch>
```

(путь возьмите из `ls` выше — на servv часто `/usr/local/php82/...`, а не `/run/php/php8.2-fpm.sock`)

Также добавьте `open_basedir` с `/ssd/www/wlsearch` в этот же SSL/HTTP vhost или в pool FPM.

```bash
sudo apache2ctl configtest
sudo systemctl reload apache2
# перезапуск вашего php-fpm, например:
sudo systemctl restart php8.2-fpm 2>/dev/null || sudo systemctl restart php-fpm82 2>/dev/null || true

curl -sSIk --resolve wlsearch.1tlt.ru:443:127.0.0.1 https://wlsearch.1tlt.ru/health
curl -sSLk --resolve wlsearch.1tlt.ru:443:127.0.0.1 https://wlsearch.1tlt.ru/health
```

### open_basedir (частый кейс на servv)

Ошибка вида:

```text
open_basedir restriction in effect.
File(/ssd/www/wlsearch/src/bootstrap.php) is not within the allowed path(s):
(/ssd/www/botfabric:/ssd/www/testtelega:...)
```

**Сразу (CLI):**

```bash
cd /ssd/www/wlsearch
sudo chmod +x bin/wlsearch-run
sudo -u www-data ./bin/wlsearch-run migrate
sudo -u www-data ./bin/wlsearch-run health
```

**Постоянно для CLI** — найти, откуда берётся ограничение:

```bash
php8.2 -i | grep -i open_basedir
# или
grep -R "open_basedir" /usr/local/php82/etc/ /etc/php/8.2/ 2>/dev/null
```

В `php.ini` для CLI добавьте `/ssd/www/wlsearch` в список (через `:`), например:

```ini
open_basedir = /ssd/www/botfabric:/ssd/www/testtelega:/ssd/www/tradesignals:/ssd/www/wlsearch:/usr/local/bin:/tmp:/usr/local/php82:/dev/urandom
```

**Для веб-админки (FPM/Apache):** в pool сайта `wlsearch.1tlt.ru` или в vhost:

```apache
php_admin_value open_basedir "/ssd/www/wlsearch:/tmp:/usr/local/php82:/dev/urandom"
```

либо добавьте `/ssd/www/wlsearch` к уже существующему `php_admin_value open_basedir` и перезапустите FPM/Apache:

```bash
sudo systemctl reload php8.2-fpm
# или ваш сервис php-fpm82
sudo systemctl reload apache2
```

Без этого сайт может отдавать 500 даже если CLI через `-d` уже работает.

Логи:

```bash
sudo tail -f /var/log/apache2/wlsearch_error.log
sudo tail -f /ssd/www/wlsearch/storage/logs/worker.log
sudo journalctl -u php8.2-fpm -n 50 --no-pager
```

---

## 14. Чеклист «установили с нуля»

- [ ] DNS `wlsearch.1tlt.ru` → IP VPS
- [ ] Каталог `/ssd/www/wlsearch`, `.env` chmod 600
- [ ] MySQL БД `wlsearch` + user, `migrate` OK
- [ ] Apache vhost → `public/`, не пересекается с rushvpn
- [ ] HTTPS certbot
- [ ] `curl https://wlsearch.1tlt.ru/health` → `status=ok`
- [ ] Логин в админку
- [ ] Cron worker каждую минуту
- [ ] Timeweb и/или Selectel в `.env`
- [ ] (Опц.) agent token + Termux → PASS в Inventory

---

## Связанные документы

- [ARCHITECTURE.md](ARCHITECTURE.md) — схема и state machine  
- [PROVIDERS.md](PROVIDERS.md) — Timeweb / Selectel  
- [PHONE_AGENT.md](PHONE_AGENT.md) — агент  
- [RUNBOOK.md](RUNBOOK.md) — повседневные операции  
- [THREAT_MODEL.md](THREAT_MODEL.md) — угрозы  
- [DEPLOY.md](DEPLOY.md) — краткая шпаргалка (ссылается сюда)
