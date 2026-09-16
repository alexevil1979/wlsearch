# Providers

Интерфейс: `create`, `destroy`, `get`, `list`.  
Токены **только** в `/ssd/www/wlsearch/.env` (chmod 600).

## Timeweb Cloud

- Base: `TIMEWEB_API_BASE` (default `https://api.timeweb.cloud/api/v1`)
- Токен: `TIMEWEB_API_TOKEN` только в `.env`
- Параметры VPS в **Настройки**: в первую очередь `TIMEWEB_PRESET_ID` + `TIMEWEB_OS_ID` + zone (дефолт preset `4795`, os `99`, `spb-3`).
- Если preset пуст — запасной `configuration` (configurator/cpu/ram/disk).
- **Биллинг всегда почасовой.** В API нет режима «на месяц» / «на час» — Timeweb списывает почасово; цены в ЛК показывают как ₽/мес.
- **Но:** при `POST /servers` на балансе должно быть **≈ на 30 дней** этого тарифа, иначе **HTTP 402**. После создания платите только за фактические часы до destroy.
- **`TIMEWEB_ENSURE_IPV4=1`** — публичный IPv4 (тоже почасовой). Сначала берётся свободный floating IP («Не подключен»), иначе новый. В `network.floating_ip` API ждёт **адрес** (`1.2.3.4`), не UUID.
- `TIMEWEB_FLOATING_IP_ID` — UUID **или** сам IPv4 уже существующего адреса.
- **Аккаунты** (`/accounts`): несколько Timeweb/Selectel, галочки «в лотерее», round-robin на run. Токены и preset хранятся в аккаунте (migrate подтягивает из `.env`).
- **`TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY=1`** (в аккаунте) — при destroy удаляет floating IP. FAIL_BS адрес не переиспользуем. Лимит Timeweb ~10 create IP/сутки на аккаунт — заводите несколько аккаунтов.
- Docs: https://timeweb.cloud/api-docs

## Selectel OpenStack

- Keystone: `SELECTEL_AUTH_URL` (например `https://cloud.api.selcloud.ru/identity/v3`)
- `SELECTEL_USERNAME` / `SELECTEL_PASSWORD`
- `SELECTEL_PROJECT_ID` **или** `SELECTEL_PROJECT_NAME`
- `SELECTEL_USER_DOMAIN_NAME` / `SELECTEL_PROJECT_DOMAIN_NAME` (часто account id)
- `SELECTEL_REGION` (AZ, например `ru-9a`)
- `SELECTEL_FLAVOR_ID`, `SELECTEL_IMAGE_ID`, `SELECTEL_NETWORK_ID`
- Опционально `SELECTEL_EXTERNAL_NET_ID` — выделить floating IP и привязать к порту

Создание: Nova `POST /servers` + `user_data` (cloud-init, base64).  
Публичный IP: из `addresses` / floating IP; worker поллит до timeout.

Docs: https://docs.selectel.ru/cloud-servers/

## Probe

Timeweb: в `cloud_init` уходит **`#!/bin/sh`** (формат из их доки; сложный `#cloud-config` + YAML
часто не выполняется, хотя ручной paste тех же команд на VPS ок).
Скрипт поднимает python probe **:80 + :443** → тело `WL_PROBE_OK <provider> run_<id> <ip> <ts>`.
Если за ~90с probe не отвечает — worker один раз PATCH cloud_init + reboot.
Selectel: тот же скрипт в Nova `user_data` (base64).

## ASN

Best-effort (ipinfo / bgpview). Ошибка ASN не роняет run. Blacklist ASN/prefix — в админке.
