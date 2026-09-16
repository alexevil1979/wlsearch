<?php

declare(strict_types=1);

namespace Wlsearch\FavoriteSubnet;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

final class FavoriteSubnetService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->pdo->query(
            'SELECT * FROM favorite_subnets ORDER BY cidr'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function add(string $cidr, ?string $note, string $actor): void
    {
        $cidr = $this->normalizeCidr($cidr);
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO favorite_subnets (cidr, note, created_at) VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE note = VALUES(note)'
        );
        try {
            $stmt->execute([$cidr, $note]);
        } catch (\Throwable) {
            $upd = $this->pdo->prepare(
                'UPDATE favorite_subnets SET note = ? WHERE cidr = ?'
            );
            $upd->execute([$note, $cidr]);
        }
        Audit::log($actor, 'favorite_subnet.add', 'favorite_subnet', $cidr, ['note' => $note]);
    }

    public function delete(int $id, string $actor): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM favorite_subnets WHERE id = ?');
        $stmt->execute([$id]);
        Audit::log($actor, 'favorite_subnet.delete', 'favorite_subnet', (string) $id);
    }

    /**
     * @return array{cidr:string, note:?string}|null
     */
    public function matchIp(?string $ipv4): ?array
    {
        if ($ipv4 === null || $ipv4 === '' || !filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        $rows = $this->pdo->query('SELECT cidr, note FROM favorite_subnets')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $cidr = (string) ($row['cidr'] ?? '');
            if ($cidr !== '' && self::ipInCidr($ipv4, $cidr)) {
                $note = $row['note'] ?? null;
                return [
                    'cidr' => $cidr,
                    'note' => $note !== null && $note !== '' ? (string) $note : null,
                ];
            }
        }

        return null;
    }

    public function normalizeCidr(string $cidr): string
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new \InvalidArgumentException('CIDR обязателен');
        }
        if (!str_contains($cidr, '/')) {
            if (!filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new \InvalidArgumentException('Нужен IPv4 или CIDR (напр. 51.250.0.0/16)');
            }
            return $cidr . '/32';
        }
        [$net, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;
        if (!filter_var($net, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || $mask < 0 || $mask > 32) {
            throw new \InvalidArgumentException('Некорректный CIDR');
        }
        return $net . '/' . $mask;
    }

    public static function ipInCidr(string $ip, string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return $ip === $cidr;
        }
        [$subnet, $mask] = explode('/', $cidr, 2);
        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }
        $ipLong = ip2long($ip);
        $subLong = ip2long($subnet);
        if ($ipLong === false || $subLong === false) {
            return false;
        }
        $maskLong = $mask === 0 ? 0 : (~((1 << (32 - $mask)) - 1) & 0xFFFFFFFF);
        return ($ipLong & $maskLong) === ($subLong & $maskLong);
    }
}
