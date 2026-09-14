# Deploy: wlsearch на VPS

Целевой стек (не менять без явной просьбы):

| Компонент | Значение |
|-----------|----------|
| Код | `/ssd/www/wlsearch` |
| Домен | `wlsearch.1tlt.ru` |
| Web | Apache2 (отдельный vhost, **не** rushvpn) |
| PHP | 8.2 (`php8.2` + предпочтительно `php8.2-fpm` + `proxy_fcgi`) |
| БД | MySQL 5.7, БД `wlsearch`, отдельный user |
| SSL | Certbot Let’s Encrypt |
| DocumentRoot | `/ssd/www/wlsearch/public` |

**Не** пересекать DocumentRoot с RushVPN (`/ssd/www/rushvpn.site`).

---

## 1. DNS

A-запись `wlsearch.1tlt.ru` → публичный IPv4 этого VPS.

---

## 2. Пакеты (Debian/Ubuntu)

```bash
sudo apt update
sudo apt install -y apache2 mysql-server \
  php8.2 php8.2-cli php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring php8.2-xml \
  certbot python3-certbot-apache git
sudo a2enmod rewrite proxy_fcgi setenvif headers ssl
sudo a2enconf php8.2-fpm
sudo systemctl enable --now apache2 php8.2-fpm mysql
```

Если на хосте MySQL 5.7 уже установлен отдельно — используйте его, не обновляйте до 8 без нужды.

---

## 3. Код

```bash
sudo mkdir -p /ssd/www/wlsearch
sudo git clone https://github.com/alexevil1979/wlsearch.git /ssd/www/wlsearch
cd /ssd/www/wlsearch
sudo cp .env.example .env
sudo chmod 600 .env
sudo chown -R www-data:www-data /ssd/www/wlsearch
# или владелец deploy-пользователя + группа www-data на storage/
```

Отредактируйте `/ssd/www/wlsearch/.env` вручную:

- `ADMIN_LOGIN` / `ADMIN_PASSWORD` — сильный пароль
- `DB_*` — доступы к MySQL
- `APP_URL=https://wlsearch.1tlt.ru`
- `SESSION_SECURE=true`

Composer **не обязателен**: есть PSR-4 autoload в `src/bootstrap.php`.  
Если хотите: `composer install --no-dev` (нужен `composer`).

---

## 4. MySQL 5.7

```sql
CREATE DATABASE wlsearch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'wlsearch'@'localhost' IDENTIFIED BY 'STRONG_DB_PASSWORD';
GRANT ALL PRIVILEGES ON wlsearch.* TO 'wlsearch'@'localhost';
FLUSH PRIVILEGES;
```

Миграции:

```bash
cd /ssd/www/wlsearch
sudo -u www-data php8.2 bin/wlsearch migrate
sudo -u www-data php8.2 bin/wlsearch health
```

---

## 5. Apache vhost

Черновик: `deploy/apache-wlsearch.1tlt.ru.conf`

```bash
sudo cp /ssd/www/wlsearch/deploy/apache-wlsearch.1tlt.ru.conf \
  /etc/apache2/sites-available/wlsearch.1tlt.ru.conf
sudo a2ensite wlsearch.1tlt.ru.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

### Пример vhost (HTTP → затем certbot)

```apache
<VirtualHost *:80>
    ServerName wlsearch.1tlt.ru
    DocumentRoot /ssd/www/wlsearch/public

    <Directory /ssd/www/wlsearch/public>
        AllowOverride All
        Require all granted
    </Directory>

    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.2-fpm.sock|fcgi://localhost"
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/wlsearch_error.log
    CustomLog ${APACHE_LOG_DIR}/wlsearch_access.log combined
</VirtualHost>
```

Опционально IP allowlist для админки (раскомментировать в conf).

---

## 6. SSL (Certbot)

```bash
sudo certbot --apache -d wlsearch.1tlt.ru
sudo systemctl reload apache2
```

Проверка:

```bash
curl -sS https://wlsearch.1tlt.ru/health
# {"status":"ok", ...}
```

Открыть https://wlsearch.1tlt.ru/ — страница логина.

---

## 7. Cron worker

```cron
* * * * * cd /ssd/www/wlsearch && /usr/bin/php8.2 bin/wlsearch worker >> storage/logs/worker.log 2>&1
```

В Phase 0 worker — no-op stub; с Phase 1 тикает state machine.

Права на логи:

```bash
sudo mkdir -p /ssd/www/wlsearch/storage/logs
sudo chown -R www-data:www-data /ssd/www/wlsearch/storage
```

---

## 8. Обновление кода

```bash
cd /ssd/www/wlsearch
sudo -u www-data git pull origin main
# при необходимости: php8.2 bin/wlsearch migrate
sudo systemctl reload php8.2-fpm
```

Секреты (`.env`) не коммитятся.

---

## 9. Чеклист приёмки (все фазы)

- [ ] HTTPS `wlsearch.1tlt.ru`, DocumentRoot = `public/`
- [ ] `/health` → ok, migrate OK, cron worker
- [ ] Timeweb и/или Selectel в `.env`
- [ ] Запуск run → CONTROL_OK → BS_CHECK
- [ ] Agent token + Termux → PASS → Inventory
- [ ] FAIL_BS → destroy (без keep)
- [ ] Settings / blacklist / audit в админке
- [ ] Apache передаёт `Authorization` (CGIPassAuth / SetEnvIf)
