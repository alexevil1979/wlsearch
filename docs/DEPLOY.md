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
sudo -u www-data git pull origin main
sudo -u www-data php8.2 bin/wlsearch migrate
sudo systemctl reload php8.2-fpm
```

## Vhost

Файл: `deploy/apache-wlsearch.1tlt.ru.conf`  
Обязательно: `CGIPassAuth` + передача `Authorization` для phone-agent.

## Cron

```cron
* * * * * cd /ssd/www/wlsearch && ./bin/wlsearch-run worker >> storage/logs/worker.log 2>&1
```

При `open_basedir restriction` см. [INSTALL_VPS.md §13](INSTALL_VPS.md).
