<?php

declare(strict_types=1);

namespace Wlsearch\ProtectedIp;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

/**
 * Публичные IP, которые нельзя отпускать/удалять действиями wlsearch (Yandex static reserve).
 */
final class ProtectedIpService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    public function protect(string $ipv4, string $source = 'manual', ?int $runId = null, ?string $note = null): void
    {
        $ipv4 = trim($ipv4);
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO protected_ips (ipv4, source, run_id, note, created_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                source = VALUES(source),
                run_id = COALESCE(VALUES(run_id), run_id),
                note = COALESCE(VALUES(note), note)'
        );
        try {
            $stmt->execute([
                $ipv4,
                mb_substr($source, 0, 64),
                $runId,
                $note !== null ? mb_substr($note, 0, 255) : null,
            ]);
        } catch (\Throwable) {
            // table may not exist yet before migrate
        }
        try {
            Audit::log('system', 'protected_ip.add', 'protected_ip', $ipv4, [
                'source' => $source,
                'run_id' => $runId,
            ]);
        } catch (\Throwable) {
        }
    }

    public function isProtected(string $ipv4): bool
    {
        $ipv4 = trim($ipv4);
        if ($ipv4 === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT 1 FROM protected_ips WHERE ipv4 = ? LIMIT 1');
            $stmt->execute([$ipv4]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }
}
