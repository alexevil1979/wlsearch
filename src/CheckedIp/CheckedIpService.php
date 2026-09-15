<?php

declare(strict_types=1);

namespace Wlsearch\CheckedIp;

use PDO;
use Wlsearch\Support\Database;

/**
 * Already-probed IPv4 cache.
 * Known-bad → destroy immediately on next provision; known-pass → skip duplicate VPS.
 */
final class CheckedIpService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
    }

    /**
     * @return array{reject:bool, reason:?string, verdict:?string}|null null = never seen
     */
    public function lookup(string $ipv4): ?array
    {
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT verdict, detail FROM checked_ips WHERE ipv4 = ? LIMIT 1');
        $stmt->execute([$ipv4]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $verdict = strtolower((string) $row['verdict']);
        // Не годный (fail*) — destroy сразу. Уже PASS — тоже destroy (IP уже в inventory, VPS не нужен).
        $reason = $verdict === 'pass'
            ? ('already_pass' . ($row['detail'] ? ': ' . mb_substr((string) $row['detail'], 0, 80) : ''))
            : ('known_' . $verdict . ($row['detail'] ? ': ' . mb_substr((string) $row['detail'], 0, 120) : ''));

        return [
            'reject' => true,
            'reason' => $reason,
            'verdict' => $verdict,
        ];
    }

    public function shouldDestroyImmediately(string $ipv4): ?string
    {
        $hit = $this->lookup($ipv4);
        if ($hit === null) {
            return null;
        }
        return (string) $hit['reason'];
    }

    public function record(
        string $ipv4,
        string $verdict,
        ?string $provider = null,
        ?int $asn = null,
        ?int $runId = null,
        ?string $detail = null,
    ): void {
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return;
        }
        $verdict = strtolower($verdict);
        if (!in_array($verdict, ['pass', 'fail_bs', 'fail_control', 'fail_seen', 'error'], true)) {
            $verdict = 'error';
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO checked_ips (ipv4, verdict, provider, asn, run_id, detail, checked_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                verdict = VALUES(verdict),
                provider = COALESCE(VALUES(provider), provider),
                asn = COALESCE(VALUES(asn), asn),
                run_id = COALESCE(VALUES(run_id), run_id),
                detail = VALUES(detail),
                updated_at = NOW()"
        );
        $stmt->execute([
            $ipv4,
            $verdict,
            $provider,
            $asn,
            $runId,
            $detail !== null ? mb_substr($detail, 0, 512) : null,
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listRecent(int $limit = 200): array
    {
        $limit = max(1, min(1000, $limit));
        return $this->pdo->query(
            "SELECT * FROM checked_ips ORDER BY updated_at DESC LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function delete(string $ipv4): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM checked_ips WHERE ipv4 = ?');
        $stmt->execute([$ipv4]);
    }
}
