# Providers

Интерфейс: `create`, `destroy`, `get`, `list`.  
Токены **только** в `/ssd/www/wlsearch/.env` (chmod 600).

## Timeweb Cloud

- Base: `TIMEWEB_API_BASE` (default `https://api.timeweb.cloud/api/v1`)
- `TIMEWEB_API_TOKEN`, `TIMEWEB_PRESET_ID`, `TIMEWEB_OS_ID`, `TIMEWEB_AVAILABILITY_ZONE`
- **`TIMEWEB_ENSURE_IPV4=1`** (по умолчанию) — при создании VPS заказывается плавающий **IPv4** и привязывается к серверу. Без этого Timeweb может выдать только IPv6, и прогон зависнет в `PROVISIONING` (wlsearch проверяет `http://IPv4/`).
- Опционально `TIMEWEB_FLOATING_IP_ID` — привязать существующий свободный floating IP вместо создания нового.
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

cloud-init → nginx :80 → `WL_PROBE_OK <provider> run_<id> <ip> <ts>`

## ASN

Best-effort (ipinfo / bgpview). Ошибка ASN не роняет run. Blacklist ASN/prefix — в админке.
