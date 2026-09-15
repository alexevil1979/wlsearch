# BS-проверка: Android agent и bsbord.com

## Режимы (`bs_mode`)

| Режим | Поведение |
|-------|-----------|
| `agent` | Termux phone-agent (как раньше) |
| `bsbord` | Только API https://bsbord.com/v1/probe (мобильные каналы dpi=on) |
| `both` | Сначала bsbord (1 раз); при FAIL ждём agent |

Выбор в админке **Запуск** или CLI: `--bs-mode=bsbord`.

## Настройка bsbord

В `.env` (или Настройки в админке):

```env
BSBORD_API_BASE=https://bsbord.com/v1
BSBORD_API_TOKEN=bsk_live_...
BSBORD_OPERATORS=
BS_MODE_DEFAULT=agent
```

Токен: кабинет bsbord → API key (`Authorization: Bearer …`).

`BSBORD_OPERATORS` — опциональный фильтр (полные `op_key` через запятую). Пусто = все доступные с `dpi=on`.

## Критерий PASS (bsbord)

После `control_ok` оркестратор вызывает:

`POST https://bsbord.com/v1/probe`  
тело: `target=http://<ip>/`, `dpi=on`, `tcp_port=80`, `probes.tcp=true`.

PASS если хотя бы один leg с **dpi=on** даёт TCP/HTTP ok (маркер `WL_PROBE_OK` в `body_head` — плюс, не обязателен: маркер уже проверен control-check).

## Миграция

```bash
./bin/wlsearch-run migrate
```
