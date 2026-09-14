<?php

declare(strict_types=1);

namespace Wlsearch\Inventory;

use PDO;
use Wlsearch\Support\Audit;
use Wlsearch\Support\Database;

final class InventoryService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    public function upsertPass(
        int $runId,
        string $ipv4,
        string $provider,
        ?int $asn,
        ?string $asnOrg,
        string $operator,
        ?string $notes = null,
    ): void {
        $existing = $this->pdo->prepare('SELECT id, operators FROM inventory WHERE ipv4 = ?');
        $existing->execute([$ipv4]);
        $row = $existing->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $ops = array_filter(array_map('trim', explode(',', (string) $row['operators'])));
            if (!in_array($operator, $ops, true)) {
                $ops[] = $operator;
            }
            $stmt = $this->pdo->prepare(
                "UPDATE inventory SET run_id = ?, provider = ?, asn = ?, asn_org = ?, operators = ?,
                        status = 'active', retired_at = NULL, notes = COALESCE(?, notes), found_at = NOW()
                 WHERE id = ?"
            );
            $stmt->execute([
                $runId,
                $provider,
                $asn,
                $asnOrg,
                implode(',', $ops),
                $notes,
                $row['id'],
            ]);
            return;
        }

        $stmt = $this->pdo->prepare(
            "INSERT INTO inventory (run_id, ipv4, provider, asn, asn_org, operators, notes, status, found_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())"
        );
        $stmt->execute([$runId, $ipv4, $provider, $asn, $asnOrg, $operator, $notes]);
    }

    /** @return list<array<string, mixed>> */
    public function listActive(int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        return $this->pdo->query(
            "SELECT * FROM inventory ORDER BY FIELD(status,'active','retired'), found_at DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function retire(int $id, string $actor, ?string $notes = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE inventory SET status = 'retired', retired_at = NOW(),
                    notes = TRIM(CONCAT(COALESCE(notes,''), ' ', COALESCE(?, '')))
             WHERE id = ?"
        );
        $stmt->execute([$notes, $id]);
        Audit::log($actor, 'inventory.retire', 'inventory', (string) $id);
    }
}
