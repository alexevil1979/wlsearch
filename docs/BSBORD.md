# BS-проверка через bsbord.com

В UI bsbord каналы делятся на:

| Метка | dpi | Использование в wlsearch |
|-------|-----|---------------------------|
| **БС** (зелёный) | `on` | **да** — только они |
| **без БС** (оранжевый) | `off` | **нет** |

## Настройка

1. Токен уже в `.env` / Настройки → `BSBORD_API_TOKEN`
2. Откройте **Настройки** → блок операторов **БС**
3. Кнопка «Выбрать МегаФон + МТС + Билайн ЦФО (БС)» или отметьте вручную
4. `BS_MODE_DEFAULT=bsbord` (или both)
5. Запуск прогона с `bs_mode=bsbord`

## API

```http
GET  /v1/operators?dpi=on
POST /v1/probe
Authorization: Bearer bsk_live_…
{ "target":"http://IP/", "dpi":"on", "operators":["…|on"], "tcp_port":80, "probes":{"tcp":true,"http":true,"icmp":false} }
```

wlsearch дергает **HTTP :80 и HTTPS :443** отдельно (только `dpi=on`).

**PASS** (строго, как зелёные точки в UI):
- TCP `ok` / `alive`
- HTTP status 2xx
- в теле есть `WL_PROBE_OK`
- ≥ `BSBORD_MIN_PASS` операторов **БС**

Не засчитываем: ICMP, каналы «без БС», голый `leg.ok` без TCP/HTTP.
