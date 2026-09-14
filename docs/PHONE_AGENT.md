# Phone Agent (Termux)

Агент работает на Android с SIM. Wi‑Fi **выкл**, VPN на телефоне **выкл**.

## API

База: `https://wlsearch.1tlt.ru`

| Метод | Путь | Назначение |
|-------|------|------------|
| GET | `/api/v1/agent/tasks/next` | Bearer → JSON задача или **204** |
| POST | `/api/v1/agent/tasks/{id}/result` | Вердикт |
| POST | `/api/v1/agent/heartbeat` | Online |
| GET | `/health` | Без auth |

### Результат (JSON)

```json
{
  "cellular": true,
  "wifi": false,
  "vpn": false,
  "http_status": 200,
  "http_ok": true,
  "marker_ok": true,
  "body": "WL_PROBE_OK timeweb run_1 ...",
  "error": null
}
```

**PASS** на сервере только если: `control_ok` ∧ `cellular` ∧ `!wifi` ∧ `!vpn` ∧ `marker_ok` ∧ `http_ok`.

## Создать token

Админка → Agents, или:

```bash
php8.2 bin/wlsearch agent-token:create --name=phone-mts --operator=mts
```

## Termux

Скрипт в репо: `agents/termux/wlsearch_agent.sh`

```bash
pkg install curl python
export WLSEARCH_URL=https://wlsearch.1tlt.ru
export WLSEARCH_TOKEN='wls_...'
bash wlsearch_agent.sh
```

Держите экран/Termux wake-lock по необходимости. Wi‑Fi выключить, VPN выключить.
