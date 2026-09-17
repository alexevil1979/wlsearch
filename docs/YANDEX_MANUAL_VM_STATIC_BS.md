# Ручная VM в Yandex Cloud: статический IP и проверка BS

Инструкция **без wlsearch**: создать Ubuntu VM в [консоли Yandex Cloud](https://console.yandex.cloud/), закрепить **статический** публичный IP и вручную проверить доступность с БС (мобильные операторы).

Связанные доки: [YANDEX_SETUP.md](YANDEX_SETUP.md) (wlsearch), [BSBORD.md](BSBORD.md) (что считается PASS).

---

## 0. Что понадобится

| Что | Зачем |
|---|---|
| Каталог (Folder) с активным биллингом | куда создавать ресурсы |
| Подсеть в нужной зоне (`ru-central1-a/b/d/e`) | сеть VM |
| Группа безопасности с **TCP 80** и **443** с `0.0.0.0/0` | иначе probe/BS timeout |
| Аккаунт [bsbord.com](https://bsbord.com) (или аналог) | ручная проверка «с БС» |

Консоль: https://console.yandex.cloud/

---

## 1. Security Group (сразу, до VM)

1. Каталог → **Virtual Private Cloud** → **Группы безопасности**.  
   URL: `https://console.yandex.cloud/folders/<FOLDER_ID>/vpc/security-groups`
2. Открой SG сети (или создай свою) → **Правила** → входящий трафик:
   - **TCP 80** ← `0.0.0.0/0`
   - **TCP 443** ← `0.0.0.0/0`
   - (опционально) **ICMP** ← `0.0.0.0/0` для ping
3. Эту SG потом укажи на VM (или сделай default для подсети).

Без открытого **80** снаружи BS всегда будет «недоступен», даже если SSH/ping ок.

---

## 2. Создать VM

1. Каталог → **Compute Cloud** → **Виртуальные машины** → **Создать ВМ**.  
   URL: `https://console.yandex.cloud/folders/<FOLDER_ID>/compute/instances`
2. Параметры (пример под проверку):

| Поле | Рекомендация |
|---|---|
| Имя | любое, напр. `manual-bs-test` |
| Зона | та же, что у подсети (`ru-central1-b` и т.п.) |
| Образ | **Ubuntu 22.04 LTS** |
| Платформа / vCPU / RAM | 2 / 2 GiB достаточно |
| Диск | 15–20 GB, HDD или SSD |
| Сеть | нужная **подсеть** |
| Публичный адрес | **Автоматически** (пока динамический) |
| Группа безопасности | с правилами TCP 80/443 |
| Доступ | логин не `root` (напр. `ubuntu` / `wl`) + SSH-ключ **или** пароль |
| Серийная консоль | включить (`serial-port-enable`), если понадобится вход без SSH |

3. Создать → дождаться статуса **Running**.
4. На карточке VM скопировать **публичный IPv4** (one-to-one NAT).

Док: https://yandex.cloud/ru/docs/compute/operations/vm-create/create-linux-vm

---

## 3. Сделать IP статическим (обязательно, если IP нужен)

Пока адрес **динамический**, при удалении/пересоздании VM он пропадёт.  
Статический остаётся в каталоге и его можно снова повесить на другую VM.

### Вариант A — из карточки адреса

1. Каталог → **VPC** → **Адреса** (публичные IP).  
   URL: `https://console.yandex.cloud/folders/<FOLDER_ID>/vpc/addresses`
2. Найди IP своей VM.
3. Меню → **Сделать статическим** / «Зарезервировать».

Док: https://yandex.cloud/ru/docs/vpc/operations/set-static-ip

### Вариант B — заранее зарезервировать и привязать

1. VPC → **Адреса** → **Зарезервировать** публичный IP (зона = зона VM).
2. На VM: **Сеть** → публичный адрес → выбрать **из списка** этот адрес  
   (или `add-one-to-one-nat` с `nat-address=…`).

Док (перенос IP): https://yandex.cloud/ru/docs/compute/operations/vm-control/vm-transferring-public-ip

**Не удаляй** сам объект «Адрес» в VPC, если IP ещё нужен. Удаление VM при **static** IP адрес не уничтожает.

---

## 4. Поднять простой probe на :80 (чтобы BS было что проверить)

С БС обычно стучатся в `http://IP/` (TCP 80 + HTTP). Нужен любой ответ 200 с телом, где есть маркер (в wlsearch — `WL_PROBE_OK`).

### Через SSH

```bash
ssh USER@ПУБЛИЧНЫЙ_IP

# быстрый python-probe на 80 (без nginx)
sudo mkdir -p /var/www/html
echo 'WL_PROBE_OK manual' | sudo tee /var/www/html/index.html
sudo python3 - <<'PY'
from http.server import HTTPServer, SimpleHTTPRequestHandler
import os
os.chdir("/var/www/html")
HTTPServer(("0.0.0.0", 80), SimpleHTTPRequestHandler).serve_forever()
PY
```

Или nginx:

```bash
sudo apt-get update -qq && sudo apt-get install -y -qq nginx
echo 'WL_PROBE_OK manual' | sudo tee /var/www/html/index.html
sudo systemctl restart nginx
```

### Проверка с своего ПК (ещё не БС)

```bash
curl -sS -m 5 http://ПУБЛИЧНЫЙ_IP/
# ожидаем: WL_PROBE_OK …
```

Если curl timeout — снова SG / firewall на VM (`ufw allow 80` или `ufw disable`).

---

## 5. Ручная проверка доступности с БС

Цель: убедиться, что **с сетей МегаФон / МТС / Билайн (каналы «БС»)** открывается `http://IP/`.

### Через bsbord.com (как в wlsearch)

1. Открой https://bsbord.com (нужен аккаунт / API-токен).
2. Цель (target): `http://ПУБЛИЧНЫЙ_IP/`
3. Режим **БС** (`dpi=on`) — **не** «без БС».
4. Операторы: те, что в UI помечены как **БС** (зелёные), напр. МегаФон / МТС / Билайн ЦФО.
5. Пробы: **TCP + HTTP** на порт **80** (ICMP можно не смотреть).
6. Успех вручную, по смыслу wlsearch:
   - TCP alive / ok  
   - HTTP **2xx**  
   - в теле есть `WL_PROBE_OK`  
   - проходит нужное число операторов БС  

Подробности критериев PASS: [BSBORD.md](BSBORD.md).

### Без bsbord (грубая проверка)

- Открыть `http://IP/` с телефона на **мобильном интернете** (не Wi‑Fi) у разных операторов.
- Или любой сторонний «check from mobile ASN» / proxy с ASN оператора.

Это слабее bsbord, но показывает «сайт с телефона открывается».

---

## 6. Чеклист

- [ ] SG: TCP 80 (+ 443) с `0.0.0.0/0`
- [ ] VM Running, есть публичный IPv4
- [ ] IP сделан **статическим**
- [ ] `curl http://IP/` → `WL_PROBE_OK` (или свой ответ)
- [ ] bsbord / телефон с БС: HTTP 200 с мобильных операторов

---

## 7. Типичные проблемы

| Симптом | Что проверить |
|---|---|
| Ping есть, HTTP timeout | SG / ufw / probe не слушает :80 |
| После удаления VM пропал IP | IP был динамический — нужно было «Сделать статическим» |
| Serial console: не пускает `root` | логинься обычным пользователем (`ubuntu` / `wl`) → `sudo -i` |
| BS «без БС» зелёный, «БС» красный | для лотереи важны только каналы **БС** (`dpi=on`) |
| 403 / нет NAT при создании | роли на каталог: compute + vpc (публичные адреса) |

---

## 8. Связь с wlsearch

Автоматика делает то же самое: create VM → публичный IP → cloud-init probe → bsbord.  
Ручной сценарий из этого файла нужен, чтобы:

1. Проверить IP/подсеть **до** лотереи.  
2. Сохранить «удачный» адрес как static.  
3. Отладить SG / probe без worker.

После ручного static IP в wlsearch при KEEP / кнопке **OS** адрес тоже резервируется и не удаляется вместе с instance.
