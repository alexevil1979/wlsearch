<?php

declare(strict_types=1);

namespace Wlsearch\Blacklist;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

final class BlacklistService
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
            'SELECT * FROM prefix_blacklist ORDER BY kind, value'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function add(string $kind, string $value, ?string $reason, string $actor): void
    {
        $kind = strtolower($kind);
        if (!in_array($kind, ['asn', 'prefix'], true)) {
            throw new \InvalidArgumentException('kind must be asn|prefix');
        }
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('value required');
        }
        if ($kind === 'asn') {
            $value = preg_replace('/^AS/i', '', $value) ?? $value;
            if (!ctype_digit($value)) {
                throw new \InvalidArgumentException('ASN must be numeric');
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO prefix_blacklist (kind, value, reason, created_at) VALUES (?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)'
        );
        // MySQL 5.7 supports ON DUPLICATE KEY UPDATE
        try {
            $stmt->execute([$kind, $value, $reason]);
        } catch (\Throwable) {
            // unique conflict without upsert support edge-case
            $upd = $this->pdo->prepare(
                'UPDATE prefix_blacklist SET reason = ? WHERE kind = ? AND value = ?'
            );
            $upd->execute([$reason, $kind, $value]);
        }
        Audit::log($actor, 'blacklist.add', 'blacklist', $kind . ':' . $value, ['reason' => $reason]);
    }

    public function delete(int $id, string $actor): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM prefix_blacklist WHERE id = ?');
        $stmt->execute([$id]);
        Audit::log($actor, 'blacklist.delete', 'blacklist', (string) $id);
    }

    /**
     * @return array{blocked:bool, reason:?string}
     */
    public function checkIp(?string $ipv4, ?int $asn): array
    {
        if ($asn !== null) {
            $stmt = $this->pdo->prepare("SELECT reason FROM prefix_blacklist WHERE kind = 'asn' AND value = ? LIMIT 1");
            $stmt->execute([(string) $asn]);
            $reason = $stmt->fetchColumn();
            if ($reason !== false) {
                return ['blocked' => true, 'reason' => 'ASN ' . $asn . ': ' . (string) $reason];
            }
        }

        if ($ipv4 === null || $ipv4 === '') {
            return ['blocked' => false, 'reason' => null];
        }

        $stmt = $this->pdo->query("SELECT value, reason FROM prefix_blacklist WHERE kind = 'prefix'");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rows as $row) {
            if ($this->ipInCidr($ipv4, (string) $row['value'])) {
                return ['blocked' => true, 'reason' => 'prefix ' . $row['value'] . ': ' . (string) $row['reason']];
            }
        }

        return ['blocked' => false, 'reason' => null];
    }

    private function ipInCidr(string $ip, string $cidr): bool
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
