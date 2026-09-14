# Architecture

## Компоненты

```
VPS /ssd/www/wlsearch → https://wlsearch.1tlt.ru
  ├── Web Admin (PHP)
  ├── Agent API /api/v1/agent/*
  ├── Worker cron  php bin/wlsearch worker
  ├── MySQL 5.7
  ├── Timeweb + Selectel providers
  └── Telegram (optional)

Phone Agent (Termux)
  poll → GET candidate IP → POST result
```

## State machine

`ORDERING → PROVISIONING → BOOTSTRAPPING → CONTROL_CHECK → BS_CHECK → PASS|FAIL_* → DESTROY|KEEP`

- Control: оркестратор `GET http://ip/` ищет `WL_PROBE_OK`
- BS: phone-agent, только cellular
- PASS → inventory; FAIL_BS/FAIL_CONTROL → destroy (если не keep_on_fail)

## Фазы (все реализованы)

| Phase | Содержание |
|-------|------------|
| 0 | Skeleton, login, migrate, /health, deploy |
| 1 | Timeweb + control + Runs UI + worker |
| 2 | Agent API + Termux + inventory |
| 3 | Selectel OpenStack |
| 4 | Blacklist, settings UI, audit, multi-device |
