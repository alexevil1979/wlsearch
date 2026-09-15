# Deploy (кратко)

**Полная установка с нуля:** → **[INSTALL_VPS.md](INSTALL_VPS.md)**

Там по шагам: DNS, пакеты, MySQL 5.7, `.env`, migrate, Apache, certbot, cron, agent, чеклист и troubleshooting.

---

## Целевой стек

| Компонент | Значение |
|-----------|----------|
| Код | `/ssd/www/wlsearch` |
| Домен | `wlsearch.1tlt.ru` |
| DocumentRoot | `/ssd/www/wlsearch/public` |
| Web | Apache2 + php8.2-fpm |
| БД | MySQL 5.7, БД `wlsearch` |
| SSL | Certbot |

Не пересекать с RushVPN (`/ssd/www/rushvpn.site`).

## Быстрый повтор после git pull

```bash
cd /ssd/www/wlsearch
sudo git pull origin main
sudo chmod +x bin/wlsearch-run
sudo -u www-data ./bin/wlsearch-run migrate
sudo systemctl reload php8.2-fpm 2>/dev/null || sudo systemctl reload php-fpm82 2>/dev/null || true
```

Не вызывайте `php8.2 bin/wlsearch` напрямую на servv — сработает host-wide `open_basedir` без wlsearch. Только `./bin/wlsearch-run …`.

`git pull` лучше от **root** (или владельца `.git`). `sudo -u www-data git pull` падает с `Permission denied` на `.git/FETCH_HEAD`, если репозиторий когда-то тянули от root.

## Vhost

Файл: `deploy/apache-wlsearch.1tlt.ru.conf`  
Обязательно: передача `Authorization` через `SetEnvIf` + `public/.htaccess` (без `CGIPassAuth` в VirtualHost).

## Cron

```cron
* * * * * cd /ssd/www/wlsearch && ./bin/wlsearch-run worker >> storage/logs/worker.log 2>&1
```

При `open_basedir restriction` см. [INSTALL_VPS.md §13](INSTALL_VPS.md).
