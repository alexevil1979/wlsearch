# Providers

Интерфейс адаптера (план): `create`, `destroy`, `get`, `list`.

Токены **только** в `/ssd/www/wlsearch/.env` (chmod 600), не в git и не в HTML.

## Timeweb Cloud (P0)

- Base: `https://api.timeweb.cloud/api/v1`
- Auth: Bearer `TIMEWEB_API_TOKEN`
- Docs: https://timeweb.cloud/api-docs
- Create server + cloud-init с nginx probe на :80
- Delete при `FAIL_BS` (если не `keep_on_fail`)

## Selectel OpenStack (P1)

- Keystone + Nova credentials (`SELECTEL_*` в `.env`)
- Docs: https://docs.selectel.ru/cloud-servers/
- Публичный IP: poll floating IP до timeout
- Тот же cloud-init probe

## ASN lookup

Best-effort (ipinfo / bgpview). Ошибка ASN не роняет run.
