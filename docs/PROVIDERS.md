# Providers

Интерфейс: `create`, `destroy`, `get`, `list`.  
Токены **только** в `/ssd/www/wlsearch/.env` (chmod 600) или в `/accounts`.

## Yandex Cloud (основной для перебора IP)

**Пошагово с нуля (консоль → админка):** [YANDEX_SETUP.md](YANDEX_SETUP.md)

- Auth: authorized key сервисного аккаунта → JWT PS256 → IAM token
- Credentials: `YANDEX_SA_KEY_JSON` (целиком JSON ключа) **или** `YANDEX_SA_ID` + `YANDEX_SA_KEY_ID` + `YANDEX_SA_PRIVATE_KEY`
- Обязательно: `YANDEX_FOLDER_ID`, `YANDEX_SUBNET_ID`
- Zone: `YANDEX_ZONE_ID` (default `ru-central1-a`) — subnet должен быть в этой зоне
- Image: `YANDEX_IMAGE_ID` **или** `YANDEX_IMAGE_FAMILY` (default `ubuntu-2204-lts` из `standard-images`)
- VM: `YANDEX_CORES`, `YANDEX_MEMORY_GB`, `YANDEX_DISK_GB`, `YANDEX_PLATFORM_ID`, `YANDEX_PREEMPTIBLE=1`
- Публичный IPv4: `oneToOneNat` IPV4 при create (новый адрес каждый раз)
- cloud-init: `metadata.user-data`
- Логи: `storage/logs/yandex.log`
- Docs: https://yandex.cloud/docs/compute/api-ref/Instance/create

Права SA **на folder** (не на cloud):
- `compute.editor`
- `vpc.publicAdmin` (нужно для one-to-one NAT / публичный IPv4; одного `vpc.user` мало)
- либо сразу примитив `editor` на folder

Частая ошибка 403 `Permission denied to resource-manager.folder`: в `YANDEX_FOLDER_ID` попал **Cloud ID** вместо **Folder ID**, либо роли выданы другому SA / не на тот каталог.

## Timeweb Cloud (на паузе)

- Base: `TIMEWEB_API_BASE` (default `https://api.timeweb.cloud/api/v1`)
- Токен: `TIMEWEB_API_TOKEN` только в `.env`
- Параметры VPS в **Настройки**: в первую очередь `TIMEWEB_PRESET_ID` + `TIMEWEB_OS_ID` + zone (дефолт preset `4795`, os `99`, `spb-3`).
- Если preset пуст — запасной `configuration` (configurator/cpu/ram/disk).
- **Биллинг всегда почасовой.** В API нет режима «на месяц» / «на час» — Timeweb списывает почасово; цены в ЛК показывают как ₽/мес.
- **Но:** при `POST /servers` на балансе должно быть **≈ на 30 дней** этого тарифа, иначе **HTTP 402**. После создания платите только за фактические часы до destroy.
- **`TIMEWEB_ENSURE_IPV4=1`** — публичный IPv4 (тоже почасовой). Сначала берётся свободный floating IP («Не подключен»), иначе новый. В `network.floating_ip` API ждёт **адрес** (`1.2.3.4`), не UUID.
- `TIMEWEB_FLOATING_IP_ID` — UUID **или** сам IPv4 уже существующего адреса.
- **Аккаунты** (`/accounts`): несколько провайдеров, галочки «в лотерее», round-robin на run.
- **`TIMEWEB_DELETE_FLOATING_IP_ON_DESTROY=1`** (в аккаунте) — при destroy удаляет floating IP. FAIL_BS адрес не переиспользуем. Лимит Timeweb ~10 create IP/сутки на аккаунт — заводите несколько аккаунтов.
- Docs: https://timeweb.cloud/api-docs

## Selectel OpenStack

- Keystone: `SELECTEL_AUTH_URL` (например `https://cloud.api.selcloud.ru/identity/v3`)
- `SELECTEL_USERNAME` / `SELECTEL_PASSWORD`
- `SELECTEL_PROJECT_ID` **или** `SELECTEL_PROJECT_NAME`
- `SELECTEL_USER_DOMAIN_NAME` / `SELECTEL_PROJECT_DOMAIN_NAME` (часто account id)
- `SELECTEL_REGION` (AZ, например `ru-9a`)
- `SELECTEL_FLAVOR_ID`, `SELECTEL_IMAGE_ID`, `SELECTEL_NETWORK_ID`
- Опционально `SELECTEL_EXTERNAL_NET_ID` — выделить floating IP и привязать к порту

Создание: Nova `POST /servers` + `user_data` (cloud-init, base64).  
Публичный IP: из `addresses` / floating IP; worker поллит до timeout.

Docs: https://docs.selectel.ru/cloud-servers/

## Probe

cloud-init: сразу python :80/:443 (без apt), nginx фоном; ufw off.
На Timeweb облачный Firewall (whitelist без 80/443) → снаружи timeout при живом localhost — worker отвязывает firewall groups.

## ASN

Best-effort (ipinfo / bgpview). Ошибка ASN не роняет run. Blacklist ASN/prefix — в админке.
