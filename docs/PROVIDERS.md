# Providers

Интерфейс адаптера (план): `create`, `destroy`, `get`, `list`.

Токены **только** в `/ssd/www/wlsearch/.env` (chmod 600), не в git и не в HTML.

## Timeweb Cloud (P0) — реализовано в Phase 1

- Base: `https://api.timeweb.cloud/api/v1` (`TIMEWEB_API_BASE`)
- Auth: Bearer `TIMEWEB_API_TOKEN`
- Create: `POST /servers` с `preset_id`, `os_id`, `availability_zone`, `cloud_init`
- Get / List / Delete: `/servers`, `/servers/{id}`
- Docs: https://timeweb.cloud/api-docs

### Обязательные env

```
TIMEWEB_API_TOKEN=...
TIMEWEB_PRESET_ID=4795
TIMEWEB_OS_ID=99
TIMEWEB_AVAILABILITY_ZONE=spb-3
TIMEWEB_BANDWIDTH=200
```

Preset/OS ID зависят от аккаунта — возьмите из панели или `GET /presets` / `GET /os`.

### Probe (cloud-init)

На кандидатном VPS ставится nginx :80, тело:

`WL_PROBE_OK timeweb run_<id> <ip> <ts>`

Control-check с оркестратора: `GET http://<ip>/` и поиск маркера `WL_PROBE_OK`.

## Selectel OpenStack (P1)

- Keystone + Nova credentials (`SELECTEL_*` в `.env`)
- Docs: https://docs.selectel.ru/cloud-servers/
- Публичный IP: poll floating IP до timeout
- Тот же cloud-init probe

## ASN lookup

Best-effort (ipinfo / bgpview). Ошибка ASN не роняет run.
