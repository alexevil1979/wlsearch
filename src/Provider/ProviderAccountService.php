<?php

declare(strict_types=1);

namespace Wlsearch\Provider;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

final class ProviderAccountService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return list<array<string, mixed>> */
    public function listAll(?string $provider = null): array
    {
        if ($provider !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM provider_accounts WHERE provider = ? ORDER BY sort_order ASC, id ASC'
            );
            $stmt->execute([strtolower($provider)]);
        } else {
            $stmt = $this->pdo->query(
                'SELECT * FROM provider_accounts ORDER BY provider ASC, sort_order ASC, id ASC'
            );
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return list<array<string, mixed>> */
    public function listEnabled(string $provider): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM provider_accounts WHERE provider = ? AND enabled = 1 ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([strtolower($provider)]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM provider_accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function hasEnabled(string $provider): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM provider_accounts WHERE provider = ? AND enabled = 1'
        );
        $stmt->execute([strtolower($provider)]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function isConfigured(string $provider): bool
    {
        if ($this->hasEnabled($provider)) {
            return true;
        }
        // legacy .env
        return ProviderFactory::isConfiguredLegacy($provider);
    }

    /**
     * Pick least-recently-used enabled account (or specific ids).
     *
     * @param list<int>|null $onlyIds
     * @return array<string, mixed>|null
     */
    public function pick(string $provider, ?array $onlyIds = null): ?array
    {
        $provider = strtolower($provider);
        $rows = $this->listEnabled($provider);
        if ($onlyIds !== null) {
            $allow = array_fill_keys(array_map('intval', $onlyIds), true);
            $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($allow[(int) $r['id']])));
        }
        if ($rows === []) {
            return null;
        }
        usort($rows, static function (array $a, array $b): int {
            $la = $a['last_used_at'] ?? null;
            $lb = $b['last_used_at'] ?? null;
            if ($la === $lb) {
                return ((int) $a['id']) <=> ((int) $b['id']);
            }
            if ($la === null) {
                return -1;
            }
            if ($lb === null) {
                return 1;
            }
            return strcmp((string) $la, (string) $lb);
        });
        return $rows[0];
    }

    public function markUsed(int $id, ?string $error = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE provider_accounts SET last_used_at = NOW(), last_error = ?, updated_at = NOW() WHERE id = ?'
        );
        $stmt->execute([$error !== null ? mb_substr($error, 0, 2000) : null, $id]);
    }

    /**
     * @param array<string, string> $credentials
     * @param array<string, string> $config
     */
    public function create(
        string $provider,
        string $name,
        array $credentials,
        array $config,
        bool $enabled,
        string $actor,
    ): int {
        $provider = strtolower($provider);
        if (!in_array($provider, ['timeweb', 'selectel', 'yandex'], true)) {
            throw new \InvalidArgumentException('provider: timeweb|selectel|yandex');
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Укажите имя аккаунта');
        }
        $this->assertCredentials($provider, $credentials, true);

        $stmt = $this->pdo->prepare(
            'INSERT INTO provider_accounts
             (provider, name, enabled, credentials_json, config_json, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
        );
        $sort = (int) $this->pdo->query(
            'SELECT COALESCE(MAX(sort_order),0)+1 FROM provider_accounts WHERE provider = ' . $this->pdo->quote($provider)
        )->fetchColumn();
        $stmt->execute([
            $provider,
            mb_substr($name, 0, 128),
            $enabled ? 1 : 0,
            json_encode($credentials, JSON_UNESCAPED_UNICODE),
            json_encode($config, JSON_UNESCAPED_UNICODE),
            $sort,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        Audit::log($actor, 'account.create', 'provider_account', (string) $id, [
            'provider' => $provider,
            'name' => $name,
            'enabled' => $enabled,
        ]);
        return $id;
    }

    /**
     * @param array<string, string> $credentials empty secret fields keep previous
     * @param array<string, string> $config
     */
    public function update(
        int $id,
        string $name,
        array $credentials,
        array $config,
        bool $enabled,
        string $actor,
    ): void {
        $row = $this->get($id);
        if ($row === null) {
            throw new \RuntimeException('Аккаунт не найден');
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Укажите имя аккаунта');
        }
        $prevCreds = json_decode((string) $row['credentials_json'], true);
        if (!is_array($prevCreds)) {
            $prevCreds = [];
        }
        foreach ($credentials as $k => $v) {
            if ($v === '' || $v === null) {
                continue;
            }
            $prevCreds[$k] = $v;
        }
        $this->assertCredentials((string) $row['provider'], $prevCreds, false);

        $stmt = $this->pdo->prepare(
            'UPDATE provider_accounts
             SET name = ?, enabled = ?, credentials_json = ?, config_json = ?, updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->execute([
            mb_substr($name, 0, 128),
            $enabled ? 1 : 0,
            json_encode($prevCreds, JSON_UNESCAPED_UNICODE),
            json_encode($config, JSON_UNESCAPED_UNICODE),
            $id,
        ]);
        Audit::log($actor, 'account.update', 'provider_account', (string) $id, [
            'name' => $name,
            'enabled' => $enabled,
        ]);
    }

    /** @param list<int> $enabledIds */
    public function setEnabledMany(string $provider, array $enabledIds, string $actor): void
    {
        $provider = strtolower($provider);
        $enabledIds = array_values(array_unique(array_map('intval', $enabledIds)));
        $all = $this->listAll($provider);
        $stmt = $this->pdo->prepare('UPDATE provider_accounts SET enabled = ?, updated_at = NOW() WHERE id = ?');
        foreach ($all as $row) {
            $id = (int) $row['id'];
            $on = in_array($id, $enabledIds, true) ? 1 : 0;
            $stmt->execute([$on, $id]);
        }
        Audit::log($actor, 'account.enabled_bulk', 'provider', $provider, ['enabled_ids' => $enabledIds]);
    }

    public function delete(int $id, string $actor): void
    {
        $row = $this->get($id);
        if ($row === null) {
            return;
        }
        $st = $this->pdo->prepare(
            "SELECT COUNT(*) FROM runs WHERE provider_account_id = ? AND state IN ('ORDERING','PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')"
        );
        $st->execute([$id]);
        $active = (int) $st->fetchColumn();
        if ($active > 0) {
            throw new \RuntimeException("Нельзя удалить: есть {$active} активных run на этом аккаунте");
        }
        $this->pdo->prepare('DELETE FROM provider_accounts WHERE id = ?')->execute([$id]);
        Audit::log($actor, 'account.delete', 'provider_account', (string) $id, [
            'provider' => $row['provider'],
            'name' => $row['name'],
        ]);
    }

    public function bag(?int $accountId, string $provider): AccountBag
    {
        if ($accountId !== null && $accountId > 0) {
            $row = $this->get($accountId);
            if ($row === null) {
                throw new \RuntimeException("Аккаунт #{$accountId} не найден");
            }
            if ((string) $row['provider'] !== strtolower($provider)) {
                throw new \RuntimeException("Аккаунт #{$accountId} не для {$provider}");
            }
            return AccountBag::fromAccountRow($row);
        }
        $picked = $this->pick($provider);
        if ($picked !== null) {
            return AccountBag::fromAccountRow($picked);
        }
        return AccountBag::legacy($provider);
    }

    /** @return array<string, string> */
    public static function maskCredentials(array $creds): array
    {
        $out = [];
        foreach ($creds as $k => $v) {
            $lk = strtolower((string) $k);
            if (str_contains($lk, 'token') || str_contains($lk, 'password') || str_contains($lk, 'secret')
                || str_contains($lk, 'private_key') || str_contains($lk, 'key_json')
            ) {
                $s = (string) $v;
                $out[$k] = $s === '' ? '' : (mb_substr($s, 0, 4) . '…' . mb_substr($s, -4));
            } else {
                $out[$k] = (string) $v;
            }
        }
        return $out;
    }

    /** @param array<string, string> $credentials */
    private function assertCredentials(string $provider, array $credentials, bool $requireAll): void
    {
        if ($provider === 'timeweb') {
            $t = $credentials['TIMEWEB_API_TOKEN'] ?? '';
            if ($requireAll && $t === '') {
                throw new \InvalidArgumentException('Нужен TIMEWEB_API_TOKEN');
            }
            return;
        }
        if ($provider === 'yandex') {
            $json = $credentials['YANDEX_SA_KEY_JSON'] ?? '';
            $sa = $credentials['YANDEX_SA_ID'] ?? '';
            $kid = $credentials['YANDEX_SA_KEY_ID'] ?? '';
            $pk = $credentials['YANDEX_SA_PRIVATE_KEY'] ?? '';
            if ($requireAll && $json === '' && ($sa === '' || $kid === '' || $pk === '')) {
                throw new \InvalidArgumentException('Нужен YANDEX_SA_KEY_JSON (ключ сервисного аккаунта)');
            }
            return;
        }
        $user = $credentials['SELECTEL_USERNAME'] ?? '';
        $pass = $credentials['SELECTEL_PASSWORD'] ?? '';
        if ($requireAll && ($user === '' || $pass === '')) {
            throw new \InvalidArgumentException('Нужны SELECTEL_USERNAME и SELECTEL_PASSWORD');
        }
    }
}
