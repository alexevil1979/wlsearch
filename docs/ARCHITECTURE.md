# Architecture

## Компоненты

```
VPS (оркестратор + админка)  /ssd/www/wlsearch
  DocumentRoot → public/
  https://wlsearch.1tlt.ru
  ├── Web Admin (PHP, server-rendered)
  ├── Agent HTTP API  /api/v1/agent/*
  ├── Worker/Cron     php bin/wlsearch worker
  ├── MySQL 5.7
  ├── Provider adapters (Timeweb P0, Selectel P1)
  └── Telegram notify (optional)

Phone Agent (Android Termux, 1..N)
  ├── poll GET /api/v1/agent/tasks/next
  ├── only cellular, no Wi‑Fi, no VPN
  ├── GET http://<candidate-ip>/
  └── POST /api/v1/agent/tasks/{id}/result
```

## State machine (runs)

`ORDERING → PROVISIONING → BOOTSTRAPPING → CONTROL_CHECK → BS_CHECK → PASS|FAIL_* → DESTROY|KEEP`

Probe живёт на **кандидатном** VPS (cloud-init → nginx :80 → тело `WL_PROBE_OK <provider> <server_id> <ip> <ts>`), не на wlsearch.

## PASS

`control_ok ∧ bs_ok ∧ cellular ∧ маркер WL_PROBE_OK` в теле ответа phone-agent.

## Данные

Таблицы: `admin_users`, `devices`, `runs`, `tasks`, `inventory`, `prefix_blacklist`, `audit_log`, `settings`.

MySQL 5.7: без CTE / window-зависимостей; JSON как TEXT при необходимости.

## Фазы

| Phase | Содержание |
|-------|------------|
| 0 | Skeleton, login, migrate, /health, deploy docs |
| 1 | Timeweb + control + UI runs + worker + Telegram |
| 2 | Phone agent + full loop + inventory |
| 3 | Selectel |
| 4 | Blacklist, multi-device, limits UI, audit |

## Worker tick

Cron: `* * * * * php8.2 bin/wlsearch worker`

За один тик обрабатывает до 50 run в активных состояниях:

- `ORDERING` → Timeweb create + cloud-init
- `PROVISIONING` → poll IP/status + ASN
- `BOOTSTRAPPING` → ждёт ответ probe
- `CONTROL_CHECK` → маркер `WL_PROBE_OK` → `BS_CHECK`
- `FAIL_*` без keep → `DESTROYING` → API delete → `DESTROYED`
