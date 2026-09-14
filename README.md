# wlsearch

Поиск IPv4 в РФ-облаках (Timeweb / Selectel), которые **открываются с мобильной сети в режиме белых списков (БС)** (МТС / Билайн / МегаФон). PASS сохраняется в inventory; оператор управляет процессом через веб-админку.

**Админка:** https://wlsearch.1tlt.ru/  
**Прод-путь:** `/ssd/www/wlsearch`  
**Стек:** Apache2 + PHP 8.2 + MySQL 5.7 + Certbot

## Критерий PASS

`PASS` только если одновременно:

1. **control_ok** — probe отвечает с оркестратора (HTTP :80, маркер в теле);
2. **bs_ok** — phone-agent с **cellular** (Wi‑Fi выкл, VPN на телефоне выкл) получил тот же маркер `WL_PROBE_OK`;
3. **cellular** подтверждён агентом.

curl с Wi‑Fi / EU **не доказывает** БС-доступность.

## Что это НЕ делает

- Не ставит VLESS/Xray/Remnawave/Hiddify
- Не настраивает WireGuard на EU exit
- Не трогает Laravel RushVPN / HiddifySales
- После PASS — inventory + уведомления; превращение IP в VPN entry — вне этого репо

## Phase 0 (текущий каркас)

- Login / session auth (CSRF, rate-limit)
- Dashboard-заглушка со счётчиками
- `/health` JSON
- Миграции таблиц (`php bin/wlsearch migrate`)
- CLI stubs + docs под Apache/PHP8.2/MySQL5.7

## Быстрый старт (VPS)

См. полный runbook: [docs/DEPLOY.md](docs/DEPLOY.md)

```bash
sudo mkdir -p /ssd/www/wlsearch
sudo git clone https://github.com/alexevil1979/wlsearch.git /ssd/www/wlsearch
cd /ssd/www/wlsearch
cp .env.example .env   # заполнить вручную (chmod 600)
# composer install  # опционально; есть встроенный autoload
php8.2 bin/wlsearch migrate
```

Apache DocumentRoot → `/ssd/www/wlsearch/public`, vhost `wlsearch.1tlt.ru`.

## CLI

```bash
php bin/wlsearch migrate
php bin/wlsearch health
php bin/wlsearch worker          # cron каждую минуту (Phase 1+)
php bin/wlsearch inventory
```

## Документация

| Файл | Содержание |
|------|------------|
| [docs/DEPLOY.md](docs/DEPLOY.md) | Apache, MySQL 5.7, PHP 8.2, certbot |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Компоненты и state machine |
| [docs/PROVIDERS.md](docs/PROVIDERS.md) | Timeweb / Selectel |
| [docs/PHONE_AGENT.md](docs/PHONE_AGENT.md) | Termux agent |
| [docs/RUNBOOK.md](docs/RUNBOOK.md) | Операции |
| [docs/THREAT_MODEL.md](docs/THREAT_MODEL.md) | Угрозы и меры |

## Лицензия

Private / internal use.
