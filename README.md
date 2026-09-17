# wlsearch

Поиск IPv4 в РФ-облаках (Timeweb / Selectel), открывающихся с мобильной сети в режиме **белых списков (БС)**. PASS → inventory; управление через веб-админку.

**Админка:** https://wlsearch.1tlt.ru/  
**Прод:** `/ssd/www/wlsearch` — Apache2 + PHP 8.2 + MySQL 5.7 + Certbot

## Критерий PASS

`control_ok ∧ bs_ok ∧ cellular ∧ маркер WL_PROBE_OK`  
Проверка BS — только с Android + SIM (Wi‑Fi/VPN выкл). curl с Wi‑Fi/EU не считается.

## Возможности

- Запуск прогонов (yandex / timeweb / selectel), лимиты, destroy/keep
- Phone-agent API + Termux-скрипт
- Inventory PASS, devices/tokens, blacklist ASN/prefix
- Settings UI, audit log, Telegram notify

**Yandex с нуля (регистрация → ID → `/accounts`):** [docs/YANDEX_SETUP.md](docs/YANDEX_SETUP.md)

## Не цель

VLESS/Xray/WG/RushVPN/Hiddify — вне репо. После PASS IP используется оператором отдельно.

## Деплой

**Установка на VPS с нуля:** [docs/INSTALL_VPS.md](docs/INSTALL_VPS.md)  
Кратко: [docs/DEPLOY.md](docs/DEPLOY.md)

```bash
cd /ssd/www/wlsearch && git pull
php8.2 bin/wlsearch migrate
# cron: * * * * * php8.2 bin/wlsearch worker
```

## CLI

```bash
php bin/wlsearch run --provider=yandex --region=ru-central1-a --count=1
php bin/wlsearch run --provider=timeweb --region=spb-3 --count=1
php bin/wlsearch run --provider=selectel --region=ru-9a --count=1
php bin/wlsearch worker
php bin/wlsearch agent-token:create --name=phone-mts --operator=mts
php bin/wlsearch inventory
php bin/wlsearch destroy-failed
```

## Документация

[INSTALL_VPS](docs/INSTALL_VPS.md) · [DEPLOY](docs/DEPLOY.md) · [ARCHITECTURE](docs/ARCHITECTURE.md) · [PROVIDERS](docs/PROVIDERS.md) · [PHONE_AGENT](docs/PHONE_AGENT.md) · [BSBORD](docs/BSBORD.md) · [RUNBOOK](docs/RUNBOOK.md) · [THREAT_MODEL](docs/THREAT_MODEL.md)
