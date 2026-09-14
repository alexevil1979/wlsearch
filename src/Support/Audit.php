<?php

declare(strict_types=1);

namespace Wlsearch\Support;

use PDO;

final class Audit
{
    public static function log(
        string $actor,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $details = null,
        ?string $ip = null,
    ): void {
        $pdo = Database::tryPdo();
        if ($pdo === null) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO audit_log (actor, action, entity_type, entity_id, details_json, ip, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                mb_substr($actor, 0, 64),
                mb_substr($action, 0, 64),
                $entityType,
                $entityId,
                $details !== null ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                $ip ?? (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null),
            ]);
        } catch (\Throwable) {
            // never break main flow
        }
    }
}
