<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Database;
use Wlsearch\Support\Env;
use Wlsearch\Support\Flash;
use Wlsearch\Support\View;

final class DashboardController
{
    public function index(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }

        $stats = [
            'active_runs' => 0,
            'queued_tasks' => 0,
            'online_agents' => 0,
            'creates_today' => 0,
            'max_creates' => Env::int('MAX_CREATES_PER_DAY', 20),
            'max_parallel' => Env::int('MAX_PARALLEL_VMS', 3),
            'db_ok' => false,
        ];

        $accountStats = [];

        $pdo = Database::tryPdo();
        if ($pdo !== null) {
            $stats['db_ok'] = true;
            try {
                $stats['active_runs'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM runs WHERE state NOT IN ('PASS','FAIL_BS','FAIL_CONTROL','ERROR','DESTROYED','KEEP','SKIPPED')"
                )->fetchColumn();
                $stats['queued_tasks'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM tasks WHERE status IN ('pending','assigned')"
                )->fetchColumn();
                $stats['online_agents'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM devices WHERE revoked_at IS NULL AND last_seen_at >= (NOW() - INTERVAL 2 MINUTE)"
                )->fetchColumn();
                $stats['creates_today'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM runs WHERE DATE(created_at) = CURDATE() AND ipv4 IS NOT NULL AND ipv4 != ''"
                )->fetchColumn();

                $accountStats = $pdo->query(
                    "SELECT a.id, a.name, a.provider, a.enabled,
                            COALESCE(SUM(r.ipv4 IS NOT NULL AND r.ipv4 != ''), 0) AS with_ip,
                            COALESCE(SUM(DATE(r.created_at) = CURDATE() AND r.ipv4 IS NOT NULL AND r.ipv4 != ''), 0) AS today,
                            COALESCE(SUM(r.state = 'PASS'), 0) AS pass,
                            COALESCE(SUM(r.state = 'KEEP'), 0) AS keep,
                            COALESCE(SUM(r.state = 'FAIL_BS'), 0) AS fail_bs,
                            COALESCE(SUM(r.state = 'FAIL_CONTROL'), 0) AS fail_ctrl,
                            COALESCE(SUM(r.state IN ('ERROR','DESTROYED')), 0) AS dead,
                            COALESCE(SUM(r.state IN ('ORDERING','PROVISIONING','BOOTSTRAPPING','CONTROL_CHECK','BS_CHECK','DESTROYING')), 0) AS live,
                            COALESCE(SUM(r.state = 'SKIPPED'), 0) AS skipped,
                            COALESCE(COUNT(r.id), 0) AS total
                     FROM provider_accounts a
                     LEFT JOIN runs r ON r.provider_account_id = a.id
                     GROUP BY a.id, a.name, a.provider, a.enabled
                     ORDER BY a.provider ASC, a.sort_order ASC, a.id ASC"
                )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable) {
                // Tables may be empty / not migrated yet — keep zeros.
            }
        }

        View::render('dashboard/index', [
            'title' => 'Dashboard',
            'user' => AuthService::user(),
            'stats' => $stats,
            'accountStats' => $accountStats,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'dashboard',
        ]);
    }
}
