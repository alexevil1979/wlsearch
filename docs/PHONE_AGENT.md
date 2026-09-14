# Phone Agent (Termux)

Агент работает на Android с SIM. Wi‑Fi **выкл**, VPN на телефоне **выкл**.

## API (тот же домен)

База: `https://wlsearch.1tlt.ru`

| Метод | Путь | Назначение |
|-------|------|------------|
| GET | `/api/v1/agent/tasks/next` | Bearer device_token → JSON или 204 |
| POST | `/api/v1/agent/tasks/{id}/result` | Вердикт + тело/маркер |
| POST | `/api/v1/agent/heartbeat` | Online status |
| GET | `/health` | Без auth |

## PASS на стороне агента

1. Убедиться: cellular, не Wi‑Fi, не VPN
2. `GET http://<candidate-ipv4>/` (таймаут короткий)
3. Тело содержит `WL_PROBE_OK`
4. `POST .../result` с `bs_ok`, `cellular`, raw snippet

## Токен

```bash
php bin/wlsearch agent-token:create --name=phone-mts --operator=mts
```

(доступно с Phase 2)

Скрипт агента и пример Termux — Phase 2.
