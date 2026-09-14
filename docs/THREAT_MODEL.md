# Threat model

## Активы

- Cloud API tokens (Timeweb / Selectel)
- Admin credentials / session
- Device (phone-agent) tokens
- Inventory PASS IPs (операционная ценность)
- Audit log

## Угрозы и меры

| Угроза | Мера |
|--------|------|
| Утечка cloud tokens | Только `.env` chmod 600; вне webroot; не в HTML/API agent |
| Брутфорс админки | HTTPS, CSRF, rate-limit login, сильный пароль, опц. IP allowlist Apache |
| Подделка agent result | Bearer device_token (hash в БД), revoke |
| SSRF / злоупотребление | Agent ходит на candidate IP; оркестратор не проксирует произвольные URL с agent API |
| Неконтролируемый spend | MAX_PARALLEL_VMS, MAX_CREATES_PER_DAY, MAX_DAILY_SPEND_RUB; отдельные cloud-проекты |
| Session hijack | Secure + HttpOnly + SameSite cookies; HTTPS only |
| Path traversal / code leak | DocumentRoot = `public/`; остальное вне webroot |

## Не цели атаки продукта

Обход ТСПУ/БС — операционная задача владельца инфраструктуры для своих entry-IP. Репозиторий не содержит VPN-стека и не атакует чужие системы.
