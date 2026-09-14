<?php

declare(strict_types=1);

namespace Wlsearch\Device;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

final class DeviceService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * @return array{device: array<string,mixed>, token: string}
     */
    public function create(string $name, string $operator, string $actor = 'admin'): array
    {
        $operator = strtolower($operator);
        if (!in_array($operator, ['mts', 'beeline', 'megafon', 'other'], true)) {
            throw new \InvalidArgumentException('operator: mts|beeline|megafon|other');
        }
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('name required');
        }

        $token = 'wls_' . bin2hex(random_bytes(24));
        $hash = hash('sha256', $token);
        $prefix = substr($token, 0, 12);

        $stmt = $this->pdo->prepare(
            'INSERT INTO devices (name, operator, token_hash, token_prefix, created_at)
             VALUES (?, ?, ?, ?, NOW())'
        );
        $stmt->execute([$name, $operator, $hash, $prefix]);
        $id = (int) $this->pdo->lastInsertId();

        Audit::log($actor, 'device.create', 'device', (string) $id, [
            'name' => $name,
            'operator' => $operator,
            'token_prefix' => $prefix,
        ]);

        $device = $this->get($id);
        return ['device' => $device, 'token' => $token];
    }

    public function revoke(int $id, string $actor = 'admin'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL'
        );
        $stmt->execute([$id]);
        Audit::log($actor, 'device.revoke', 'device', (string) $id);
    }

    /** @return array<string, mixed>|null */
    public function get(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM devices WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT id, name, operator, token_prefix, last_seen_at, revoked_at, created_at
             FROM devices ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** @return array<string, mixed>|null */
    public function authenticateBearer(?string $header): ?array
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)/i', $header, $m)) {
            $token = $m[1];
        } else {
            $token = trim($header);
        }
        if ($token === '') {
            return null;
        }

        $hash = hash('sha256', $token);
        $stmt = $this->pdo->prepare(
            'SELECT * FROM devices WHERE token_hash = ? AND revoked_at IS NULL LIMIT 1'
        );
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function touch(int $deviceId, ?array $meta = null): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE devices SET last_seen_at = NOW(), meta_json = COALESCE(?, meta_json) WHERE id = ?'
        );
        $stmt->execute([
            $meta !== null ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            $deviceId,
        ]);
    }
}
