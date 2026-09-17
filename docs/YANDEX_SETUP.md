# Yandex Cloud → wlsearch: с нуля до первого прогона

Практическая инструкция по опыту проекта: что сделать в [консоли Yandex Cloud](https://console.yandex.cloud/), **откуда взять ID**, **куда вставить в админке** wlsearch (`/accounts`).

Консоль: https://console.yandex.cloud/  
Админка аккаунтов: https://wlsearch.1tlt.ru/accounts (или ваш хост `/accounts`)

---

## 0. Что в итоге нужно в wlsearch

| Поле в админке `/accounts` | Откуда в Yandex | Пример |
|---|---|---|
| **Имя** | любое своё | `yandex 1` |
| **Provider** | `yandex` | |
| **SA key JSON** | ключ сервисного аккаунта целиком | `{"id":"...","service_account_id":"...","private_key":"-----BEGIN..."}` |
| **Folder id** | ID **каталога** (не облака!) | `b1g…` |
| **Subnet id** | ID подсети в нужной зоне | `e9b…` |
| **Zone** | зона этой подсети | `ru-central1-a` |
| Image family | можно не трогать | `ubuntu-2204-lts` |
| Cores / RAM / Disk | лотерейный минимум | `2` / `2` / `15` |
| Preemptible | `1` = прерываемая (дешевле) | `1` |

Остальное опционально. Галочка **enabled** = участвует в лотерее.

---

## 1. Регистрация и биллинг

1. Зайти / зарегистрироваться: https://console.yandex.cloud/
2. Привязать **платёжный аккаунт** (Billing), статус `ACTIVE` или trial.
3. Создать **облако** (Cloud), внутри него **каталог** (Folder) — обычно «default».

**Важно:** в URL и в API есть два разных ID:

| Что | Как выглядит | Куда в wlsearch |
|---|---|---|
| **Cloud ID** | часто `b1g…` тоже, но это **не** folder | **не** вставлять в Folder id |
| **Folder ID** | в URL: `…/folders/<FOLDER_ID>/…` | → **Folder id** |

Как взять Folder ID:

1. Открой каталог в консоли.
2. Смотри адресную строку:  
   `https://console.yandex.cloud/folders/b1gXXXXXXXXXXXXXX/...`  
   кусок после `/folders/` — это **Folder ID**.
3. Либо: каталог → **Обзор** / настройки → скопировать ID.

Ошибка, если перепутать: `403 Permission denied to resource-manager.folder`.

---

## 2. Сеть (VPC) — подсеть и зона

Нужна подсеть с выходом в интернет (default network обычно уже есть).

1. Консоль → каталог → **Virtual Private Cloud** → **Сети** / **Подсети**.  
   Прямой путь (подставь Folder ID):  
   `https://console.yandex.cloud/folders/<FOLDER_ID>/vpc/subnets`
2. Выбери подсеть в зоне, где будешь крутить VM (часто **`ru-central1-a`**).
3. Открой подсеть → скопируй **ID** (`e9b…`) → в wlsearch **Subnet id**.
4. Зону этой подсети → в wlsearch **Zone** (`ru-central1-a`).  
   **Subnet и Zone должны совпадать.**

В `/accounts` проще: селект **Zone** + кнопка **«Загрузить подсети»** (Folder + SA JSON) → выбрать подсеть из списка — Zone и Subnet id подставятся сами.

### Security Group (чтобы probe :80 не таймаутился)

По опыту: ICMP может пинговаться, а **TCP 80** — timeout (`errno 28`), если SG режет вход.

1. VPC → **Группы безопасности** (default сети / SG VM).
2. Ingress: разрешить **TCP 80** и **443** с `0.0.0.0/0` (для лотереи probe).
3. Либо повесить эту SG на создаваемые VM / сделать default.

Без открытого 80 лотерея будет «через раз» или всегда падать в reboot.

---

## 3. Сервисный аккаунт (SA) и ключ

### 3.1. Создать SA

1. Каталог → **Service Accounts**  
   `https://console.yandex.cloud/folders/<FOLDER_ID>/iam/service-accounts`
2. **Создать** сервисный аккаунт, имя например `wlsearch`.

### 3.2. Роли на **каталог** (folder), не на cloud

Назначить SA на этот folder роли (минимум):

| Роль | Зачем |
|---|---|
| `compute.editor` | создавать/удалять/ребутить VM |
| `vpc.publicAdmin` | one-to-one NAT (публичный IPv4). Одного `vpc.user` **мало** |

Либо одной ролью: **`editor`** на folder.

Как выдать:

1. IAM → **Назначить роли** / на странице folder → права доступа.
2. Субъект = твой SA.
3. Роли как выше.

Без `vpc.publicAdmin` / `editor`: create VM может пройти, а публичный IP / NAT — 403.

### 3.3. Ключ JSON (то, что вставляем в админку)

1. Открой SA → **Создать новый ключ** → тип **Авторизованный ключ** (Authorized key) / JSON.
2. Скачается JSON вида:

```json
{
  "id": "aje…",
  "service_account_id": "aje…",
  "created_at": "…",
  "key_algorithm": "RSA_2048",
  "public_key": "…",
  "private_key": "-----BEGIN PRIVATE KEY-----\n…\n-----END PRIVATE KEY-----\n"
}
```

3. **Весь файл целиком** (одной простынёй) → поле **SA key JSON** в `/accounts`.  
   Не вырезать только `private_key`. Не класть в git / чат.

В проекте из JSON берутся: `service_account_id`, `id` (key id), `private_key` → JWT → IAM token.

---

## 4. Вставка в админку wlsearch

1. Открыть **Аккаунты** → добавить / редактировать.
2. Provider: **yandex**.
3. Заполнить:

```
Имя:              yandex 1
SA key JSON:      <весь JSON ключа>
Folder id:        b1g…     ← из URL /folders/…
Subnet id:        e9b…     ← ID подсети
Zone:             ru-central1-a
Image family:     ubuntu-2204-lts
Platform:         standard-v3
Cores:            2
RAM GB:           2
Disk GB:          15
Preemptible:      1
```

4. **Сохранить**, галочка **enabled**.
5. **Запуск** → provider `yandex` → отметить этот аккаунт → count ≥ 1.

Worker на VPS должен крутиться (`php bin/console wlsearch-run worker` / cron).

Логи: `/logs` → канал **yandex** / **probe**, файлы `storage/logs/yandex.log`, `probe.log`.

---

## 5. Проверка, что всё ок

Успешный create в `yandex.log`: `create:done` + `instance_id`, в Runs появляется IP.

| Симптом | Что проверить |
|---|---|
| `403` / Permission denied folder | Folder ID vs Cloud ID; роли SA на **этот** folder |
| Нет публичного IP / NAT 403 | роль `vpc.publicAdmin` или `editor` |
| Ping ок, HTTP timeout :80 | Security Group: TCP 80/443 |
| `429` `vpc.externalAddressesCreation.rate` | квота **созданий** публичных IP (~64/сутки) — пауза; в настройках `YANDEX_QUOTA_COOLDOWN_SEC` |
| Частые reboot / bootstrap | SG + cloud-init; см. probe.log |

---

## 6. После «избранная подсеть попалась» (нужный IP)

IP при create **динамический**. Чтобы оставить с оплатой резерва:

1. [VPC → Публичные IP](https://console.yandex.cloud/) → `…/folders/<FOLDER_ID>/vpc/addresses`
2. Найти IP → **Сделать статическим** (обязательно!).  
   Док: https://yandex.cloud/ru/docs/vpc/operations/set-static-ip
3. Отвязать от VM (NAT) или удалить VM **после** static.  
   Док: https://yandex.cloud/ru/docs/compute/operations/vm-control/vm-detach-public-ip
4. В wlsearch run уже **KEEP** — можно продолжать лотерею новыми VM; защищённый IP проект **не удаляет** (только instance).

Не нажимай в консоли «Удалить» на самом адресе, если он нужен.

---

## 7. Избранные подсети в wlsearch

Админка → **Избранные** (`/favorites`): CIDR вроде `84.201.128.0/16`.  
При попадании IP → STOP очереди + Telegram «избранная подсеть попалась» + KEEP.

---

## 8. Квоты (чтобы не удивляться)

| Квота | Смысл |
|---|---|
| `vpc.externalAddressesCreation.rate` (limit 64) | сколько раз **создать** публичный IP за окно (~сутки) |
| `vpc.externalAddresses.count` | сколько адресов **держать** сразу (включая static) |

При 429 wlsearch ставит create на паузу и повторяет (не роняет в вечный ERROR).  
Увеличить квоту: консоль → квоты / поддержка Yandex.

---

## 9. Чеклист «новый аккаунт за 10 минут»

- [ ] Биллинг ACTIVE  
- [ ] Folder ID из URL `/folders/…`  
- [ ] Подсеть + Zone совпадают  
- [ ] SG: TCP 80/443  
- [ ] SA + роли `compute.editor` + `vpc.publicAdmin` (или `editor`) на folder  
- [ ] Authorized key JSON скачан  
- [ ] В `/accounts` вставлено: JSON + Folder + Subnet + Zone, enabled  
- [ ] Запуск yandex, worker жив  
- [ ] В Runs появился IP; probe не timeout  

---

См. также: [PROVIDERS.md](PROVIDERS.md), [RUNBOOK.md](RUNBOOK.md).
