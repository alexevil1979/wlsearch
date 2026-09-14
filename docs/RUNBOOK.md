# Runbook

## Обычные операции

### Обновить код

```bash
cd /ssd/www/wlsearch && git pull && php8.2 bin/wlsearch migrate
sudo systemctl reload php8.2-fpm
```

### Проверить здоровье

```bash
curl -sS https://wlsearch.1tlt.ru/health
php8.2 bin/wlsearch health
tail -n 100 /ssd/www/wlsearch/storage/logs/worker.log
```

### Запуск прогона (Phase 1+)

Админка → «Запуск» или:

```bash
php8.2 bin/wlsearch run --provider=timeweb --region=spb-3 --count=1
php8.2 bin/wlsearch worker
```

Убедитесь, что в `.env` заданы `TIMEWEB_API_TOKEN`, `TIMEWEB_PRESET_ID`, `TIMEWEB_OS_ID`.

### Destroy failed

```bash
php8.2 bin/wlsearch destroy-failed
```

### Inventory

Админка → Inventory или `php8.2 bin/wlsearch inventory`.

## Лимиты

Соблюдать `MAX_PARALLEL_VMS`, `MAX_CREATES_PER_DAY`, `MAX_DAILY_SPEND_RUB` (`.env` / settings).

## Инциденты

| Симптом | Действие |
|---------|----------|
| `/health` db=down | MySQL, `.env` DB_*, grants |
| Login lockout | Подождать `LOGIN_LOCKOUT_SECONDS` или очистить session |
| Агенты offline | heartbeat, token revoked?, сеть телефона |
| Утечка spend | снизить лимиты, stop creates, destroy orphan VMs в облаке |
