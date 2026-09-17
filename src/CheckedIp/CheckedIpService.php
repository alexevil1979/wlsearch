<?php

declare(strict_types=1);

namespace Wlsearch\CheckedIp;

use PDO;
use Wlsearch\FavoriteSubnet\FavoriteSubnetService;
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
        if ($hit !== null) {
            return (string) $hit['reason'];
        }
        return $this->failedBsSame24Reason($ipv4);
    }

    /**
     * Если в той же /24 уже был FAIL_BS — сразу не ок.
     * Исключение: IP из избранных подсетей — всегда полная проверка заново.
     */
    public function failedBsSame24Reason(string $ipv4): ?string
    {
        if (!filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }

        try {
            if ((new FavoriteSubnetService($this->pdo))->matchIp($ipv4) !== null) {
                return null;
            }
        } catch (\Throwable) {
            // таблица favorites может отсутствовать
        }

        $parts = explode('.', $ipv4);
        if (count($parts) !== 4) {
            return null;
        }
        $net24 = $parts[0] . '.' . $parts[1] . '.' . $parts[2];
        $like = $net24 . '.%';

        $stmt = $this->pdo->prepare(
            "SELECT ipv4, detail FROM checked_ips
             WHERE ipv4 LIKE ? AND ipv4 != ? AND verdict = 'fail_bs'
             ORDER BY checked_at DESC LIMIT 1"
        );
        $stmt->execute([$like, $ipv4]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && !empty($row['ipv4'])) {
            return 'fail_bs_subnet /24 ' . $net24 . ' (как ' . $row['ipv4'] . ')';
        }

        // запасной поиск по runs (если checked_ips ещё не успел)
        $stmt2 = $this->pdo->prepare(
            "SELECT ipv4 FROM runs
             WHERE ipv4 LIKE ? AND ipv4 != ?
               AND (state = 'FAIL_BS' OR verdict = 'FAIL_BS')
             ORDER BY id DESC LIMIT 1"
        );
        $stmt2->execute([$like, $ipv4]);
        $prev = $stmt2->fetchColumn();
        if (is_string($prev) && $prev !== '') {
            return 'fail_bs_subnet /24 ' . $net24 . ' (как ' . $prev . ')';
        }

        return null;
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

    /**
     * Снять пометки fail_* / pass по префиксу (напр. 84.201.) — IP снова пойдут в полную проверку.
     * @return int сколько строк удалено
     */
    public function deleteByPrefix(string $prefix): int
    {
        $prefix = trim($prefix);
        if ($prefix === '' || !preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/', $prefix)) {
            throw new \InvalidArgumentException('Некорректный префикс IP (пример: 84.201.)');
        }
        if (!str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }
        $stmt = $this->pdo->prepare('DELETE FROM checked_ips WHERE ipv4 LIKE ?');
        $stmt->execute([$prefix . '%']);
        return $stmt->rowCount();
    }

    /**
     * Удалить все fail_bs (и опционально другие fail_*) по префиксу.
     * @return int
     */
    public function clearFailByPrefix(string $prefix): int
    {
        $prefix = trim($prefix);
        if ($prefix === '' || !preg_match('/^\d{1,3}(\.\d{1,3}){0,3}\.?$/', $prefix)) {
            throw new \InvalidArgumentException('Некорректный префикс IP (пример: 84.201.)');
        }
        if (!str_ends_with($prefix, '.')) {
            $prefix .= '.';
        }
        $stmt = $this->pdo->prepare(
            "DELETE FROM checked_ips WHERE ipv4 LIKE ? AND verdict IN ('fail_bs','fail_control','fail_seen','error')"
        );
        $stmt->execute([$prefix . '%']);
        return $stmt->rowCount();
    }
}
