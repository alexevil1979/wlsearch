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

wlsearch бьёт bsbord так же, как строки в UI «МОИ ПРОВЕРКИ»:

1. **Цель = голый IP**, проба **TCP :80**, `dpi=on` → это решает **PASS/FAIL** (зелёная/красная точка).
2. Дополнительно `http://IP/` для лога/маркера — на PASS не влияет.

Раньше слали только `http://IP/` и засчитывали `leg.ok` / `global_ok` → в wlsearch PASS, а в UI на том же IP красный TCP.

**PASS:** ≥ `BSBORD_MIN_PASS` операторов БС с **TCP ok**.  
Не засчитываем: «без БС», ICMP, чужие операторы из ответа.

Избранные (`/favorites`) не травятся /24.  
Ложные fail: `/checked-ips` → `84.201.` → forget fails.  
Ложный PASS (как #213): forget этот IP в Checked IPs и при необходимости destroy VM.
