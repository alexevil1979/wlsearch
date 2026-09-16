<?php

declare(strict_types=1);

namespace Wlsearch\Http\Controllers;

use PDO;
use Wlsearch\Auth\AuthService;
use Wlsearch\Support\Csrf;
use Wlsearch\Support\Database;
use Wlsearch\Support\FileLog;
use Wlsearch\Support\View;

final class AuditController
{
    public function index(): void
    {
        if (!AuthService::check()) {
            header('Location: /login');
            exit;
        }

        $rows = [];
        $pdo = Database::tryPdo();
        if ($pdo) {
            $rows = $pdo->query(
                'SELECT * FROM audit_log ORDER BY id DESC LIMIT 200'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $channels = FileLog::channels();
        $preferred = ['probe', 'worker', 'yandex', 'timeweb'];
        foreach (array_reverse($preferred) as $p) {
            if (in_array($p, $channels, true)) {
                array_unshift($channels, $p);
            }
        }
        $channels = array_values(array_unique($channels));
        if ($channels === []) {
            $channels = ['probe', 'worker', 'yandex'];
        }

        $channel = strtolower(trim((string) ($_GET['file'] ?? 'probe')));
        if (!preg_match('/^[a-z0-9_\-]+$/i', $channel)) {
            $channel = 'probe';
        }
        $filter = trim((string) ($_GET['q'] ?? ''));
        $lines = max(50, min(1000, (int) ($_GET['lines'] ?? 300)));
        $raw = FileLog::tail($channel, $lines, $filter !== '' ? $filter : null);

        View::render('logs/index', [
            'title' => 'Логи',
            'user' => AuthService::user(),
            'rows' => $rows,
            'channels' => $channels,
            'channel' => $channel,
            'filter' => $filter,
            'lines' => $lines,
            'rawLog' => $raw,
            'csrf' => Csrf::field(),
            'nav' => 'logs',
        ]);
    }
}
