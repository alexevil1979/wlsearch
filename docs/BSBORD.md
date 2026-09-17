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

wlsearch дергает **HTTP :80** (решает PASS по TCP БС) и **HTTPS :443** (информативно).

**PASS** (= зелёная точка в UI bsbord на наших же запросах):
- канал **БС** (`dpi=on`)
- **TCP** `ok` / `alive` (или `leg.ok`)
- ≥ `BSBORD_MIN_PASS` таких операторов

HTTP status / `WL_PROBE_OK` пишутся в detail, но **не режут** PASS — иначе в чекере зелёный TCP, а у нас `tcp=1 http=0 marker=0` → ложный FAIL.

Не засчитываем: каналы «без БС» (`dpi=off`).  
Избранные подсети (`/favorites`) **не травятся** правилом «один FAIL_BS → вся /24».

Снять ложные fail: `/checked-ips` → префикс `84.201.` → **forget fails**.
