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

        $pdo = Database::tryPdo();
        if ($pdo !== null) {
            $stats['db_ok'] = true;
            try {
                $stats['active_runs'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM runs WHERE state NOT IN ('PASS','FAIL_BS','FAIL_CONTROL','ERROR','DESTROYED','KEEP')"
                )->fetchColumn();
                $stats['queued_tasks'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM tasks WHERE status IN ('pending','assigned')"
                )->fetchColumn();
                $stats['online_agents'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM devices WHERE revoked_at IS NULL AND last_seen_at >= (NOW() - INTERVAL 2 MINUTE)"
                )->fetchColumn();
                $stats['creates_today'] = (int) $pdo->query(
                    "SELECT COUNT(*) FROM runs WHERE DATE(created_at) = CURDATE()"
                )->fetchColumn();
            } catch (\Throwable) {
                // Tables may be empty / not migrated yet — keep zeros.
            }
        }

        View::render('dashboard/index', [
            'title' => 'Dashboard',
            'user' => AuthService::user(),
            'stats' => $stats,
            'flash' => Flash::pull(),
            'csrf' => Csrf::field(),
            'nav' => 'dashboard',
        ]);
    }
}
